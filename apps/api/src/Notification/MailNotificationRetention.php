<?php

declare(strict_types=1);

namespace App\Notification;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A mail notification keeps what it says only as long as clients can still get it through the delta (7 days after it
 * was delivered); a dropped one, never shown, as long. After that its title and body are cleared. The row itself stays:
 * it is what makes a message notify at most once.
 */
#[AsMessageHandler]
final readonly class MailNotificationRetention
{
    public const string KEEP = '7 days';
    private const int BATCH = 1000;

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ScrubMailNotifications $message): void
    {
        $this->scrub(new \DateTimeImmutable());
    }

    /** Returns how many notifications were cleared. */
    public function scrub(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify('-'.self::KEEP)->format('Y-m-d H:i:s');
        $cleared = 0;
        do {
            $batch = (int) $this->connection->executeStatement(
                "UPDATE mail_notification SET title = '', body = '', sealed_body = NULL
                 WHERE id IN (
                     SELECT id FROM mail_notification
                     WHERE COALESCE(delivered_at, dropped_at) < :cutoff AND (title <> '' OR body <> '' OR sealed_body IS NOT NULL)
                     LIMIT ".self::BATCH.'
                 )',
                ['cutoff' => $cutoff],
            );
            $cleared += $batch;
        } while (self::BATCH === $batch);

        return $cleared;
    }
}
