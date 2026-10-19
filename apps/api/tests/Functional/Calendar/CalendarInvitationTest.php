<?php

declare(strict_types=1);

namespace App\Tests\Functional\Calendar;

use App\Entity\Calendar;
use App\Tests\Functional\AuthenticatesUsers;
use App\Tests\Functional\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CalendarInvitationTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use CalendarTestHelpers;

    /** @var array<string, string> */
    private array $kim;

    /** @var array<string, string> */
    private array $worker;

    private string $accountId;

    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'mail:send', 'calendar:read', 'calendar:write', 'settings:write']);

        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => 'kim@company.example',
            'displayName' => 'Kim Visser',
            'username' => 'kim@company.example',
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->kim);
        $this->accountId = $this->responseString('id');

        $this->worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/folders', ['workerId' => 'w1', 'folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => 'Junk', 'displayName' => 'Junk', 'role' => 'spam', 'uidValidity' => 1],
        ]], $this->worker);
        self::assertResponseIsSuccessful();
    }

    public function testAnInvitationLandsInTheCalendarUnanswered(): void
    {
        $messageId = $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'));

        $occurrences = $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00');
        self::assertCount(1, $occurrences);
        self::assertSame('Budget review', $occurrences[0]['summary']);
        self::assertSame(['dateTime' => '2026-10-21T12:00:00+00:00'], $occurrences[0]['start'], 'The Windows zone name is read as Central European time.');
        self::assertSame('needs-action', $occurrences[0]['selfResponse']);

        $invitation = $this->getJson('/api/v1/messages/'.$messageId.'/invitation', $this->kim);
        self::assertSame('REQUEST', $invitation['method']);
        self::assertSame('new', $invitation['kind']);
        self::assertSame(['address' => 'anna@finance.example', 'name' => 'Anna de Vries'], $invitation['organizer']);
        self::assertSame($occurrences[0]['eventId'], $invitation['eventId']);
        self::assertTrue($invitation['canRespond']);

        $delta = $this->getJson('/api/v1/sync/changes?since=0', $this->kim);
        self::assertIsArray($delta['messages']);
        self::assertSame(['REQUEST'], array_column($delta['messages'], 'calendarMethod'));
        self::assertIsArray($delta['calendarEvents']);
        self::assertSame([$messageId], array_column($delta['calendarEvents'], 'messageId'));
    }

    public function testAcceptingRepliesToTheOrganizerThroughTheOutbox(): void
    {
        $messageId = $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'));
        $eventId = $this->getJson('/api/v1/messages/'.$messageId.'/invitation', $this->kim)['eventId'];
        self::assertIsString($eventId);

        $this->client->jsonRequest('POST', '/api/v1/calendar-events/'.$eventId.'/response', ['response' => 'accepted', 'comment' => 'See you there'], $this->kim);
        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $this->decodedResponse()['selfResponse']);

        $outgoing = $this->outgoing();
        self::assertCount(1, $outgoing);
        self::assertSame('Accepted: Budget review', $outgoing[0]->getSubject());
        self::assertSame(['anna@finance.example'], $outgoing[0]->getEnvelopeRecipients());
        $raw = quoted_printable_decode($this->rawOutgoing($outgoing[0]));
        self::assertStringContainsString('text/calendar; charset=utf-8; method=REPLY', $raw);
        self::assertStringContainsString('METHOD:REPLY', $raw);
        self::assertMatchesRegularExpression('/ATTENDEE;[^\r\n]*PARTSTAT=ACCEPTED[^\r\n]*:mailto:kim@company\.example/', $raw);
        self::assertDoesNotMatchRegularExpression('/^ATTENDEE[^\r\n]*mailto:anna@finance\.example/m', $raw, 'A reply only carries the replying attendee.');
        self::assertStringContainsString('COMMENT:See you there', $raw);
        self::assertStringContainsString('In-Reply-To: <invite-1@finance.example>', $raw);
    }

    public function testUpdatesMoveTheEventAndOlderOnesAreIgnored(): void
    {
        $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'));
        $this->receive('Updated: Budget review', self::invitation('REQUEST', 2, '20261021T160000'));
        $late = $this->receive('Invitation: Budget review', self::invitation('REQUEST', 1, '20261021T150000'));

        $occurrences = $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00');
        self::assertCount(1, $occurrences);
        self::assertSame(['dateTime' => '2026-10-21T14:00:00+00:00'], $occurrences[0]['start']);
        self::assertSame('outdated', $this->getJson('/api/v1/messages/'.$late.'/invitation', $this->kim)['kind']);
    }

    public function testCancellationsCancelTheEvent(): void
    {
        $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'));
        $cancel = $this->receive('Cancelled: Budget review', self::invitation('CANCEL', 1, '20261021T140000'));

        $occurrences = $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00');
        self::assertSame('cancelled', $occurrences[0]['status']);
        self::assertSame('cancelled', $this->getJson('/api/v1/messages/'.$cancel.'/invitation', $this->kim)['kind']);
    }

    public function testIgnoresInvitationsAddressedToOthers(): void
    {
        $this->receive('Invitation: Budget review', str_replace('mailto:kim@company.example', 'mailto:pieter@company.example', self::invitation('REQUEST', 0, '20261021T140000')));

        self::assertSame([], $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00'));
    }

    public function testOrganizingInvitesAttendeesAndTheirRepliesAreRecorded(): void
    {
        $calendarId = $this->localCalendarId($this->kim);
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($calendarId, [
            'summary' => 'Planning',
            'attendees' => [['address' => 'anna@finance.example', 'name' => 'Anna de Vries']],
            'organizerMailAccountId' => $this->accountId,
        ]), $this->kim);
        self::assertResponseStatusCodeSame(201);
        $event = $this->decodedResponse();
        self::assertSame(['address' => 'kim@company.example', 'name' => 'Kim Visser'], $event['organizer']);
        self::assertNull($event['selfResponse']);

        $outgoing = $this->outgoing();
        self::assertCount(1, $outgoing);
        self::assertSame('Invitation: Planning', $outgoing[0]->getSubject());
        $raw = quoted_printable_decode($this->rawOutgoing($outgoing[0]));
        self::assertStringContainsString('method=REQUEST', $raw);
        self::assertStringContainsString('BEGIN:VTIMEZONE', $raw);
        self::assertStringContainsString('TZID:Europe/Amsterdam', $raw);

        $uid = self::string($event['uid']);
        $eventId = self::string($event['id']);
        $this->receive('Tentative: Planning', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Example//EN', 'METHOD:REPLY', 'BEGIN:VEVENT',
            'UID:'.$uid, 'SEQUENCE:0', 'DTSTAMP:20261019T090000Z', 'DTSTART;TZID=Europe/Amsterdam:20261019T090000',
            'ORGANIZER:mailto:kim@company.example', 'ATTENDEE;PARTSTAT=TENTATIVE:mailto:anna@finance.example',
            'END:VEVENT', 'END:VCALENDAR', '',
        ]));

        $attendees = $this->getJson('/api/v1/calendar-events/'.$eventId, $this->kim)['attendees'];
        self::assertIsArray($attendees);
        self::assertSame(['tentative'], array_column($attendees, 'partstat'));

        $this->client->request('DELETE', '/api/v1/calendar-events/'.$eventId, server: $this->kim);
        self::assertResponseStatusCodeSame(204);
        $outgoing = $this->outgoing();
        self::assertCount(2, $outgoing);
        self::assertSame('Cancelled: Planning', $outgoing[1]->getSubject());
        self::assertStringContainsString('Content-Type: text/calendar; charset=utf-8; method=CANCEL', $this->rawOutgoing($outgoing[1]));
    }

    public function testANewInvitationCountsOnlyFromItsOrganizerOrWhoSendsForThem(): void
    {
        $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'), 'mallory@attacker.test');
        self::assertSame([], $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00'));

        $delegated = str_replace('ORGANIZER;CN=Anna de Vries:', 'ORGANIZER;CN=Anna de Vries;SENT-BY="mailto:assistant@finance.example":', self::invitation('REQUEST', 0, '20261021T140000'));
        $this->receive('Invitation: Budget review', $delegated, 'assistant@finance.example');
        self::assertCount(1, $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00'));
    }

    public function testOnlyTheOrganizerCanUpdateOrCancelAnInvitation(): void
    {
        $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'));

        $this->receive('Cancelled: Budget review', self::invitation('CANCEL', 1, '20261021T140000'), 'mallory@attacker.test');
        $this->receive('Updated: Budget review', self::invitation('REQUEST', 1, '20261021T180000'), 'mallory@attacker.test');
        $takeover = str_replace('ORGANIZER;CN=Anna de Vries:mailto:anna@finance.example', 'ORGANIZER:mailto:mallory@attacker.test', self::invitation('REQUEST', 2, '20261021T180000'));
        $this->receive('Updated: Budget review', $takeover, 'mallory@attacker.test');
        // Even the organizer cannot hand the event to someone else.
        $this->receive('Updated: Budget review', str_replace('mallory@attacker.test', 'pieter@finance.example', $takeover));

        $occurrences = $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00');
        self::assertCount(1, $occurrences);
        self::assertSame('confirmed', $occurrences[0]['status']);
        self::assertSame(['dateTime' => '2026-10-21T12:00:00+00:00'], $occurrences[0]['start']);
        $event = $this->getJson('/api/v1/calendar-events/'.self::string($occurrences[0]['eventId']), $this->kim);
        self::assertSame(['address' => 'anna@finance.example', 'name' => 'Anna de Vries'], $event['organizer']);
    }

    public function testAForgedHighSequenceCannotLockOutTheOrganizer(): void
    {
        $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'));

        $this->receive('Cancelled: Budget review', self::invitation('CANCEL', 999999, '20261021T140000'));
        $this->receive('Updated: Budget review', self::invitation('REQUEST', 1, '20261021T160000'));

        $occurrences = $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00');
        self::assertSame('confirmed', $occurrences[0]['status']);
        self::assertSame(['dateTime' => '2026-10-21T14:00:00+00:00'], $occurrences[0]['start']);
    }

    public function testCalendarMailNeverChangesAnEventTheUserOrganizes(): void
    {
        $calendarId = $this->localCalendarId($this->kim);
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($calendarId, [
            'summary' => 'Planning',
            'attendees' => [['address' => 'anna@finance.example', 'name' => 'Anna de Vries']],
            'organizerMailAccountId' => $this->accountId,
        ]), $this->kim);
        self::assertResponseStatusCodeSame(201);
        $uid = self::string($this->decodedResponse()['uid']);
        $eventId = self::string($this->decodedResponse()['id']);
        $forged = static fn (string $method, string $organizer): string => str_replace(
            ['UID:budget-review@finance.example', 'ORGANIZER;CN=Anna de Vries:mailto:anna@finance.example'],
            ['UID:'.$uid, 'ORGANIZER:mailto:'.$organizer],
            self::invitation($method, 5, '20261019T090000'),
        );

        $this->receive('Cancelled: Planning', $forged('CANCEL', 'kim@company.example'), 'kim@company.example');
        $this->receive('Cancelled: Planning', $forged('CANCEL', 'anna@finance.example'));
        $this->receive('Updated: Planning', $forged('REQUEST', 'anna@finance.example'));

        $event = $this->getJson('/api/v1/calendar-events/'.$eventId, $this->kim);
        self::assertSame('confirmed', $event['status']);
        self::assertSame('Planning', $event['summary']);
        self::assertSame(['address' => 'kim@company.example', 'name' => 'Kim Visser'], $event['organizer']);
    }

    public function testAddingGuestsToAnExistingEventInvitesThem(): void
    {
        $calendarId = $this->localCalendarId($this->kim);
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($calendarId, ['summary' => 'Planning']), $this->kim);
        self::assertResponseStatusCodeSame(201);
        $eventId = self::string($this->decodedResponse()['id']);
        self::assertSame([], $this->outgoing());

        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$eventId, self::event($calendarId, [
            'summary' => 'Planning',
            'attendees' => [['address' => 'anna@finance.example', 'name' => 'Anna de Vries']],
            'organizerMailAccountId' => $this->accountId,
        ]), $this->kim);
        self::assertResponseIsSuccessful();
        self::assertSame(['address' => 'kim@company.example', 'name' => 'Kim Visser'], $this->decodedResponse()['organizer']);

        $outgoing = $this->outgoing();
        self::assertCount(1, $outgoing);
        self::assertSame('Invitation: Planning', $outgoing[0]->getSubject());
        self::assertSame(['anna@finance.example'], $outgoing[0]->getEnvelopeRecipients());

        // A guest added later is invited as well, by the same organizer.
        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$eventId, self::event($calendarId, [
            'summary' => 'Planning',
            'attendees' => [['address' => 'anna@finance.example', 'name' => 'Anna de Vries'], ['address' => 'pieter@finance.example', 'name' => null]],
        ]), $this->kim);
        self::assertResponseIsSuccessful();
        $outgoing = $this->outgoing();
        self::assertContains(['pieter@finance.example'], array_map(static fn ($message): array => $message->getEnvelopeRecipients(), $outgoing));
    }

    public function testAReplyWithoutTimesShowsTheEventAsTheCalendarHasIt(): void
    {
        $calendarId = $this->localCalendarId($this->kim);
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($calendarId, [
            'summary' => 'Planning',
            'attendees' => [['address' => 'anna@finance.example', 'name' => 'Anna de Vries']],
            'organizerMailAccountId' => $this->accountId,
        ]), $this->kim);
        $uid = self::string($this->decodedResponse()['uid']);

        $messageId = $this->receive('Accepted: Planning', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Example//EN', 'METHOD:REPLY', 'BEGIN:VEVENT',
            'UID:'.$uid, 'SEQUENCE:0', 'DTSTAMP:20261019T090000Z',
            'ORGANIZER:mailto:kim@company.example', 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:anna@finance.example',
            'END:VEVENT', 'END:VCALENDAR', '',
        ]));

        $invitation = $this->getJson('/api/v1/messages/'.$messageId.'/invitation', $this->kim);
        self::assertSame('reply', $invitation['kind']);
        self::assertSame('Planning', $invitation['summary']);
        self::assertSame(['dateTime' => '2026-10-19T09:00:00', 'timeZone' => 'Europe/Amsterdam'], $invitation['start']);
    }

    public function testAReplyCountsOnlyFromAnAttendeeItSpeaksFor(): void
    {
        $calendarId = $this->localCalendarId($this->kim);
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($calendarId, [
            'summary' => 'Planning',
            'attendees' => [['address' => 'anna@finance.example', 'name' => 'Anna de Vries']],
            'organizerMailAccountId' => $this->accountId,
        ]), $this->kim);
        $uid = self::string($this->decodedResponse()['uid']);
        $eventId = self::string($this->decodedResponse()['id']);
        $reply = static fn (string $attendee): string => implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Example//EN', 'METHOD:REPLY', 'BEGIN:VEVENT',
            'UID:'.$uid, 'SEQUENCE:0', 'DTSTAMP:20261019T090000Z', 'DTSTART;TZID=Europe/Amsterdam:20261019T090000',
            'ORGANIZER:mailto:kim@company.example', 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:'.$attendee,
            'END:VEVENT', 'END:VCALENDAR', '',
        ]);

        // An outsider cannot join by answering, nor answer for an attendee.
        $this->receive('Accepted: Planning', $reply('mallory@attacker.test'), 'mallory@attacker.test');
        $this->receive('Accepted: Planning', $reply('anna@finance.example'), 'mallory@attacker.test');

        $attendees = $this->getJson('/api/v1/calendar-events/'.$eventId, $this->kim)['attendees'];
        self::assertIsArray($attendees);
        self::assertSame(['anna@finance.example'], array_column($attendees, 'address'));
        self::assertSame(['needs-action'], array_column($attendees, 'partstat'));
    }

    public function testCalendarMailInSpamOrFromBeforeTheAccountWasConnectedChangesNothing(): void
    {
        $spam = $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'), folder: 'Junk');
        $this->client->request('GET', '/api/v1/messages/'.$spam.'/invitation', server: $this->kim);
        self::assertResponseStatusCodeSame(404);

        $old = $this->receive('Invitation: Old review', self::invitation('REQUEST', 0, '20261021T140000'), receivedAt: '2020-01-06T08:00:00+00:00');
        self::assertSame([], $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00'));
        $invitation = $this->getJson('/api/v1/messages/'.$old.'/invitation', $this->kim);
        self::assertSame('Budget review', $invitation['summary'], 'The message still shows what it carries.');
        self::assertNull($invitation['eventId']);
        self::assertFalse($invitation['canRespond']);
        self::assertTrue($invitation['canAdd']);
    }

    public function testAnInvitationTheCalendarDidNotTakeCanBeAddedWithTheSameChecks(): void
    {
        $old = $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'), receivedAt: '2020-01-06T08:00:00+00:00');
        $forged = $this->receive('Invitation: Budget review again', str_replace('UID:budget-review@finance.example', 'UID:other@finance.example', self::invitation('REQUEST', 0, '20261022T140000')), 'mallory@attacker.test', receivedAt: '2020-01-06T08:00:00+00:00');

        $this->client->request('POST', '/api/v1/messages/'.$old.'/invitation/add', server: $this->kim);
        self::assertResponseIsSuccessful();
        $added = $this->decodedResponse();
        self::assertIsString($added['eventId']);
        self::assertFalse($added['canAdd']);
        self::assertTrue($added['canRespond']);
        self::assertCount(1, $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00'));

        $this->client->request('POST', '/api/v1/messages/'.$forged.'/invitation/add', server: $this->kim);
        self::assertResponseStatusCodeSame(422, 'Not from the organizer it names.');
        self::assertSame([], $this->occurrences($this->kim, '2026-10-22T00:00:00+00:00', '2026-10-23T00:00:00+00:00'));
    }

    public function testAnEventTooLongToCheckForConflictsStillShows(): void
    {
        $long = str_replace('DTEND;TZID=W. Europe Standard Time:20261021T150000', 'DTEND;TZID=W. Europe Standard Time:20280101T150000', self::invitation('REQUEST', 0, '20261021T140000'));
        $messageId = $this->receive('Invitation: Sabbatical', $long);

        $invitation = $this->getJson('/api/v1/messages/'.$messageId.'/invitation', $this->kim);

        self::assertSame('Budget review', $invitation['summary']);
        self::assertSame([], $invitation['conflicts']);
    }

    public function testAUserWithoutATressoCalendarGetsOneForAnInvitation(): void
    {
        // Users from before calendars existed have none.
        self::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM '.Calendar::class.' c')->execute();

        $this->receive('Invitation: Budget review', self::invitation('REQUEST', 0, '20261021T140000'));

        $occurrences = $this->occurrences($this->kim, '2026-10-21T00:00:00+00:00', '2026-10-22T00:00:00+00:00');
        self::assertCount(1, $occurrences);
        $calendars = $this->getJson('/api/v1/calendars', $this->kim);
        self::assertSame(['Tresso'], array_column($calendars, 'name'));
        self::assertSame([true], array_column($calendars, 'isDefault'));
    }

    private static function invitation(string $method, int $sequence, string $start): string
    {
        $end = substr($start, 0, 9).\sprintf('%02d', (int) substr($start, 9, 2) + 1).substr($start, 11);

        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'PRODID:-//Example//EN', 'VERSION:2.0', 'METHOD:'.$method, 'BEGIN:VEVENT',
            'UID:budget-review@finance.example', 'SEQUENCE:'.$sequence, 'DTSTAMP:20261019T080000Z',
            'DTSTART;TZID=W. Europe Standard Time:'.$start, 'DTEND;TZID=W. Europe Standard Time:'.$end,
            'SUMMARY:Budget review', 'LOCATION:Room 3',
            'ORGANIZER;CN=Anna de Vries:mailto:anna@finance.example',
            'ATTENDEE;CN=Kim Visser;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;ROLE=REQ-PARTICIPANT:mailto:kim@company.example',
            'ATTENDEE;PARTSTAT=ACCEPTED:mailto:anna@finance.example',
            'END:VEVENT', 'END:VCALENDAR', '',
        ]);
    }

    /**
     * Ingests a message with a calendar part (from Anna unless said otherwise) and applies it; returns the message id.
     */
    private function receive(string $subject, string $ical, string $from = 'anna@finance.example', string $folder = 'INBOX', ?string $receivedAt = null): string
    {
        ++$this->uid;
        $sha = hash('sha256', $subject.$this->uid);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/messages', ['workerId' => 'w1', 'folder' => $folder, 'messages' => [[
            'remoteUid' => (string) $this->uid,
            'rawObjectKey' => 'raw/'.substr($sha, 0, 2).'/'.$sha,
            'size' => 100,
            'receivedAt' => $receivedAt ?? new \DateTimeImmutable('+1 minute')->format(\DATE_ATOM),
            'messageId' => '<invite-'.$this->uid.'@finance.example>',
            'subject' => $subject,
            'from' => ['address' => $from, 'name' => null],
            'to' => [['address' => 'kim@company.example']],
            'calendarParts' => [['method' => null, 'ical' => $ical]],
        ]]], $this->worker);
        self::assertResponseIsSuccessful();
        $this->applyQueuedInvitations();

        $delta = $this->getJson('/api/v1/sync/changes?since=0', $this->kim);
        self::assertIsArray($delta['messages']);
        foreach ($delta['messages'] as $message) {
            if (\is_array($message) && $message['subject'] === $subject && \is_string($message['id'])) {
                $id = $message['id'];
            }
        }

        return $id ?? self::fail('The message was not stored.');
    }
}
