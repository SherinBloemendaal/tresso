<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Bulk\CleanupSuggestions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Cleanup suggestions from fixed rules, the senders of a smart inbox in numbers, and acting on a suggestion in one
 * click, all within the user's own mail.
 */
final class CleanupSuggestionsTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use StoresMail;

    private string $accountId;

    /** @var array<string, string> */
    private array $kim;

    /** @var array<string, string> */
    private array $worker;

    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->createUser('lisa@example.test', 'Lisa');
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'mail:write', 'settings:write']);
        $this->worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->accountId = $this->connectAccount('kim@company.example', $this->kim);
    }

    public function testSuggestsArchivingNewslettersNobodyOpens(): void
    {
        $this->mail('Deals', 'news@shop.example', 3, newsletter: true);

        $suggestions = $this->suggestions();

        self::assertCount(1, $suggestions);
        self::assertSame([
            'id' => 'newsletter:news@shop.example',
            'kind' => 'newsletter',
            'conversations' => 3,
            'unread' => 3,
            'bytes' => null,
            'sender' => 'news@shop.example',
            'senderName' => 'Shop',
            'smartInboxId' => null,
            'windowDays' => CleanupSuggestions::WINDOW_DAYS,
            'windowReceived' => 3,
            'olderThanDays' => null,
            'largerThan' => null,
            'action' => 'archive',
            'scope' => ['in' => 'inbox', 'smartInboxId' => null, 'sender' => 'news@shop.example', 'olderThanDays' => null, 'largerThan' => null],
        ], $suggestions[0]);
    }

    public function testASenderWhoseMailWasOpenedIsNotSuggested(): void
    {
        $this->mail('Deals', 'news@shop.example', 3, newsletter: true);
        $this->mail('Opened', 'news@shop.example', 1, newsletter: true, seen: true, folder: 'Archive');

        self::assertSame([], $this->suggestions());
    }

    public function testOtherSendersNeedManyMoreUnread(): void
    {
        $this->mail('Alert', 'alerts@monitor.example', CleanupSuggestions::SENDER_UNREAD - 1);
        self::assertSame([], $this->suggestions());

        $this->mail('More alerts', 'alerts@monitor.example', 1);
        $suggestions = $this->suggestions();
        self::assertSame(['unopenedSender:alerts@monitor.example'], array_column($suggestions, 'id'));
        self::assertSame(CleanupSuggestions::SENDER_UNREAD, $suggestions[0]['unread']);
    }

    public function testEachRuleAppliesItsOwnThresholdBeforeTheLimit(): void
    {
        // More senders just under the threshold for other mail than suggestions fit, all with more unread than the
        // newsletter that does qualify.
        for ($i = 1; $i <= CleanupSuggestions::MAX_SENDERS * 2 + 1; ++$i) {
            $this->mail("Alerts {$i}", "alerts{$i}@monitor.example", CleanupSuggestions::SENDER_UNREAD - 1);
        }
        $this->mail('Deals', 'news@shop.example', CleanupSuggestions::NEWSLETTER_UNREAD, newsletter: true);

        self::assertSame(['newsletter:news@shop.example'], array_column($this->suggestions(), 'id'));
    }

    public function testASenderWhoSentNothingLatelyIsNotSaidToBeUnopened(): void
    {
        // Enough unread for either rule: only having sent nothing in the window keeps it out.
        $this->mail('Old deals', 'news@shop.example', CleanupSuggestions::SENDER_UNREAD, newsletter: true, receivedAt: new \DateTimeImmutable(\sprintf('-%d days', CleanupSuggestions::WINDOW_DAYS + 10)));

        self::assertSame([], $this->suggestions());
    }

    public function testMailHeldForItsBatchCountsForNothing(): void
    {
        $this->mail('Deals', 'news@shop.example', CleanupSuggestions::NEWSLETTER_UNREAD, newsletter: true, smartInbox: 'advertise');
        $this->connection()->executeStatement("UPDATE message SET held_until = now() + interval '1 hour' WHERE from_address = 'news@shop.example'");
        $this->connection()->executeStatement("UPDATE thread SET held_until = now() + interval '1 hour' WHERE id IN (SELECT thread_id FROM message WHERE from_address = 'news@shop.example')");

        self::assertSame([], $this->suggestions());
        $this->client->request('GET', '/api/v1/smart-inboxes/'.$this->smartInboxId('advertise').'/senders', server: $this->kim);
        self::assertSame(['conversations' => 0, 'messages' => 0, 'unread' => 0, 'senders' => []], array_diff_key($this->decodedResponse(), ['id' => true]));
    }

    public function testTheSenderListLeavesOutAHeldMessageOfAConversationItShows(): void
    {
        $this->mail('Deals', 'news@shop.example', 2, newsletter: true, smartInbox: 'advertise');
        // The second message joins the first conversation and waits for its batch; the conversation itself is shown.
        $this->connection()->executeStatement(
            "UPDATE message SET held_until = now() + interval '1 hour',
                    thread_id = (SELECT thread_id FROM message WHERE subject = 'Deals 1')
             WHERE subject = 'Deals 2'",
        );

        $this->client->request('GET', '/api/v1/smart-inboxes/'.$this->smartInboxId('advertise').'/senders', server: $this->kim);
        self::assertResponseIsSuccessful();
        $senders = $this->decodedResponse();

        self::assertSame(['conversations' => 1, 'messages' => 1, 'unread' => 1], array_intersect_key($senders, ['conversations' => true, 'messages' => true, 'unread' => true]));
        self::assertIsArray($senders['senders']);
        self::assertIsArray($senders['senders'][0]);
        self::assertSame(['conversations' => 1, 'messages' => 1, 'unread' => 1], array_intersect_key($senders['senders'][0], ['conversations' => true, 'messages' => true, 'unread' => true]));
    }

    public function testTheUsersOwnAddressIsNeverSuggested(): void
    {
        $this->mail('Note to self', 'kim@company.example', CleanupSuggestions::SENDER_UNREAD);

        self::assertSame([], $this->suggestions());
    }

    public function testSuggestsArchivingOldNotifications(): void
    {
        $this->mail('Build', 'ci@builds.example', CleanupSuggestions::OLD_MIN, receivedAt: new \DateTimeImmutable('-40 days'), seen: true, smartInbox: 'notification');
        $this->mail('Fresh build', 'ci@builds.example', 2, seen: true, smartInbox: 'notification');

        $suggestions = $this->suggestions();

        self::assertSame(['oldInSmartInbox:'.$this->smartInboxId('notification')], array_column($suggestions, 'id'));
        self::assertSame(CleanupSuggestions::OLD_MIN, $suggestions[0]['conversations']);
        self::assertSame(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('notification'), 'sender' => null, 'olderThanDays' => CleanupSuggestions::OLD_DAYS, 'largerThan' => null], $suggestions[0]['scope']);
    }

    public function testSuggestsTrashingLargeOldMail(): void
    {
        $old = new \DateTimeImmutable('-400 days');
        $this->mail('Scans', 'scanner@office.example', CleanupSuggestions::LARGE_MIN, receivedAt: $old, seen: true, folder: 'Archive', size: CleanupSuggestions::LARGE_BYTES);
        $this->mail('Small', 'scanner@office.example', 2, receivedAt: $old, seen: true, folder: 'Archive');
        $this->mail('Gone', 'scanner@office.example', 2, receivedAt: $old, seen: true, folder: 'Trash', size: CleanupSuggestions::LARGE_BYTES);

        $suggestions = $this->suggestions();

        self::assertSame(['largeOld'], array_column($suggestions, 'id'));
        self::assertSame('trash', $suggestions[0]['action']);
        self::assertSame(CleanupSuggestions::LARGE_MIN, $suggestions[0]['conversations']);
        self::assertSame(CleanupSuggestions::LARGE_MIN * CleanupSuggestions::LARGE_BYTES, $suggestions[0]['bytes']);
    }

    public function testASuggestionIsCleanedUpInOneClick(): void
    {
        $this->mail('Deals', 'news@shop.example', 3, newsletter: true);
        $suggestion = $this->suggestions()[0];
        self::assertIsArray($suggestion['scope']);

        $this->client->jsonRequest('POST', '/api/v1/bulk-actions', [...$suggestion['scope'], 'action' => $suggestion['action']], $this->kim);
        self::assertResponseStatusCodeSame(202);
        $this->runBulkActions();

        self::assertSame([], $this->suggestions());
    }

    public function testListsTheSendersOfASmartInbox(): void
    {
        $this->mail('Deals', 'news@shop.example', 3, newsletter: true, smartInbox: 'advertise');
        $this->mail('Read deals', 'news@shop.example', 1, newsletter: true, seen: true, smartInbox: 'advertise');
        $this->mail('Digest', 'digest@news.example', 1, newsletter: true, smartInbox: 'advertise');
        $this->mail('Old digest', 'digest@news.example', 1, newsletter: true, smartInbox: 'advertise', folder: 'Archive');
        $this->mail('Plan', 'anna@finance.example', 1, smartInbox: 'todo');

        $this->client->request('GET', '/api/v1/smart-inboxes/'.$this->smartInboxId('advertise').'/senders', server: $this->kim);
        self::assertResponseIsSuccessful();
        $senders = $this->decodedResponse();

        self::assertSame(5, $senders['conversations'], 'what the smart inbox lists, not its archived mail');
        self::assertSame(5, $senders['messages']);
        self::assertSame(4, $senders['unread']);
        self::assertIsArray($senders['senders']);
        self::assertSame(['news@shop.example', 'digest@news.example'], array_column($senders['senders'], 'address'));
        self::assertIsArray($senders['senders'][0]);
        self::assertSame(['address' => 'news@shop.example', 'name' => 'Shop', 'conversations' => 4, 'messages' => 4, 'unread' => 3], array_diff_key($senders['senders'][0], ['lastReceivedAt' => true]));
    }

    public function testStaysWithinTheUsersOwnMail(): void
    {
        $this->mail('Deals', 'news@shop.example', 3, newsletter: true, smartInbox: 'advertise');
        $lisa = $this->userHeaders('lisa@example.test', ['mail:read', 'mail:write', 'settings:write']);
        $lisaAccount = $this->connectAccount('lisa@company.example', $lisa);
        $this->mail('Lisa deals', 'news@shop.example', 1, newsletter: true, accountId: $lisaAccount);

        $this->client->request('GET', '/api/v1/cleanup-suggestions', server: $lisa);
        self::assertResponseIsSuccessful();
        self::assertSame(['suggestions' => []], $this->decodedResponse(), 'one unread newsletter of her own is not enough; Kim\'s do not count');

        $this->client->request('GET', '/api/v1/smart-inboxes/'.$this->smartInboxId('advertise').'/senders', server: $lisa);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(3, $this->suggestions()[0]['conversations'], 'and Lisa\'s mail does not count for Kim');
    }

    public function testNeedsTheReadScope(): void
    {
        $this->client->request('GET', '/api/v1/cleanup-suggestions', server: $this->userHeaders('kim@example.test', ['settings:read']));
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestions(): array
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->request('GET', '/api/v1/cleanup-suggestions', server: $this->kim);
        self::assertResponseIsSuccessful();

        /** @var list<array<string, mixed>> */
        return $this->decodedResponse()['suggestions'];
    }

    /**
     * Ingests $count conversations of one message each, "<subject> <n>".
     */
    private function mail(
        string $subject,
        string $from,
        int $count,
        bool $newsletter = false,
        bool $seen = false,
        string $folder = 'INBOX',
        ?\DateTimeImmutable $receivedAt = null,
        int $size = 100,
        ?string $smartInbox = null,
        ?string $accountId = null,
    ): void {
        $messages = [];
        for ($i = 1; $i <= $count; ++$i) {
            $id = '<'.hash('sha256', $subject.$i).'@test.example>';
            $messages[] = [
                'remoteUid' => (string) ++$this->uid,
                'rawObjectKey' => $this->rawKeyFor($accountId ?? $this->accountId, $id),
                'keyVersion' => 1,
                'size' => $size,
                'receivedAt' => ($receivedAt ?? new \DateTimeImmutable('-1 day'))->format(\DATE_ATOM),
                'messageId' => $id,
                'subject' => $subject.' '.$i,
                'from' => ['address' => $from, 'name' => $newsletter ? 'Shop' : 'Sender'],
                'to' => [['address' => 'kim@company.example']],
                'snippet' => $subject,
                'headers' => $newsletter ? ['list-unsubscribe' => '<mailto:unsubscribe@shop.example>'] : [],
                'flags' => ['seen' => $seen, 'flagged' => false, 'answered' => false, 'draft' => false],
            ];
        }
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.($accountId ?? $this->accountId).'/messages', ['workerId' => 'w1', 'folder' => $folder, 'messages' => $messages], $this->worker);
        self::assertResponseIsSuccessful();

        if (null !== $smartInbox) {
            $this->connection()->executeStatement(
                'UPDATE thread SET category_id = :smartInbox WHERE id IN (SELECT thread_id FROM message WHERE message_id_header = ANY(CAST(:headers AS TEXT[])))',
                ['smartInbox' => $this->smartInboxId($smartInbox), 'headers' => '{'.implode(',', array_map(static fn (array $message): string => trim($message['messageId'], '<>'), $messages)).'}'],
            );
        }
    }

    private function smartInboxId(string $builtin): string
    {
        $id = $this->connection()->fetchOne(
            "SELECT c.id FROM category c JOIN app_user u ON u.id = c.owner_id WHERE u.email = 'kim@example.test' AND c.builtin = :builtin",
            ['builtin' => $builtin],
        );
        self::assertIsString($id);

        return $id;
    }

    private function runBulkActions(): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $transport = static::getContainer()->get('messenger.transport.bulk');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        while ([] !== ($envelopes = array_values([...$transport->get()]))) {
            $transport->reset();
            foreach ($envelopes as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('bulk')));
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function connectAccount(string $address, array $headers): string
    {
        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => $address,
            'displayName' => 'Work',
            'username' => $address,
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $headers);
        self::assertResponseStatusCodeSame(201);
        $accountId = $this->responseString('id');

        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$accountId.'/folders', ['workerId' => 'w1', 'folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => 'Archive', 'displayName' => 'Archive', 'role' => 'archive', 'uidValidity' => 2],
            ['remoteName' => 'Trash', 'displayName' => 'Trash', 'role' => 'trash', 'uidValidity' => 3],
        ]], $this->worker);
        self::assertResponseIsSuccessful();

        return $accountId;
    }
}
