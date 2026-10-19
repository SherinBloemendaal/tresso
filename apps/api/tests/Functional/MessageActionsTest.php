<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

final class MessageActionsTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private string $accountId;

    /** @var array<string, string> */
    private array $kim;

    /** @var array<string, string> */
    private array $worker;

    private string $messageId;

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
        ]);
        $this->ingest($this->accountId, 'INBOX', '1', '<plan@finance.example>');
        $this->messageId = $this->message()['id'];
    }

    public function testMarkingReadShowsAtOnceAndReachesTheServer(): void
    {
        $this->act('markRead');
        self::assertSame(['changed' => 1], $this->decodedResponse());
        self::assertTrue($this->message()['flags']['seen']);
        self::assertContains($this->accountId, $this->lease(), 'the heartbeat points the worker at the account');

        $changes = $this->claim();
        self::assertCount(1, $changes);
        self::assertSame('flags', $changes[0]['kind']);
        self::assertTrue($changes[0]['seen']);
        self::assertNull($changes[0]['flagged']);
        self::assertSame([['folder' => 'INBOX', 'uidValidity' => 1, 'remoteUid' => '1']], $changes[0]['locations']);
        self::assertSame([], $this->claim(), 'a claimed change is not handed out twice');

        $this->report($changes[0]['id'], 'done');
        self::assertSame(['accepted' => true], $this->decodedResponse());
        self::assertSame(0, $this->pendingChanges());
    }

    public function testDoingWhatIsAlreadySoChangesNothing(): void
    {
        $this->act('markUnread');

        self::assertSame(['changed' => 0], $this->decodedResponse());
        self::assertSame(0, $this->pendingChanges());
    }

    public function testTheFolderSyncDoesNotPutOldFlagsBackMeanwhile(): void
    {
        $this->act('markRead');
        $this->act('flag');

        $this->flagChange('INBOX', '1', seen: false, flagged: false, answered: true);
        $message = $this->message();
        self::assertTrue($message['flags']['seen']);
        self::assertTrue($message['flags']['flagged']);
        self::assertTrue($message['flags']['answered'], 'other flags still follow the server');

        foreach ($this->claim() as $change) {
            $this->report($change['id'], 'done');
        }
        $this->flagChange('INBOX', '1', seen: true, flagged: false, answered: true);
        self::assertFalse($this->message()['flags']['flagged'], 'once confirmed, the server has the last word again');
    }

    public function testArchivingMovesTheMessageOutOfTheInbox(): void
    {
        $this->act('archive');
        self::assertSame([$this->folderId('Archive')], $this->message()['folderIds']);

        $changes = $this->claim();
        self::assertCount(1, $changes);
        self::assertSame('move', $changes[0]['kind']);
        self::assertSame(['folder' => 'INBOX', 'uidValidity' => 1, 'remoteUid' => '1'], $changes[0]['source']);
        self::assertSame(['folder' => 'Archive'], $changes[0]['target']);

        $this->report($changes[0]['id'], 'done', '7');
        self::assertSame(['Archive' => '7'], $this->locations());

        // The folder sync finds the copy where the server reported it: nothing doubles.
        $this->ingest($this->accountId, 'Archive', '7', '<plan@finance.example>');
        $this->changesBatch('INBOX', ['vanished' => ['1']]);
        self::assertSame(['Archive' => '7'], $this->locations());
        self::assertSame([$this->folderId('Archive')], $this->message()['folderIds']);
    }

    public function testAMoveWithoutAReportedUidLandsWhenTheFolderSyncFindsIt(): void
    {
        $this->act('move', $this->folderId('Projects'));
        $this->report($this->claim()[0]['id'], 'done');
        self::assertSame([$this->folderId('Projects')], $this->message()['folderIds']);

        $this->ingest($this->accountId, 'Projects', '12', '<plan@finance.example>');

        self::assertSame(['Projects' => '12'], $this->locations());
    }

    public function testAMoveTheServerRefusesIsUndone(): void
    {
        $this->act('trash');
        self::assertSame([$this->folderId('Trash')], $this->message()['folderIds']);

        $this->report($this->claim()[0]['id'], 'failed', error: 'NO [TRYCREATE] Mailbox does not exist');

        self::assertSame(['INBOX' => '1'], $this->locations());
        self::assertSame([$this->folderId('INBOX')], $this->message()['folderIds']);
        self::assertSame(0, $this->pendingChanges());
    }

    public function testAFlagChangeTheServerRefusesIsUndone(): void
    {
        $this->act('flag');
        $this->report($this->claim()[0]['id'], 'failed', error: 'NO Permission denied');

        self::assertFalse($this->message()['flags']['flagged']);
    }

    public function testAFailureThatMayPassIsTriedAgainLaterAndGivenUpInTheEnd(): void
    {
        $this->act('archive');
        $id = $this->claim()[0]['id'];
        $this->report($id, 'retry', error: 'connection reset');
        self::assertSame([], $this->claim(), 'it waits before the next attempt');

        for ($attempt = 2; $attempt <= 6; ++$attempt) {
            $this->makeDue();
            $changes = $this->claim();
            self::assertSame($id, $changes[0]['id'] ?? null, 'attempt '.$attempt);
            $this->report($id, 'retry', error: 'connection reset');
        }

        self::assertSame(0, $this->pendingChanges());
        self::assertSame(['INBOX' => '1'], $this->locations());
    }

    public function testLaterChangesWaitForTheMoveTheyBuildOn(): void
    {
        $this->act('archive');
        $this->act('markRead');
        $this->act('move', $this->folderId('Projects'));

        $changes = $this->claim();
        self::assertCount(1, $changes, 'the flag change and the second move wait for the copy to land');
        $this->report($changes[0]['id'], 'done', '7');

        $changes = $this->claim();
        self::assertSame(['flags', 'move'], array_column($changes, 'kind'));
        self::assertSame([['folder' => 'Archive', 'uidValidity' => 2, 'remoteUid' => '7']], $changes[0]['locations']);
        self::assertSame(['folder' => 'Archive', 'uidValidity' => 2, 'remoteUid' => '7'], $changes[1]['source']);
        self::assertSame(['folder' => 'Projects'], $changes[1]['target']);
    }

    public function testUndoingAMoveUndoesTheMovesBuiltOnIt(): void
    {
        $this->act('archive');
        $this->act('trash');
        self::assertSame([$this->folderId('Trash')], $this->message()['folderIds']);

        $this->report($this->claim()[0]['id'], 'failed', error: 'NO Over quota');

        self::assertSame(['INBOX' => '1'], $this->locations());
        self::assertSame(0, $this->pendingChanges());
    }

    public function testAMoveOfACopyStillOnItsWayStartsFromWhereTheCopyLanded(): void
    {
        $this->act('archive');
        $archive = $this->claim()[0]['id'];
        $this->act('trash');

        // The server moved it without saying where (no UIDPLUS); the folder sync finds it in Archive afterwards.
        $this->report($archive, 'done');
        self::assertSame([], $this->claim(), 'the move to Trash waits until the copy is found');
        $this->ingest($this->accountId, 'Archive', '7', '<plan@finance.example>');

        self::assertSame([$this->folderId('Trash')], $this->message()['folderIds'], 'it does not show up in Archive again');
        $changes = $this->claim();
        self::assertSame(['folder' => 'Archive', 'uidValidity' => 2, 'remoteUid' => '7'], $changes[0]['source']);
        self::assertSame(['folder' => 'Trash'], $changes[0]['target']);
    }

    public function testTrashingOnGmailFindsTheOtherLabelsGoneAndLeavesOneCopy(): void
    {
        $this->folders($this->accountId, [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => 'Archive', 'displayName' => 'Archive', 'role' => 'archive', 'uidValidity' => 2],
            ['remoteName' => 'Trash', 'displayName' => 'Trash', 'role' => 'trash', 'uidValidity' => 3],
            ['remoteName' => 'Projects', 'displayName' => 'Projects', 'role' => 'custom', 'uidValidity' => 4],
            ['remoteName' => '[Gmail]/All Mail', 'displayName' => 'All Mail', 'role' => 'all', 'uidValidity' => 5],
        ]);
        $this->ingest($this->accountId, '[Gmail]/All Mail', '40', '<plan@finance.example>');
        $this->act('trash');

        $changes = $this->claim();
        self::assertCount(2, $changes);
        $this->report($changes[0]['id'], 'done', '9');
        // Moving the first copy to Trash took every label away on Gmail.
        $this->report($changes[1]['id'], 'gone');

        self::assertSame(['Trash' => '9'], $this->locations());
        self::assertSame(0, $this->pendingChanges());
    }

    public function testAMoveThatRanBeforeButWentUnreportedIsNotUndone(): void
    {
        $this->act('archive');
        $this->report($this->claim()[0]['id'], 'gone');

        self::assertSame([$this->folderId('Archive')], $this->message()['folderIds'], 'nothing is put back in the inbox');
        $this->ingest($this->accountId, 'Archive', '7', '<plan@finance.example>');
        self::assertSame(['Archive' => '7'], $this->locations(), 'the same message, found where the move took it');
    }

    public function testAChangeTriedAgainKeepsTheMessagesLaterChangesBehindIt(): void
    {
        $this->act('markRead');
        $this->act('markUnread');

        $changes = $this->claim();
        self::assertSame([true, false], array_column($changes, 'seen'), 'the changes of a message come in order');
        $this->report($changes[0]['id'], 'retry', error: 'NO [INUSE] busy');
        self::assertSame([], $this->claim(), 'the unread is handed back and waits for the read');

        $this->makeDue();
        self::assertSame([true, false], array_column($this->claim(), 'seen'), 'the read goes first again');
    }

    public function testChangesThatWaitDoNotHoldUpOtherMessages(): void
    {
        $this->ingest($this->accountId, 'INBOX', '2', '<other@finance.example>');
        $this->act('markRead');
        $this->report($this->claim()[0]['id'], 'retry', error: 'NO [INUSE] busy');
        for ($i = 0; $i < 101; ++$i) {
            $this->act(0 === $i % 2 ? 'flag' : 'unflag');
        }
        $this->act('flag', ids: [$this->message('<other@finance.example>')['id']]);

        $changes = $this->claim();

        self::assertCount(1, $changes);
        self::assertSame(['folder' => 'INBOX', 'uidValidity' => 1, 'remoteUid' => '2'], $changes[0]['locations'][0]);
    }

    public function testACopyFoundBeforeItsMoveWasReportedStillLandsForTheNextMove(): void
    {
        $this->act('move', $this->folderId('Projects'));
        $first = $this->claim()[0]['id'];
        $this->act('archive');
        $this->act('move', $this->folderId('Trash'));

        // The server moved it (no UIDPLUS) and the folder sync saw it in Projects before the report came in.
        $this->ingest($this->accountId, 'Projects', '5', '<plan@finance.example>');
        self::assertSame([$this->folderId('Trash')], $this->message()['folderIds'], 'it does not show up in Projects');
        $this->report($first, 'done');

        $changes = $this->claim();
        self::assertSame(['folder' => 'Projects', 'uidValidity' => 4, 'remoteUid' => '5'], $changes[0]['source']);
    }

    public function testACopyAboutToMoveOnDoesNotShowUpWhereItPassesThrough(): void
    {
        $this->act('move', $this->folderId('Projects'));
        $first = $this->claim()[0]['id'];
        $this->act('move', $this->folderId('Trash'));
        $this->report($first, 'done', '5');

        $this->ingest($this->accountId, 'Projects', '5', '<plan@finance.example>');

        self::assertSame([$this->folderId('Trash')], $this->message()['folderIds']);
    }

    public function testAPlaceholderThatNeverLandsIsDroppedAfterAWhile(): void
    {
        $this->ingest($this->accountId, 'Projects', '3', '<plan@finance.example>');
        $this->act('archive');
        $this->report($this->claim()[0]['id'], 'done');
        self::assertCount(2, $this->locations(), 'the placeholder waits for the folder sync');
        $old = new UuidV7(UuidV7::generate(new \DateTimeImmutable('-2 hours')));
        $this->connection()->executeStatement("UPDATE message_location SET remote_uid = :old WHERE remote_uid LIKE 'pending:%'", ['old' => 'pending:'.$old->toRfc4122()]);

        $this->claim();

        self::assertSame(['Projects' => '3'], $this->locations());
    }

    public function testAMoveMadeOfACopyWhoseMoveWentUnreportedSurvivesItsRepeat(): void
    {
        $this->act('archive');
        $this->claim();
        $this->act('move', $this->folderId('Projects'));
        $this->makeClaimsExpire();

        // The archive ran but went unreported; running it again finds the copy gone from INBOX.
        $changes = $this->claim();
        self::assertCount(1, $changes, 'the move to Projects waits for the archived copy');
        $this->report($changes[0]['id'], 'gone');
        self::assertSame([$this->folderId('Projects')], $this->message()['folderIds'], 'the move to Projects still shows');
        self::assertSame(1, $this->pendingChanges());

        $this->ingest($this->accountId, 'Archive', '7', '<plan@finance.example>');
        $changes = $this->claim();
        self::assertSame(['folder' => 'Archive', 'uidValidity' => 2, 'remoteUid' => '7'], $changes[0]['source']);
        self::assertSame(['folder' => 'Projects'], $changes[0]['target']);
    }

    public function testAMoveWhoseSourceNeverShowsUpIsGivenUpAfterAWhile(): void
    {
        $this->ingest($this->accountId, 'Projects', '3', '<plan@finance.example>');
        $this->act('archive');
        $first = $this->claim()[0]['id'];
        $this->act('move', $this->folderId('Projects'));
        $this->report($first, 'done');
        self::assertSame(1, $this->pendingChanges(), 'the move back to Projects waits for the archived copy to show up');

        $old = new UuidV7(UuidV7::generate(new \DateTimeImmutable('-2 hours')));
        $this->connection()->executeStatement("UPDATE mailbox_change SET source_remote_uid = :old WHERE source_remote_uid LIKE 'pending:%'", ['old' => 'pending:'.$old->toRfc4122()]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->claim();

        self::assertSame(0, $this->pendingChanges());
        self::assertSame(['Projects' => '3'], $this->locations());
    }

    public function testAPlaceholderWaitsFromWhenItsMoveWasDoneNotWhenItWasMade(): void
    {
        $this->act('archive');
        $id = $this->claim()[0]['id'];

        $this->report($id, 'done');

        $uid = $this->locations()['Archive'] ?? '';
        self::assertStringStartsWith('pending:', $uid);
        self::assertNotSame('pending:'.$id, $uid, 'it no longer points at the finished move');
        $stamp = UuidV7::fromString(substr($uid, \strlen('pending:')));
        self::assertInstanceOf(UuidV7::class, $stamp);
        self::assertEqualsWithDelta(time(), $stamp->getDateTime()->getTimestamp(), 5);
    }

    public function testAFlagChangeWaitsForACopyThatIsStillToLand(): void
    {
        $this->act('archive');
        $move = $this->claim()[0]['id'];
        $this->act('markRead');

        $this->report($move, 'done');
        self::assertSame([], $this->claim(), 'the uid of the archived copy is not known yet');

        $this->ingest($this->accountId, 'Archive', '7', '<plan@finance.example>');
        $changes = $this->claim();
        self::assertSame([['folder' => 'Archive', 'uidValidity' => 2, 'remoteUid' => '7']], $changes[0]['locations']);
        self::assertTrue($this->message()['flags']['seen']);
    }

    public function testAResetTargetFolderKeepsTheMoveAndADeletedOneUndoesIt(): void
    {
        $this->act('archive');
        $this->folders($this->accountId, [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => 'Archive', 'displayName' => 'Archive', 'role' => 'archive', 'uidValidity' => 20],
            ['remoteName' => 'Trash', 'displayName' => 'Trash', 'role' => 'trash', 'uidValidity' => 3],
            ['remoteName' => 'Projects', 'displayName' => 'Projects', 'role' => 'custom', 'uidValidity' => 4],
        ]);
        self::assertSame([$this->folderId('Archive')], $this->message()['folderIds'], 'the move survives a reset of its folder');
        self::assertSame(1, $this->pendingChanges());

        $this->folders($this->accountId, [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => 'Trash', 'displayName' => 'Trash', 'role' => 'trash', 'uidValidity' => 3],
        ]);
        self::assertSame(['INBOX' => '1'], $this->locations(), 'with its folder gone, the message is back where it was');
        self::assertSame(0, $this->pendingChanges());
    }

    public function testUndoingAFlagChangeLeavesWhatALaterChangeSets(): void
    {
        $this->act('markRead');
        $this->act('markUnread');
        $this->act('markRead');

        $this->report($this->claim()[0]['id'], 'failed', error: 'NO Permission denied');

        self::assertTrue($this->message()['flags']['seen'], 'the last read still stands');
    }

    public function testArchivingWithoutAnArchiveFolderUsesAllMailAndOtherwiseExplainsWhy(): void
    {
        $gmail = $this->createAccount('kim@gmail.example', $this->kim);
        $this->lease();
        $this->folders($gmail, [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => '[Gmail]/All Mail', 'displayName' => 'All Mail', 'role' => 'all', 'uidValidity' => 5],
        ]);
        $this->ingest($gmail, 'INBOX', '3', '<gmail@finance.example>');
        $gmailMessage = $this->message('<gmail@finance.example>')['id'];

        $this->act('archive', ids: [$gmailMessage]);
        self::assertResponseIsSuccessful();

        $bare = $this->createAccount('kim@bare.example', $this->kim);
        $this->lease();
        $this->folders($bare, [['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1]]);
        $this->ingest($bare, 'INBOX', '4', '<bare@finance.example>');

        $this->act('archive', ids: [$this->messageId, $this->message('<bare@finance.example>')['id']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('action: kim@bare.example has no Archive folder.', $this->decodedResponse()['detail'] ?? null);
        self::assertSame([$this->folderId('INBOX')], $this->message()['folderIds'], 'a refused selection changes nothing');
    }

    public function testArchivingOnTheGmailApiLeavesTheCopyAlreadyInAllMail(): void
    {
        // The Gmail API syncer files every message in the virtual All Mail folder too, under the same message id.
        $this->accountId = $this->createAccount('kim@gmail.example', $this->kim);
        $this->connection()->executeStatement("UPDATE mail_account SET provider = 'gmail', connection = '{}' WHERE id = :id", ['id' => $this->accountId]);
        $this->lease();
        $this->folders($this->accountId, [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => null],
            ['remoteName' => 'tresso:all', 'displayName' => 'All Mail', 'role' => 'all', 'uidValidity' => null],
        ]);
        $this->ingest($this->accountId, 'INBOX', '18f2a', '<gmail@finance.example>');
        $this->ingest($this->accountId, 'tresso:all', '18f2a', '<gmail@finance.example>');
        $this->messageId = $this->message('<gmail@finance.example>')['id'];

        $this->act('archive');
        $changes = $this->claim();
        self::assertSame(['folder' => 'INBOX', 'uidValidity' => null, 'remoteUid' => '18f2a'], $changes[0]['source'] ?? null);
        self::assertSame(['folder' => 'tresso:all'], $changes[0]['target'] ?? null);

        $this->report($changes[0]['id'], 'done', '18f2a');

        self::assertSame(['tresso:all' => '18f2a'], $this->locations(), 'one copy, the one All Mail already had');
        self::assertSame([$this->folderId('tresso:all')], $this->message('<gmail@finance.example>')['folderIds']);
    }

    public function testAMoveOnAnImapAccountReportsANumericUid(): void
    {
        $this->act('archive');
        $change = $this->claim()[0];

        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/mailbox-changes/'.$change['id'].'/report', ['workerId' => 'w1', 'outcome' => 'done', 'remoteUid' => '18f2a'], $this->worker);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('An IMAP uid is a number.', $this->decodedResponse()['detail'] ?? null);
    }

    public function testTheStoredUidListingLeavesOutCopiesStillOnTheirWay(): void
    {
        $this->act('move', $this->folderId('Projects'));
        self::assertSame(['Projects'], array_keys($this->locations()));

        $this->client->request('GET', '/internal/sync/accounts/'.$this->accountId.'/folders/remote-uids?'.http_build_query(['workerId' => 'w1', 'folder' => 'Projects']), server: $this->worker);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decodedResponse()['remoteUids'] ?? null, 'a resync must not take the placeholder for a deleted message');
    }

    public function testAMoveCanTakeOnlyTheCopiesInOneFolder(): void
    {
        $this->ingest($this->accountId, 'Projects', '3', '<plan@finance.example>');

        $this->client->jsonRequest('POST', '/api/v1/message-actions', ['messageIds' => [$this->messageId], 'action' => 'move', 'folderId' => $this->folderId('Archive'), 'fromFolderId' => $this->folderId('INBOX')], $this->kim);
        self::assertResponseIsSuccessful();

        $folders = $this->message()['folderIds'];
        sort($folders);
        $expected = [$this->folderId('Archive'), $this->folderId('Projects')];
        sort($expected);
        self::assertSame($expected, $folders, 'the copy in Projects stays');
        self::assertSame(['folder' => 'INBOX', 'uidValidity' => 1, 'remoteUid' => '1'], $this->claim()[0]['source']);

        $this->client->jsonRequest('POST', '/api/v1/message-actions', ['messageIds' => [$this->messageId], 'action' => 'flag', 'fromFolderId' => $this->folderId('INBOX')], $this->kim);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRefersOnlyToTheUsersOwnMessagesAndFolders(): void
    {
        $lisa = $this->userHeaders('lisa@example.test', ['mail:read', 'mail:write', 'settings:write']);
        $this->client->jsonRequest('POST', '/api/v1/message-actions', ['messageIds' => [$this->messageId], 'action' => 'markRead'], $lisa);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('messageIds[0]: This message does not exist.', $this->decodedResponse()['detail'] ?? null);

        $lisaAccount = $this->createAccount('lisa@company.example', $lisa);
        $this->lease();
        $this->folders($lisaAccount, [['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1]]);
        $this->act('move', $this->folderIdOf($lisaAccount, 'INBOX'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('folderId: This folder does not exist.', $this->decodedResponse()['detail'] ?? null);
    }

    public function testValidatesTheRequest(): void
    {
        $this->act('move');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('folderId: Choose the folder to move to.', $this->decodedResponse()['detail'] ?? null);

        $this->act('flag', $this->folderId('Projects'));
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/api/v1/message-actions', ['messageIds' => [], 'action' => 'flag'], $this->kim);
        self::assertResponseStatusCodeSame(422);

        $other = $this->createAccount('kim@home.example', $this->kim);
        $this->lease();
        $this->folders($other, [['remoteName' => 'Work', 'displayName' => 'Work', 'role' => 'custom', 'uidValidity' => 1]]);
        $this->act('move', $this->folderIdOf($other, 'Work'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('folderId: Messages can only move to a folder of their own account.', $this->decodedResponse()['detail'] ?? null);
    }

    public function testRequiresTheWriteScope(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/message-actions', ['messageIds' => [$this->messageId], 'action' => 'markRead'], $this->userHeaders('kim@example.test', ['mail:read']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyTheLeasingWorkerTakesAndReportsChanges(): void
    {
        $this->act('markRead');
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/mailbox-changes/claim', ['workerId' => 'w2'], $this->worker);
        self::assertResponseStatusCodeSame(404);

        $id = $this->claim()[0]['id'];
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/mailbox-changes/'.$id.'/report', ['workerId' => 'w1', 'outcome' => 'done', 'remoteUid' => 'abc'], $this->worker);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param list<string>|null $ids
     */
    private function act(string $action, ?string $folderId = null, ?array $ids = null): void
    {
        $this->client->jsonRequest('POST', '/api/v1/message-actions', array_filter(['messageIds' => $ids ?? [$this->messageId], 'action' => $action, 'folderId' => $folderId]), $this->kim);
    }

    /**
     * @return list<array{id: string, kind: string, seen: ?bool, flagged: ?bool, locations: list<array<string, mixed>>, source: ?array<string, mixed>, target: ?array<string, mixed>}>
     */
    private function claim(): array
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/mailbox-changes/claim', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $changes = $this->decodedResponse()['changes'] ?? null;
        self::assertIsArray($changes);

        /** @var list<array{id: string, kind: string, seen: ?bool, flagged: ?bool, locations: list<array<string, mixed>>, source: ?array<string, mixed>, target: ?array<string, mixed>}> */
        return array_values($changes);
    }

    private function report(string $id, string $outcome, ?string $remoteUid = null, ?string $error = null): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/mailbox-changes/'.$id.'/report', array_filter(['workerId' => 'w1', 'outcome' => $outcome, 'remoteUid' => $remoteUid, 'error' => $error]), $this->worker);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return list<string> the accounts the heartbeat says have work
     */
    private function lease(): array
    {
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $outbox = $this->decodedResponse()['outbox'] ?? null;
        self::assertIsArray($outbox);

        /** @var list<string> */
        return array_values($outbox);
    }

    /**
     * @param list<array<string, mixed>> $folders
     */
    private function folders(string $accountId, array $folders): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$accountId.'/folders', ['workerId' => 'w1', 'folders' => $folders], $this->worker);
        self::assertResponseIsSuccessful();
    }

    private function ingest(string $accountId, string $folder, string $uid, string $messageId): void
    {
        $sha = hash('sha256', $messageId);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$accountId.'/messages', ['workerId' => 'w1', 'folder' => $folder, 'messages' => [[
            'remoteUid' => $uid,
            'rawObjectKey' => 'raw/'.substr($sha, 0, 2).'/'.$sha,
            'size' => 100,
            'receivedAt' => '2026-10-19T08:00:00+00:00',
            'messageId' => $messageId,
            'subject' => 'Offsite plan',
            'from' => ['address' => 'anna@finance.example', 'name' => 'Anna'],
            'to' => [['address' => 'kim@company.example']],
            'snippet' => 'Plan',
            'flags' => ['seen' => false, 'flagged' => false, 'answered' => false, 'draft' => false],
        ]]], $this->worker);
        self::assertResponseIsSuccessful();
    }

    private function flagChange(string $folder, string $uid, bool $seen, bool $flagged, bool $answered): void
    {
        $this->changesBatch($folder, ['flags' => [['remoteUid' => $uid, 'modSeq' => 20, 'flags' => ['seen' => $seen, 'flagged' => $flagged, 'answered' => $answered, 'draft' => false]]]]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function changesBatch(string $folder, array $changes): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/changes', ['workerId' => 'w1', 'folder' => $folder] + $changes, $this->worker);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array{id: string, folderIds: list<string>, flags: array{seen: bool, flagged: bool, answered: bool, draft: bool}}
     */
    private function message(string $messageIdHeader = '<plan@finance.example>'): array
    {
        $id = $this->connection()->fetchOne('SELECT id FROM message WHERE message_id_header = :header', ['header' => trim($messageIdHeader, '<>')]);
        self::assertIsString($id);
        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        foreach ((array) ($this->decodedResponse()['messages'] ?? []) as $message) {
            if (\is_array($message) && ($message['id'] ?? null) === $id) {
                self::assertIsArray($message['folderIds']);
                $folderIds = $message['folderIds'];
                sort($folderIds);
                $message['folderIds'] = $folderIds;

                /** @var array{id: string, folderIds: list<string>, flags: array{seen: bool, flagged: bool, answered: bool, draft: bool}} */
                return $message;
            }
        }
        self::fail('The message is not in the delta.');
    }

    /**
     * @return array<string, string> folder name => remote uid of each copy of the message
     */
    private function locations(): array
    {
        /** @var array<string, string> */
        return $this->connection()->fetchAllKeyValue(
            'SELECT f.remote_name, l.remote_uid FROM message_location l JOIN folder f ON f.id = l.folder_id WHERE l.message_id = :id ORDER BY f.remote_name',
            ['id' => $this->messageId],
        );
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

    private function pendingChanges(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM mailbox_change');
        self::assertIsInt($count);

        return $count;
    }

    /**
     * A minute back, not a second: times are stored to the second, and a test that runs fast would otherwise find the
     * claim ending in the very second it asks again, so still in progress.
     */
    private function makeClaimsExpire(): void
    {
        $this->connection()->executeStatement("UPDATE mailbox_change SET claimed_until = now() - interval '1 minute' WHERE claimed_until IS NOT NULL");
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function makeDue(): void
    {
        $this->connection()->executeStatement("UPDATE mailbox_change SET not_before = now() - interval '1 minute'");
        static::getContainer()->get(EntityManagerInterface::class)->clear();
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
