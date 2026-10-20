<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Bulk\BulkActionHandler;
use App\Bulk\BulkActions;
use App\Bulk\BulkSelection;
use App\Bulk\RunBulkAction;
use App\Mailbox\MessageActions;
use App\Realtime\AfterCommitDispatcher;
use App\Repository\BulkActionRepository;
use App\Repository\MessageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Bulk actions on a whole smart inbox, one of its senders or mail older than an age: started over the API, worked
 * through in batches the way the messenger worker runs them, and undone exactly.
 */
final class BulkActionsTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

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
        $this->accountId = $this->createAccount('kim@company.example', $this->kim);
        $this->worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->lease();
        $this->folders($this->accountId, [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => 'Archive', 'displayName' => 'Archive', 'role' => 'archive', 'uidValidity' => 2],
            ['remoteName' => 'Trash', 'displayName' => 'Trash', 'role' => 'trash', 'uidValidity' => 3],
            ['remoteName' => 'Projects', 'displayName' => 'Projects', 'role' => 'custom', 'uidValidity' => 4],
            ['remoteName' => 'Junk', 'displayName' => 'Junk', 'role' => 'spam', 'uidValidity' => 5],
        ]);
    }

    public function testArchivingASmartInboxTakesEveryConversationItListsAndNothingElse(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 3);
        $this->ingest('Offsite plan', 'anna@finance.example');
        $this->ingest('Old deals', 'news@shop.example', folder: 'Archive');
        $this->place('Old deals', 'advertise');
        $this->ingest('Junk deals', 'news@shop.example', folder: 'Junk');
        $this->place('Junk deals', 'advertise');

        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'archive']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('running', $bulk['status']);
        $this->runQueued();

        $done = $this->bulkAction($bulk['id']);
        self::assertSame('done', $done['status']);
        self::assertSame(4, $done['conversations'], 'the three in the inbox and the one in Junk: what the smart inbox lists');
        self::assertSame(3, $done['changed'], 'archiving takes mail out of the inbox only; the copy in Junk stays');
        self::assertTrue($done['canUndo']);
        foreach (['Deals 1', 'Deals 2', 'Deals 3'] as $subject) {
            self::assertSame(['Archive'], $this->foldersOf($subject));
        }
        self::assertSame(['INBOX'], $this->foldersOf('Offsite plan'));
        self::assertSame(['Junk'], $this->foldersOf('Junk deals'));
        self::assertSame(3, $this->pendingMoves(), 'the mail server follows as for a single action');
    }

    public function testActsOnOneSenderOfASmartInbox(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 2);
        $this->newsletters('Digest', 'digest@news.example', 2);

        $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'sender' => 'NEWS@Shop.example', 'action' => 'markRead']);
        $this->runQueued();

        self::assertTrue($this->seen('Deals 1'));
        self::assertTrue($this->seen('Deals 2'));
        self::assertFalse($this->seen('Digest 1'));
    }

    public function testActsOnMailOlderThanAnAge(): void
    {
        $this->ingest('Last quarter', 'news@shop.example', receivedAt: new \DateTimeImmutable('-60 days'));
        $this->ingest('This week', 'news@shop.example', receivedAt: new \DateTimeImmutable('-2 days'));
        $this->place('Last quarter', 'advertise');
        $this->place('This week', 'advertise');

        $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'olderThanDays' => 30, 'action' => 'trash']);
        $this->runQueued();

        self::assertSame(['Trash'], $this->foldersOf('Last quarter'));
        self::assertSame(['INBOX'], $this->foldersOf('This week'));
    }

    public function testCountsWhatAnActionWouldTakeBeforeStarting(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 3);
        $this->ingest('Re: Deals 1', 'news@shop.example', inReplyTo: 'Deals 1');

        $this->client->request('GET', '/api/v1/bulk-selection?in=smartInbox&smartInboxId='.$this->smartInboxId('advertise'), server: $this->kim);
        self::assertResponseIsSuccessful();
        self::assertSame(['conversations' => 3, 'messages' => 4, 'mailAccountIds' => [$this->accountId]], $this->decodedResponse());

        $this->client->request('GET', '/api/v1/bulk-selection?in=inbox&sender=nobody@else.example', server: $this->kim);
        self::assertSame(0, $this->decodedResponse()['conversations'] ?? null);

        $this->client->request('GET', '/api/v1/bulk-selection?in=everywhere', server: $this->kim);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/v1/bulk-selection?in=smartInbox', server: $this->kim);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('smartInboxId: Choose the smart inbox.', $this->decodedResponse()['detail'] ?? null);
    }

    public function testWorksThroughLargeScopesInShortBatches(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 5);
        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'archive']);
        $this->takeQueued();
        $actions = $this->bulkActions(conversationsPerBatch: 2);

        self::assertTrue($actions->run(Uuid::fromString($bulk['id']), 1), 'work is left after one batch');
        $progress = $this->bulkAction($bulk['id']);
        self::assertSame('running', $progress['status']);
        self::assertSame(2, $progress['conversations']);
        self::assertSame(2, $this->inFolder('Archive'), 'each batch is committed and seen on its own');

        self::assertFalse($actions->run(Uuid::fromString($bulk['id'])));
        $done = $this->bulkAction($bulk['id']);
        self::assertSame('done', $done['status']);
        self::assertSame(5, $done['conversations']);
        self::assertSame(5, $this->inFolder('Archive'));
    }

    public function testABatchStopsAtAboutAsManyMessagesAsItMayTakeButTakesAtLeastOneConversation(): void
    {
        $this->ingest('Long thread', 'news@shop.example');
        $this->ingest('Re: Long thread', 'news@shop.example', inReplyTo: 'Long thread');
        $this->ingest('Re: Re: Long thread', 'news@shop.example', inReplyTo: 'Re: Long thread');
        $this->place('Long thread', 'advertise');
        $this->newsletters('Deals', 'news@shop.example', 2);
        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'markRead']);
        $this->takeQueued();

        $this->bulkActions(messagesPerBatch: 2)->run(Uuid::fromString($bulk['id']), 1);

        self::assertSame(1, $this->bulkAction($bulk['id'])['conversations']);
    }

    public function testTheHandlerQueuesTheRestOfALongRun(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 3);
        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'markRead']);
        $this->takeQueued();

        $handler = new BulkActionHandler($this->bulkActions(conversationsPerBatch: 1), static::getContainer()->get(MessageBusInterface::class), batchesPerRun: 1);
        $handler(new RunBulkAction($bulk['id']));

        $rest = $this->takeQueued();
        self::assertCount(1, $rest);
        self::assertInstanceOf(RunBulkAction::class, $rest[0]->getMessage());
        self::assertSame(1, $this->bulkAction($bulk['id'])['conversations']);
    }

    public function testLeavesMailThatArrivesWhileItRuns(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 1);
        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'archive']);
        $queued = $this->takeQueued();
        $this->ingest('Re: Deals 1', 'news@shop.example', inReplyTo: 'Deals 1');

        $this->runQueued($queued);

        self::assertSame(['Archive'], $this->foldersOf('Deals 1'));
        self::assertSame(['INBOX'], $this->foldersOf('Re: Deals 1'), 'it reached Tresso after the action started');
        self::assertSame(1, $this->bulkAction($bulk['id'])['changed']);
    }

    public function testUndoPutsBackExactlyWhatMoved(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 3);
        $this->ingest('Deals 1', 'news@shop.example', folder: 'Projects', messageId: 'Deals 1');
        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'trash']);
        $this->runQueued();
        $done = $this->bulkAction($bulk['id']);
        self::assertSame(3, $done['changed']);
        self::assertSame(1, $done['unrestorable'], 'Deals 1 was in two folders: a move takes both copies');

        // Moved on since: the undo leaves it where the user put it now.
        $this->client->jsonRequest('POST', '/api/v1/message-actions', ['messageIds' => [$this->messageId('Deals 3')], 'action' => 'move', 'folderId' => $this->folderId('Projects')], $this->kim);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/v1/bulk-actions/'.$bulk['id'].'/undo', [], $this->kim);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('undoing', $this->decodedResponse()['status'] ?? null);
        $this->runQueued();

        $undone = $this->bulkAction($bulk['id']);
        self::assertSame('undone', $undone['status']);
        self::assertSame(1, $undone['restored']);
        self::assertFalse($undone['canUndo']);
        self::assertSame(['INBOX'], $this->foldersOf('Deals 2'));
        self::assertSame(['Trash', 'Trash'], $this->foldersOf('Deals 1'), 'both copies went to Trash, and the undo leaves them there');
        self::assertSame(['Projects'], $this->foldersOf('Deals 3'));

        $this->client->jsonRequest('POST', '/api/v1/bulk-actions/'.$bulk['id'].'/undo', [], $this->kim);
        self::assertResponseStatusCodeSame(409);
    }

    public function testUndoingAnArchivePutsMailBackInTheInbox(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 2);
        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'archive']);
        $this->runQueued();

        $this->client->jsonRequest('POST', '/api/v1/bulk-actions/'.$bulk['id'].'/undo', [], $this->kim);
        $this->runQueued();

        self::assertSame(2, $this->bulkAction($bulk['id'])['restored']);
        self::assertSame(['INBOX'], $this->foldersOf('Deals 1'));
        self::assertSame(['INBOX'], $this->foldersOf('Deals 2'));
    }

    public function testArchivingIntoAllMailCannotBeUndone(): void
    {
        $gmail = $this->createAccount('kim@home.example', $this->kim);
        $this->lease();
        $this->folders($gmail, [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => '[Gmail]/All Mail', 'displayName' => 'All Mail', 'role' => 'all', 'uidValidity' => 2],
            ['remoteName' => '[Gmail]/Trash', 'displayName' => 'Trash', 'role' => 'trash', 'uidValidity' => 3],
        ]);
        $this->ingest('Deals', 'news@shop.example', accountId: $gmail);
        $this->place('Deals', 'advertise');

        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'archive']);
        $this->runQueued();

        $done = $this->bulkAction($bulk['id']);
        self::assertSame(1, $done['changed']);
        self::assertSame(1, $done['unrestorable'], 'moving out of All Mail would delete it');
    }

    public function testMarkingReadIsNotUndone(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 1);
        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'markRead']);
        $this->runQueued();

        self::assertFalse($this->bulkAction($bulk['id'])['canUndo']);
        $this->client->jsonRequest('POST', '/api/v1/bulk-actions/'.$bulk['id'].'/undo', [], $this->kim);
        self::assertResponseStatusCodeSame(409);
    }

    public function testMovesWithinOneAccount(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 2);
        $smartInbox = $this->smartInboxId('advertise');

        $this->start(['in' => 'smartInbox', 'smartInboxId' => $smartInbox, 'action' => 'move', 'folderId' => $this->folderId('Projects')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('mailAccountId: A move stays within one account: choose it.', $this->decodedResponse()['detail'] ?? null);

        $other = $this->createAccount('kim@home.example', $this->kim);
        $this->lease();
        $this->folders($other, [['remoteName' => 'Work', 'displayName' => 'Work', 'role' => 'custom', 'uidValidity' => 1]]);
        $this->start(['in' => 'smartInbox', 'smartInboxId' => $smartInbox, 'mailAccountId' => $this->accountId, 'action' => 'move', 'folderId' => $this->folderIdOf($other, 'Work')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('folderId: Messages can only move to a folder of their own account.', $this->decodedResponse()['detail'] ?? null);

        $this->start(['in' => 'smartInbox', 'smartInboxId' => $smartInbox, 'mailAccountId' => $this->accountId, 'action' => 'move', 'folderId' => $this->folderId('Projects')]);
        self::assertResponseStatusCodeSame(202);
        $this->runQueued();
        self::assertSame(['Projects'], $this->foldersOf('Deals 1'));
        self::assertSame(['Projects'], $this->foldersOf('Deals 2'));
    }

    public function testValidatesTheRequest(): void
    {
        $this->start(['in' => 'smartInbox', 'action' => 'archive']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('smartInboxId: Choose the smart inbox.', $this->decodedResponse()['detail'] ?? null);

        $this->start(['in' => 'inbox', 'action' => 'flag']);
        self::assertResponseStatusCodeSame(422);

        $this->start(['in' => 'inbox', 'action' => 'archive', 'folderId' => $this->folderId('Projects')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('folderId: Only a move takes a folder.', $this->decodedResponse()['detail'] ?? null);

        $this->start(['in' => 'inbox', 'action' => 'archive', 'sender' => 'not an address']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRefusesWhenAnAccountInScopeHasNoFolderForIt(): void
    {
        $other = $this->createAccount('kim@home.example', $this->kim);
        $this->lease();
        $this->folders($other, [['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1]]);
        $this->ingest('Deals', 'news@shop.example', accountId: $other);

        $this->start(['in' => 'inbox', 'action' => 'archive']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('action: kim@home.example has no Archive folder.', $this->decodedResponse()['detail'] ?? null);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM bulk_action'));
    }

    public function testStopsWhenItsSmartInboxIsDeletedAndKeepsWhatItDid(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 2);
        $smartInbox = $this->smartInboxId('advertise');
        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $smartInbox, 'action' => 'archive']);
        $this->takeQueued();
        $actions = $this->bulkActions(conversationsPerBatch: 1);
        $actions->run(Uuid::fromString($bulk['id']), 1);

        $this->connection()->executeStatement('DELETE FROM category WHERE id = :id', ['id' => $smartInbox]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $actions->run(Uuid::fromString($bulk['id']));

        $stopped = $this->bulkAction($bulk['id']);
        self::assertSame('failed', $stopped['status']);
        self::assertSame('The smart inbox was deleted.', $stopped['error']);
        self::assertNull($stopped['scope']);
        self::assertTrue($stopped['canUndo'], 'what it did so far can still be undone');
    }

    public function testARunThatKeepsFailingIsMarkedAsStopped(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 1);
        $bulk = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'archive']);
        $this->takeQueued();

        static::getContainer()->get(BulkActions::class)->fail(Uuid::fromString($bulk['id']), 'Something went wrong.');

        self::assertSame('failed', $this->bulkAction($bulk['id'])['status']);
    }

    public function testFinishedActionsAreForgottenAfterADay(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 1);
        $old = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'markRead']);
        $this->runQueued();
        $this->connection()->executeStatement("UPDATE bulk_action SET finished_at = now() - interval '25 hours' WHERE id = :id", ['id' => $old['id']]);

        $this->start(['in' => 'inbox', 'action' => 'markRead']);

        $this->client->request('GET', '/api/v1/bulk-actions/'.$old['id'], server: $this->kim);
        self::assertResponseStatusCodeSame(404);
    }

    public function testStaysWithinTheUsersOwnMail(): void
    {
        $this->newsletters('Deals', 'news@shop.example', 1);
        $kims = $this->start(['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'archive']);
        $this->runQueued();

        $lisa = $this->userHeaders('lisa@example.test', ['mail:read', 'mail:write', 'settings:write']);
        $lisaAccount = $this->createAccount('lisa@company.example', $lisa);
        $this->lease();
        $this->folders($lisaAccount, [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => 'Archive', 'displayName' => 'Archive', 'role' => 'archive', 'uidValidity' => 2],
        ]);
        $this->ingest('Lisa deals', 'news@shop.example', accountId: $lisaAccount);
        $this->ingest('Kim deals', 'news@shop.example');

        $this->client->request('GET', '/api/v1/bulk-actions/'.$kims['id'], server: $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/bulk-actions/'.$kims['id'].'/undo', [], $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/bulk-actions', ['in' => 'smartInbox', 'smartInboxId' => $this->smartInboxId('advertise'), 'action' => 'archive'], $lisa);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('smartInboxId: This smart inbox does not exist.', $this->decodedResponse()['detail'] ?? null);
        $this->client->jsonRequest('POST', '/api/v1/bulk-actions', ['in' => 'inbox', 'mailAccountId' => $this->accountId, 'action' => 'archive'], $lisa);
        self::assertSame('mailAccountId: This account does not exist.', $this->decodedResponse()['detail'] ?? null);
        $this->client->request('GET', '/api/v1/bulk-selection?in=inbox&sender=news@shop.example', server: $lisa);
        self::assertSame(1, $this->decodedResponse()['conversations'] ?? null, 'only her own conversation counts');

        $this->client->jsonRequest('POST', '/api/v1/bulk-actions', ['in' => 'inbox', 'sender' => 'news@shop.example', 'action' => 'archive'], $lisa);
        self::assertResponseStatusCodeSame(202);
        $this->runQueued();

        self::assertSame(['Archive'], $this->foldersOf('Lisa deals'));
        self::assertSame(['INBOX'], $this->foldersOf('Kim deals'), 'Kim\'s mail from the same sender stays where it is');
    }

    public function testNeedsTheMailScopes(): void
    {
        $readOnly = $this->userHeaders('kim@example.test', ['mail:read']);
        $this->client->jsonRequest('POST', '/api/v1/bulk-actions', ['in' => 'inbox', 'action' => 'markRead'], $readOnly);
        self::assertResponseStatusCodeSame(403);

        $nothing = $this->userHeaders('kim@example.test', ['settings:read']);
        $this->client->request('GET', '/api/v1/bulk-selection?in=inbox', server: $nothing);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{id: string, status: string}
     */
    private function start(array $payload): array
    {
        $this->client->jsonRequest('POST', '/api/v1/bulk-actions', $payload, $this->kim);

        /** @var array{id: string, status: string} */
        return $this->decodedResponse();
    }

    /**
     * @return array{id: string, status: string, conversations: int, changed: int, unrestorable: int, restored: int, canUndo: bool, error: ?string, scope: ?array<string, mixed>}
     */
    private function bulkAction(string $id): array
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->request('GET', '/api/v1/bulk-actions/'.$id, server: $this->kim);
        self::assertResponseIsSuccessful();

        /** @var array{id: string, status: string, conversations: int, changed: int, unrestorable: int, restored: int, canUndo: bool, error: ?string, scope: ?array<string, mixed>} */
        return $this->decodedResponse();
    }

    /**
     * The service with smaller batches, to see them.
     */
    private function bulkActions(int $conversationsPerBatch = BulkActions::CONVERSATIONS_PER_BATCH, int $messagesPerBatch = BulkActions::MESSAGES_PER_BATCH): BulkActions
    {
        $container = static::getContainer();

        return new BulkActions(
            $container->get(EntityManagerInterface::class),
            $container->get(Connection::class),
            $container->get(BulkSelection::class),
            $container->get(MessageActions::class),
            $container->get(MessageRepository::class),
            $container->get(BulkActionRepository::class),
            $container->get(AfterCommitDispatcher::class),
            $conversationsPerBatch,
            $messagesPerBatch,
        );
    }

    /**
     * Handles what the last request queued (or the given envelopes), the way the messenger worker would, until nothing
     * is left. The test client empties the in-memory transport before every request, so this runs right after it.
     *
     * @param list<Envelope>|null $envelopes
     */
    private function runQueued(?array $envelopes = null): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $envelopes ??= $this->takeQueued();
        while ([] !== $envelopes) {
            foreach ($envelopes as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('bulk')));
            }
            $envelopes = $this->takeQueued();
        }
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @phpstan-impure
     *
     * @return list<Envelope>
     */
    private function takeQueued(): array
    {
        $transport = static::getContainer()->get('messenger.transport.bulk');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = array_values([...$transport->get()]);
        $transport->reset();

        return $envelopes;
    }

    private function newsletters(string $subject, string $from, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->ingest($subject.' '.$i, $from, headers: ['list-unsubscribe' => '<mailto:unsubscribe@shop.example>']);
            $this->place($subject.' '.$i, 'advertise');
        }
    }

    /**
     * Ingests one message; the subject doubles as its Message-ID, so it is how tests find it again.
     *
     * @param array<string, string> $headers
     */
    private function ingest(
        string $subject,
        string $from,
        string $folder = 'INBOX',
        ?\DateTimeImmutable $receivedAt = null,
        ?string $inReplyTo = null,
        ?string $messageId = null,
        array $headers = [],
        ?string $accountId = null,
    ): void {
        $id = self::header($messageId ?? $subject);
        $sha = hash('sha256', $id.$folder);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.($accountId ?? $this->accountId).'/messages', ['workerId' => 'w1', 'folder' => $folder, 'messages' => [array_filter([
            'remoteUid' => (string) ++$this->uid,
            'rawObjectKey' => 'raw/'.substr($sha, 0, 2).'/'.$sha,
            'size' => 100,
            'receivedAt' => ($receivedAt ?? new \DateTimeImmutable('-1 day'))->format(\DATE_ATOM),
            'messageId' => $id,
            'inReplyTo' => null === $inReplyTo ? null : self::header($inReplyTo),
            'references' => null === $inReplyTo ? null : [self::header($inReplyTo)],
            'subject' => $subject,
            'from' => ['address' => $from, 'name' => 'Sender'],
            'to' => [['address' => 'kim@company.example']],
            'snippet' => $subject,
            'headers' => $headers,
            'flags' => ['seen' => false, 'flagged' => false, 'answered' => false, 'draft' => false],
        ], static fn (mixed $value): bool => null !== $value)]], $this->worker);
        self::assertResponseIsSuccessful();
    }

    private static function header(string $subject): string
    {
        return '<'.hash('sha256', $subject).'@test.example>';
    }

    /** Puts the conversation in one of Kim's built-in smart inboxes, as classification would. */
    private function place(string $subject, string $builtin): void
    {
        $this->connection()->executeStatement(
            'UPDATE thread SET category_id = :smartInbox WHERE id = (SELECT thread_id FROM message WHERE message_id_header = :header LIMIT 1)',
            ['smartInbox' => $this->smartInboxId($builtin), 'header' => trim(self::header($subject), '<>')],
        );
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

    private function messageId(string $subject): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM message WHERE message_id_header = :header', ['header' => trim(self::header($subject), '<>')]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<string> the folders the message has a copy in
     */
    private function foldersOf(string $subject): array
    {
        /** @var list<string> */
        return $this->connection()->fetchFirstColumn(
            'SELECT f.remote_name FROM message_location l JOIN folder f ON f.id = l.folder_id WHERE l.message_id = :id ORDER BY f.remote_name',
            ['id' => $this->messageId($subject)],
        );
    }

    private function seen(string $subject): bool
    {
        return (bool) $this->connection()->fetchOne('SELECT seen FROM message WHERE id = :id', ['id' => $this->messageId($subject)]);
    }

    private function inFolder(string $remoteName): int
    {
        return $this->rowCount(
            'SELECT COUNT(*) FROM message_location l JOIN folder f ON f.id = l.folder_id WHERE f.mail_account_id = :account AND f.remote_name = :name',
            ['account' => $this->accountId, 'name' => $remoteName],
        );
    }

    private function pendingMoves(): int
    {
        return $this->rowCount("SELECT COUNT(*) FROM mailbox_change WHERE kind = 'move'");
    }

    /**
     * @param array<string, string> $parameters
     */
    private function rowCount(string $sql, array $parameters = []): int
    {
        $count = $this->connection()->fetchOne($sql, $parameters);
        self::assertIsInt($count);

        return $count;
    }

    private function folderId(string $remoteName): string
    {
        return $this->folderIdOf($this->accountId, $remoteName);
    }

    private function folderIdOf(string $accountId, string $remoteName): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM folder WHERE mail_account_id = :account AND remote_name = :name', ['account' => $accountId, 'name' => $remoteName]);
        self::assertIsString($id);

        return $id;
    }

    private function lease(): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<array<string, mixed>> $folders
     */
    private function folders(string $accountId, array $folders): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$accountId.'/folders', ['workerId' => 'w1', 'folders' => $folders], $this->worker);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string> $headers
     */
    private function createAccount(string $address, array $headers): string
    {
        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => $address,
            'displayName' => 'Kim',
            'username' => $address,
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $headers);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }
}
