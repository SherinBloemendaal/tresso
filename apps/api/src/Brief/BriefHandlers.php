<?php

declare(strict_types=1);

namespace App\Brief;

use App\Repository\DailyBriefSettingsRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the brief sweep, the settings backfill and the delivery of briefs the way the messenger worker receives them.
 */
final readonly class BriefHandlers
{
    /** How long one backfill run may take, so it never holds the worker up for long. */
    public const int BACKFILL_BUDGET_SECONDS = 30;

    public function __construct(
        private DailyBriefs $briefs,
        private DailyBriefSettingsRepository $settings,
        private DailyBriefDelivery $delivery,
        private ClockInterface $clock,
        private int $backfillBatch = DailyBriefSettingsRepository::BACKFILL_BATCH,
    ) {
    }

    #[AsMessageHandler]
    public function sweep(SweepDailyBriefs $message): void
    {
        $this->briefs->sweep($this->clock->now());
    }

    /**
     * Batches (of {@see DailyBriefSettingsRepository::BACKFILL_BATCH} users) until every user has settings or the time budget
     * is spent; the next hour goes on from there.
     */
    #[AsMessageHandler]
    public function createMissingSettings(CreateMissingBriefSettings $message): void
    {
        $deadline = hrtime(true) + self::BACKFILL_BUDGET_SECONDS * 1_000_000_000;
        do {
            $found = $this->settings->createMissing($this->clock->now(), limit: $this->backfillBatch);
        } while ($this->backfillBatch === $found && hrtime(true) < $deadline);
    }

    #[AsMessageHandler]
    public function deliver(DeliverDailyBrief $message): void
    {
        $this->delivery->deliver($message->briefId, $this->clock->now());
    }
}
