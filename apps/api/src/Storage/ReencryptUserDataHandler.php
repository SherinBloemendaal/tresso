<?php

declare(strict_types=1);

namespace App\Storage;

use App\Security\Crypto\UserKeys;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Background re-encryption: after a deploy (data from before encryption) and after a user key rotation, without anyone
 * having to remember the command. Each job works for at most {@see BUDGET} seconds, then queues itself again while
 * work is left; when only the retirement of an older key waits for its grace period, it comes back then. One job per
 * user runs at a time (an advisory lock); a duplicate simply does nothing. Progress is logged per step and
 * `app:crypto:reencrypt --status` counts what is left.
 */
final readonly class ReencryptUserDataHandler
{
    private const int BUDGET = 50;
    private const int BATCH = 200;
    private const int SWEEP = 500;

    public function __construct(
        private Reencryption $reencryption,
        private Connection $connection,
        private MessageBusInterface $bus,
        private UserKeys $keys,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ReencryptUserData $message): void
    {
        $lock = 'reencrypt:'.$message->userId;
        if (!$this->connection->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(:lock, 0))', ['lock' => $lock])) {
            return;
        }
        try {
            if (false === $this->connection->fetchOne('SELECT 1 FROM app_user WHERE id = :id AND keys_shredded_at IS NULL', ['id' => $message->userId])) {
                return;
            }
            $owner = Uuid::fromString($message->userId);
            $result = $this->reencryption->run($owner, self::BATCH, function (string $step, int $moved, int $failed) use ($message): void {
                if ($moved > 0 || $failed > 0) {
                    $this->logger->info('Re-encrypting {user}: {step} {moved} moved, {failed} not.', ['user' => $message->userId, 'step' => $step, 'moved' => $moved, 'failed' => $failed]);
                }
            }, new \DateTimeImmutable('+'.self::BUDGET.' seconds'));
            $this->again($message, $owner, $result);
        } finally {
            $this->connection->fetchOne('SELECT pg_advisory_unlock(hashtextextended(:lock, 0))', ['lock' => $lock]);
        }
    }

    #[AsMessageHandler]
    public function onSweep(SweepReencryption $message): void
    {
        foreach ($this->reencryption->usersWithWork(self::SWEEP) as $userId) {
            $this->bus->dispatch(new ReencryptUserData($userId));
        }
    }

    private function again(ReencryptUserData $message, Uuid $owner, ReencryptionResult $result): void
    {
        if ($result->isComplete()) {
            return;
        }
        // Rows that failed are left to the hourly sweep rather than retried in a loop.
        if ($result->left > $result->failed) {
            $this->bus->dispatch($message);

            return;
        }
        if (0 === $result->left) {
            $retireAt = $this->keys->currentSince($owner)->add(new \DateInterval(UserKeys::RETIRE_GRACE))->modify('+1 minute');
            $this->bus->dispatch($message, [new DelayStamp(max(0, $retireAt->getTimestamp() - time()) * 1000)]);
        }
    }
}
