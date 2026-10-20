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
    public function __construct(
        private DailyBriefs $briefs,
        private DailyBriefSettingsRepository $settings,
        private DailyBriefDelivery $delivery,
        private ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler]
    public function sweep(SweepDailyBriefs $message): void
    {
        $this->briefs->sweep($this->clock->now());
    }

    #[AsMessageHandler]
    public function createMissingSettings(CreateMissingBriefSettings $message): void
    {
        $this->settings->createMissing($this->clock->now());
    }

    #[AsMessageHandler]
    public function deliver(DeliverDailyBrief $message): void
    {
        $this->delivery->deliver($message->briefId, $this->clock->now());
    }
}
