<?php

declare(strict_types=1);

namespace App\Tests\Functional\Calendar;

use App\Calendar\OccurrenceExpander;
use App\Tests\Functional\AuthenticatesUsers;
use App\Tests\Functional\DatabaseTestCase;

final class CalendarEventApiTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use CalendarTestHelpers;

    private const array SCOPES = ['mail:read', 'mail:send', 'calendar:read', 'calendar:write', 'settings:write'];

    /** @var array<string, string> */
    private array $kim;

    private string $calendarId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->kim = $this->userHeaders('kim@example.test', self::SCOPES);
        $this->calendarId = $this->localCalendarId($this->kim);
    }

    public function testNewUsersHaveAWritableTressoCalendar(): void
    {
        $calendars = $this->getJson('/api/v1/calendars', $this->kim);

        self::assertCount(1, $calendars);
        self::assertIsArray($calendars[0]);
        self::assertSame('Tresso', $calendars[0]['name']);
        self::assertSame('local', $calendars[0]['source']);
        self::assertTrue($calendars[0]['writable']);
        self::assertTrue($calendars[0]['isDefault']);
    }

    public function testCreatesAnEventAndListsItsOccurrence(): void
    {
        $event = $this->create(['location' => 'Room 4', 'reminders' => [10], 'description' => 'Agenda: https://meet.google.com/abc-defg-hij']);

        self::assertSame(['dateTime' => '2026-10-19T09:00:00', 'timeZone' => 'Europe/Amsterdam'], $event['start'] ?? null);
        self::assertSame('Room 4', $event['location'] ?? null);
        self::assertSame([['action' => 'display', 'trigger' => '-PT10M', 'relatedTo' => 'start', 'at' => null]], $event['reminders'] ?? null);
        self::assertSame(['provider' => 'meet', 'url' => 'https://meet.google.com/abc-defg-hij', 'id' => null, 'dialIn' => null], $event['conference'] ?? null);

        $occurrences = $this->occurrences($this->kim, '2026-10-19T00:00:00+00:00', '2026-10-20T00:00:00+00:00');
        self::assertCount(1, $occurrences);
        self::assertSame($event['id'], $occurrences[0]['eventId']);
        self::assertNull($occurrences[0]['recurrenceId']);
        self::assertSame(['dateTime' => '2026-10-19T07:00:00+00:00'], $occurrences[0]['start']);
        self::assertSame(['dateTime' => '2026-10-19T07:30:00+00:00'], $occurrences[0]['end']);
        self::assertSame('Europe/Amsterdam', $occurrences[0]['timeZone']);
    }

    public function testAllDayEventsUseDates(): void
    {
        $this->create(['summary' => 'Offsite', 'start' => ['date' => '2026-10-20'], 'end' => ['date' => '2026-10-22']]);

        $occurrences = $this->occurrences($this->kim, '2026-10-20T00:00:00+02:00', '2026-10-23T00:00:00+02:00', 'Europe/Amsterdam');
        self::assertCount(1, $occurrences);
        self::assertTrue($occurrences[0]['allDay']);
        self::assertSame(['date' => '2026-10-20'], $occurrences[0]['start']);
        self::assertSame(['date' => '2026-10-22'], $occurrences[0]['end']);
    }

    public function testWeeklySeriesKeepsItsLocalTimeAcrossDaylightSaving(): void
    {
        $this->create(['recurrence' => 'FREQ=WEEKLY;COUNT=4']);

        $starts = array_map(static fn (array $o): mixed => $o['start'], $this->occurrences($this->kim, '2026-10-01T00:00:00+00:00', '2026-12-01T00:00:00+00:00'));

        self::assertSame([
            ['dateTime' => '2026-10-19T07:00:00+00:00'],
            ['dateTime' => '2026-10-26T08:00:00+00:00'],
            ['dateTime' => '2026-11-02T08:00:00+00:00'],
            ['dateTime' => '2026-11-09T08:00:00+00:00'],
        ], $starts);
    }

    public function testEditingOneOccurrenceOverridesOnlyThatOne(): void
    {
        $series = $this->create(['recurrence' => 'FREQ=WEEKLY;COUNT=4']);

        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$series['id'].'?scope=this&recurrenceId=20261026T080000Z', self::event($this->calendarId, [
            'summary' => 'Weekly sync (moved)',
            'start' => ['dateTime' => '2026-10-26T11:00:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2026-10-26T11:30:00', 'timeZone' => 'Europe/Amsterdam'],
        ]), $this->kim);
        self::assertResponseIsSuccessful();
        $override = $this->decodedResponse();
        self::assertSame('20261026T080000Z', $override['recurrenceId']);
        self::assertNotSame($series['id'], $override['id']);

        $occurrences = $this->occurrences($this->kim, '2026-10-01T00:00:00+00:00', '2026-12-01T00:00:00+00:00');
        self::assertCount(4, $occurrences);
        self::assertSame('Weekly sync (moved)', $occurrences[1]['summary']);
        self::assertSame(['dateTime' => '2026-10-26T10:00:00+00:00'], $occurrences[1]['start']);
        self::assertSame($override['id'], $occurrences[1]['eventId']);
        self::assertSame('20261026T080000Z', $occurrences[1]['recurrenceId']);
        self::assertSame('Weekly sync', $occurrences[2]['summary']);
        self::assertSame($series['id'], $occurrences[2]['eventId']);
    }

    public function testEditingThisAndFollowingSplitsTheSeries(): void
    {
        $series = $this->create(['recurrence' => 'FREQ=WEEKLY;COUNT=4']);

        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$series['id'].'?scope=following&recurrenceId=20261102T080000Z', self::event($this->calendarId, [
            'summary' => 'Weekly sync, later',
            'recurrence' => 'FREQ=WEEKLY;COUNT=4',
            'start' => ['dateTime' => '2026-11-02T10:00:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2026-11-02T10:30:00', 'timeZone' => 'Europe/Amsterdam'],
        ]), $this->kim);
        self::assertResponseIsSuccessful();
        $next = $this->decodedResponse();
        self::assertNotSame($series['uid'], $next['uid']);
        self::assertSame('FREQ=WEEKLY;COUNT=2', $next['recurrence'], 'The new series takes over the occurrences that were left.');

        $occurrences = $this->occurrences($this->kim, '2026-10-01T00:00:00+00:00', '2026-12-01T00:00:00+00:00');
        self::assertSame(
            [['Weekly sync', '2026-10-19T07:00:00+00:00'], ['Weekly sync', '2026-10-26T08:00:00+00:00'], ['Weekly sync, later', '2026-11-02T09:00:00+00:00'], ['Weekly sync, later', '2026-11-09T09:00:00+00:00']],
            array_map(static fn (array $o): array => [$o['summary'], \is_array($o['start']) ? $o['start']['dateTime'] : null], $occurrences),
        );

        $original = $this->getJson('/api/v1/calendar-events/'.$series['id'], $this->kim);
        self::assertSame('FREQ=WEEKLY;UNTIL=20261102T075959Z', $original['recurrence']);
    }

    public function testEditingAllOccurrencesMovesTheSeriesAndItsExceptions(): void
    {
        $series = $this->create(['recurrence' => 'FREQ=WEEKLY;COUNT=3']);
        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$series['id'].'?scope=this&recurrenceId=20261026T080000Z', self::event($this->calendarId, [
            'location' => 'Room 12',
            'start' => ['dateTime' => '2026-10-26T09:00:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2026-10-26T09:30:00', 'timeZone' => 'Europe/Amsterdam'],
        ]), $this->kim);
        self::assertResponseIsSuccessful();

        // The user drags the third occurrence an hour later and applies it to all.
        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$series['id'].'?scope=all&recurrenceId=20261102T080000Z', self::event($this->calendarId, [
            'recurrence' => 'FREQ=WEEKLY;COUNT=3',
            'start' => ['dateTime' => '2026-11-02T10:00:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2026-11-02T10:30:00', 'timeZone' => 'Europe/Amsterdam'],
        ]), $this->kim);
        self::assertResponseIsSuccessful();

        $occurrences = $this->occurrences($this->kim, '2026-10-01T00:00:00+00:00', '2026-12-01T00:00:00+00:00');
        self::assertCount(3, $occurrences);
        self::assertSame(['dateTime' => '2026-10-19T08:00:00+00:00'], $occurrences[0]['start']);
        self::assertSame('Room 12', $occurrences[1]['location'], 'The exception stays attached to its occurrence.');
        self::assertSame('20261026T090000Z', $occurrences[1]['recurrenceId']);
        self::assertSame(['dateTime' => '2026-11-02T09:00:00+00:00'], $occurrences[2]['start']);
    }

    public function testDeletesOneOccurrence(): void
    {
        $series = $this->create(['recurrence' => 'FREQ=WEEKLY;COUNT=3']);

        $this->client->request('DELETE', '/api/v1/calendar-events/'.$series['id'].'?scope=this&recurrenceId=20261026T080000Z', server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        $occurrences = $this->occurrences($this->kim, '2026-10-01T00:00:00+00:00', '2026-12-01T00:00:00+00:00');
        self::assertSame(['20261019T070000Z', '20261102T080000Z'], array_map(static fn (array $o): mixed => $o['recurrenceId'], $occurrences));
        self::assertSame(['20261026T080000Z'], $this->getJson('/api/v1/calendar-events/'.$series['id'], $this->kim)['exdates']);
    }

    public function testDeletesThisAndFollowing(): void
    {
        $series = $this->create(['recurrence' => 'FREQ=WEEKLY']);

        $this->client->request('DELETE', '/api/v1/calendar-events/'.$series['id'].'?scope=following&recurrenceId=20261102T080000Z', server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        self::assertCount(2, $this->occurrences($this->kim, '2026-10-01T00:00:00+00:00', '2027-06-01T00:00:00+00:00'));
    }

    public function testDeletesAWholeEvent(): void
    {
        $event = $this->create();

        $this->client->request('DELETE', '/api/v1/calendar-events/'.$event['id'], server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], $this->occurrences($this->kim, '2026-10-19T00:00:00+00:00', '2026-10-20T00:00:00+00:00'));
        $this->client->request('GET', '/api/v1/calendar-events/'.$event['id'], server: $this->kim);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsInvalidEvents(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->calendarId, ['end' => ['dateTime' => '2026-10-19T08:00:00', 'timeZone' => 'Europe/Amsterdam']]), $this->kim);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('end', $this->violationPath());

        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->calendarId, ['start' => ['dateTime' => '2026-10-19T09:00:00', 'timeZone' => 'Mars/Olympus']]), $this->kim);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('start', $this->violationPath());

        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->calendarId, ['recurrence' => 'FREQ=WEEKLY;BYDAY=XX']), $this->kim);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('recurrence', $this->violationPath());

        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->calendarId, ['start' => ['date' => '2026-10-19'], 'end' => ['dateTime' => '2026-10-20T00:00:00', 'timeZone' => null]]), $this->kim);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOtherUsersCalendarsAndEventsDoNotExist(): void
    {
        $event = $this->create();
        $this->createUser('lisa@example.test', 'Lisa');
        $lisa = $this->userHeaders('lisa@example.test', self::SCOPES);

        $this->client->request('GET', '/api/v1/calendar-events/'.$event['id'], server: $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->calendarId), $lisa);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->occurrences($lisa, '2026-10-19T00:00:00+00:00', '2026-10-20T00:00:00+00:00'));
    }

    public function testOtherUsersCannotChangeAnswerOrReceiveAnEvent(): void
    {
        $event = $this->create();
        $this->createUser('lisa@example.test', 'Lisa');
        $lisa = $this->userHeaders('lisa@example.test', self::SCOPES);
        $lisasCalendar = $this->localCalendarId($lisa);

        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$event['id'], self::event($lisasCalendar, ['summary' => 'Taken']), $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/api/v1/calendar-events/'.$event['id'], server: $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/calendar-events/'.$event['id'].'/response', ['response' => 'accepted'], $lisa);
        self::assertResponseStatusCodeSame(404);

        // Kim cannot move her event into Lisa's calendar either.
        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$event['id'], self::event($lisasCalendar), $this->kim);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('calendarId', $this->violationPath());

        self::assertSame('Weekly sync', $this->getJson('/api/v1/calendar-events/'.$event['id'], $this->kim)['summary']);
        self::assertSame([], $this->occurrences($lisa, '2026-10-19T00:00:00+00:00', '2026-10-20T00:00:00+00:00'));
    }

    public function testRequiresCalendarScopes(): void
    {
        $mailOnly = $this->userHeaders('kim@example.test', ['mail:read']);

        $this->client->request('GET', '/api/v1/calendars', server: $mailOnly);
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->calendarId), $this->userHeaders('kim@example.test', ['calendar:read']));
        self::assertResponseStatusCodeSame(403);
    }

    public function testLimitsTheOccurrenceRange(): void
    {
        $this->client->request('GET', '/api/v1/calendar-occurrences?'.http_build_query(['from' => '2026-01-01T00:00:00+00:00', 'to' => '2027-06-01T00:00:00+00:00']), server: $this->kim);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnHourlySeriesFillsTheLongestRange(): void
    {
        $this->create(['recurrence' => 'FREQ=HOURLY']);

        $occurrences = $this->occurrences($this->kim, '2026-10-19T00:00:00+00:00', '2027-11-01T00:00:00+00:00');

        // Every hour until the end of the range: no series is cut short without a word.
        self::assertGreaterThan(9000, \count($occurrences));
        self::assertSame(['dateTime' => '2027-10-31T23:00:00+00:00'], $occurrences[\count($occurrences) - 1]['start']);
    }

    public function testARangeHoldingTooManyOccurrencesIsRefused(): void
    {
        $this->create(['recurrence' => 'FREQ=HOURLY']);
        $this->create(['recurrence' => 'FREQ=HOURLY', 'summary' => 'Another']);

        $this->client->request('GET', '/api/v1/calendar-occurrences?'.http_build_query(['from' => '2026-10-19T00:00:00+00:00', 'to' => '2027-11-01T00:00:00+00:00']), server: $this->kim);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString((string) OccurrenceExpander::MAX_OCCURRENCES, self::string($this->decodedResponse()['detail'] ?? null));
    }

    public function testARangeInAnotherOffsetFindsFloatingEvents(): void
    {
        $this->create([
            'start' => ['dateTime' => '2026-10-19T09:00:00', 'timeZone' => null],
            'end' => ['dateTime' => '2026-10-19T10:00:00', 'timeZone' => null],
        ]);

        // The whole of 19 October in Honolulu, written in +14:00.
        $occurrences = $this->occurrences($this->kim, '2026-10-20T00:00:00+14:00', '2026-10-21T00:00:00+14:00', 'Pacific/Honolulu');

        self::assertCount(1, $occurrences);
        self::assertSame(['dateTime' => '2026-10-19T19:00:00+00:00'], $occurrences[0]['start']);
    }

    public function testAnUnknownTimeZoneIsRefused(): void
    {
        $this->client->request('GET', '/api/v1/calendar-occurrences?'.http_build_query(['from' => '2026-10-19T00:00:00+00:00', 'to' => '2026-10-20T00:00:00+00:00', 'timeZone' => 'Mars/Olympus']), server: $this->kim);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('timeZone', $this->violationPath());
    }

    public function testAnOccurrenceFarIntoADenseSeriesCanBeChanged(): void
    {
        $series = $this->create(['recurrence' => 'FREQ=HOURLY']);

        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$series['id'].'?'.http_build_query(['scope' => 'this', 'recurrenceId' => '20400101T000000Z']), self::event($this->calendarId, [
            'summary' => 'New year',
            'start' => ['dateTime' => '2040-01-01T01:30:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2040-01-01T02:00:00', 'timeZone' => 'Europe/Amsterdam'],
        ]), $this->kim);
        self::assertResponseIsSuccessful();

        $occurrences = $this->occurrences($this->kim, '2039-12-31T23:00:00+00:00', '2040-01-01T02:00:00+00:00');
        self::assertSame(['Weekly sync', 'New year', 'Weekly sync'], array_column($occurrences, 'summary'));
        self::assertSame(['dateTime' => '2040-01-01T00:30:00+00:00'], $occurrences[1]['start']);
    }

    public function testASplitCountsTheOccurrencesAnExceptionRemoved(): void
    {
        $series = $this->create(['recurrence' => 'FREQ=WEEKLY;COUNT=4']);
        $this->client->request('DELETE', '/api/v1/calendar-events/'.$series['id'].'?scope=this&recurrenceId=20261026T080000Z', server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$series['id'].'?scope=following&recurrenceId=20261102T080000Z', self::event($this->calendarId, [
            'summary' => 'Weekly sync, later',
            'recurrence' => 'FREQ=WEEKLY;COUNT=4',
            'start' => ['dateTime' => '2026-11-02T10:00:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2026-11-02T10:30:00', 'timeZone' => 'Europe/Amsterdam'],
        ]), $this->kim);
        self::assertResponseIsSuccessful();

        // COUNT includes the removed occurrence (RFC 5545 3.3.10), so two of the four are left for the new series.
        self::assertSame('FREQ=WEEKLY;COUNT=2', $this->decodedResponse()['recurrence']);
        self::assertCount(3, $this->occurrences($this->kim, '2026-10-01T00:00:00+00:00', '2027-01-01T00:00:00+00:00'));
    }

    public function testEndingAFloatingSeriesKeepsItsEndFloating(): void
    {
        $series = $this->create([
            'recurrence' => 'FREQ=WEEKLY',
            'start' => ['dateTime' => '2026-10-19T09:00:00', 'timeZone' => null],
            'end' => ['dateTime' => '2026-10-19T09:30:00', 'timeZone' => null],
        ]);

        $this->client->request('DELETE', '/api/v1/calendar-events/'.$series['id'].'?scope=following&recurrenceId=20261102T090000Z', server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        self::assertSame('FREQ=WEEKLY;UNTIL=20261102T085959', $this->getJson('/api/v1/calendar-events/'.$series['id'], $this->kim)['recurrence']);
        self::assertCount(2, $this->occurrences($this->kim, '2026-10-01T00:00:00+00:00', '2027-01-01T00:00:00+00:00', 'Pacific/Auckland'));
    }

    public function testEditingAllFromAMovedOccurrenceShiftsByWhatTheUserChanged(): void
    {
        $series = $this->create(['recurrence' => 'FREQ=WEEKLY;COUNT=3']);
        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$series['id'].'?scope=this&recurrenceId=20261026T080000Z', self::event($this->calendarId, [
            'start' => ['dateTime' => '2026-10-26T11:00:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2026-10-26T11:30:00', 'timeZone' => 'Europe/Amsterdam'],
        ]), $this->kim);
        self::assertResponseIsSuccessful();

        // The moved occurrence, shown at 11:00, is dragged half an hour later and applied to all.
        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$series['id'].'?scope=all&recurrenceId=20261026T080000Z', self::event($this->calendarId, [
            'recurrence' => 'FREQ=WEEKLY;COUNT=3',
            'start' => ['dateTime' => '2026-10-26T11:30:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2026-10-26T12:00:00', 'timeZone' => 'Europe/Amsterdam'],
        ]), $this->kim);
        self::assertResponseIsSuccessful();

        $occurrences = $this->occurrences($this->kim, '2026-10-01T00:00:00+00:00', '2026-12-01T00:00:00+00:00');
        self::assertSame(['dateTime' => '2026-10-19T07:30:00+00:00'], $occurrences[0]['start']);
    }

    public function testAnEventMovedBeforeTheDeltaWindowLeavesTheCopies(): void
    {
        $event = $this->create();
        $cursor = $this->getJson('/api/v1/sync/changes?since=0', $this->kim)['cursor'];
        self::assertIsInt($cursor);

        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$event['id'], self::event($this->calendarId, [
            'start' => ['dateTime' => '2020-01-06T09:00:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2020-01-06T09:30:00', 'timeZone' => 'Europe/Amsterdam'],
        ]), $this->kim);
        self::assertResponseIsSuccessful();

        $delta = $this->getJson('/api/v1/sync/changes?since='.$cursor, $this->kim);
        self::assertSame([], $delta['calendarEvents']);
        self::assertSame([['type' => 'calendarEvent', 'id' => $event['id']]], $delta['deleted']);
        self::assertSame([], $this->getJson('/api/v1/sync/changes?since=0', $this->kim)['calendarEvents'], 'A new copy never gets it.');
    }

    public function testTheDeltaCarriesCalendarsAndEvents(): void
    {
        $series = $this->create(['recurrence' => 'FREQ=DAILY;COUNT=5']);

        $delta = $this->getJson('/api/v1/sync/changes?since=0', $this->kim);
        self::assertIsArray($delta['calendars']);
        self::assertSame([$this->calendarId], array_column($delta['calendars'], 'id'));
        self::assertIsArray($delta['calendarEvents']);
        self::assertSame([$series['id']], array_column($delta['calendarEvents'], 'id'));
        self::assertSame(['FREQ=DAILY;COUNT=5'], array_column($delta['calendarEvents'], 'recurrence'));
        $cursor = $delta['cursor'];
        self::assertIsInt($cursor);

        $this->client->request('DELETE', '/api/v1/calendar-events/'.$series['id'], server: $this->kim);
        $delta = $this->getJson('/api/v1/sync/changes?since='.$cursor, $this->kim);
        self::assertSame([['type' => 'calendarEvent', 'id' => $series['id']]], $delta['deleted']);
    }

    public function testManagesLocalCalendars(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/calendars', ['name' => 'Family', 'color' => '#E0457B'], $this->kim);
        self::assertResponseStatusCodeSame(201);
        $family = $this->responseString('id');

        $this->client->request('PATCH', '/api/v1/calendars/'.$family, server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['isDefault' => true, 'visible' => false], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->decodedResponse()['isDefault']);
        self::assertFalse($this->decodedResponse()['visible']);
        self::assertSame('#e0457b', $this->decodedResponse()['color']);
        self::assertFalse($this->getJson('/api/v1/calendars/'.$this->calendarId, $this->kim)['isDefault']);

        $this->client->request('DELETE', '/api/v1/calendars/'.$family, server: $this->kim);
        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->getJson('/api/v1/calendars/'.$this->calendarId, $this->kim)['isDefault']);

        $this->client->request('DELETE', '/api/v1/calendars/'.$this->calendarId, server: $this->kim);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{id: string, uid: string, ...}
     */
    private function create(array $overrides = []): array
    {
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->calendarId, $overrides), $this->kim);
        self::assertResponseStatusCodeSame(201);
        $event = $this->decodedResponse();
        self::assertIsString($event['id'] ?? null);
        self::assertIsString($event['uid'] ?? null);

        /** @var array{id: string, uid: string, ...} */
        return $event;
    }

    private function violationPath(): ?string
    {
        $violations = $this->decodedResponse()['violations'] ?? [];
        self::assertIsArray($violations);
        $first = $violations[0] ?? null;

        return \is_array($first) && \is_string($first['propertyPath'] ?? null) ? $first['propertyPath'] : null;
    }
}
