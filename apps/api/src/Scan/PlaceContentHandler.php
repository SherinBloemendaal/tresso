<?php

declare(strict_types=1);

namespace App\Scan;

use App\Entity\Attachment;
use App\Storage\ObjectNotFound;
use App\Storage\ObjectStorage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Moves content to quarantine while any attachment with it is infected, and back to every key an attachment still
 * refers to once none is. Storage errors make Messenger retry; the downloads follow the committed verdict meanwhile.
 */
#[AsMessageHandler]
final readonly class PlaceContentHandler
{
    public function __construct(
        private Connection $connection,
        private ObjectStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PlaceContent $message): void
    {
        // The same lock as recording a verdict: the verdict cannot change while content moves, and two placements of
        // the same content (a quarantine and a release) never interleave.
        $this->connection->transactional(function () use ($message): void {
            $this->connection->executeStatement("SELECT pg_advisory_xact_lock(hashtextextended('scan:' || :sha, 0))", ['sha' => $message->sha256]);
            $this->place($message->sha256);
        });
    }

    private function place(string $sha256): void
    {
        $quarantine = Attachment::quarantineKey($sha256);
        $liveKeys = $this->liveKeys($sha256);

        if ($this->infected($sha256)) {
            foreach ($liveKeys as $key) {
                try {
                    $this->storage->move($key, $quarantine);
                } catch (ObjectNotFound) {
                    continue;
                }
                $this->logger->info('Quarantined {key}.', ['key' => $key]);
            }

            return;
        }

        if ([] === $liveKeys) {
            return;
        }
        try {
            foreach ($liveKeys as $key) {
                $this->storage->copy($quarantine, $key);
            }
        } catch (ObjectNotFound) {
            return;
        }
        $this->storage->delete($quarantine);
        $this->logger->info('Released {sha256} from quarantine.', ['sha256' => $sha256]);
    }

    private function infected(string $sha256): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM attachment WHERE sha256 = :sha AND scan_status = 'infected')
                 OR EXISTS (SELECT 1 FROM draft_attachment WHERE sha256 = :sha AND scan_status = 'infected')",
            ['sha' => $sha256],
        );
    }

    /**
     * Where attachments with this content expect it: the received key, and the upload key of draft uploads.
     *
     * @return list<string>
     */
    private function liveKeys(string $sha256): array
    {
        $keys = $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM attachment WHERE sha256 = :sha)', ['sha' => $sha256])
            ? [Attachment::objectKeyFor($sha256)]
            : [];
        /** @var list<string> $draftKeys */
        $draftKeys = $this->connection->fetchFirstColumn('SELECT DISTINCT object_key FROM draft_attachment WHERE sha256 = :sha', ['sha' => $sha256]);

        return array_values(array_unique([...$keys, ...$draftKeys]));
    }
}
