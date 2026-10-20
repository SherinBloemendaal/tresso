<?php

declare(strict_types=1);

namespace App\Account;

use App\Search\MessageIndex;
use App\Storage\BlobKey;
use App\Storage\BlobKind;
use App\Storage\ObjectStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes a deleted user's search documents and objects. Storage or search errors make Messenger retry; what was
 * deleted stays deleted, so a retry only does what is left.
 */
#[AsMessageHandler]
final readonly class PurgeDeletedUserHandler
{
    public function __construct(
        private MessageIndex $index,
        private ObjectStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeDeletedUser $message): void
    {
        $owner = Uuid::fromString($message->userId);
        $this->index->deleteOwnedBy($owner);

        $deleted = 0;
        foreach (BlobKind::cases() as $kind) {
            foreach ($this->storage->list(BlobKey::ownerPrefix($kind, $owner)) as $key) {
                $this->storage->delete($key);
                ++$deleted;
            }
        }
        $this->logger->info('Purged {count} objects of deleted user {user}.', ['count' => $deleted, 'user' => $message->userId]);
    }
}
