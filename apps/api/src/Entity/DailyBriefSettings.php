<?php

declare(strict_types=1);

namespace App\Entity;

use App\Brief\BriefTime;
use App\Repository\DailyBriefSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Whether and when the user gets their daily brief of `know` mail. The time is read in the user's notification time
 * zone, and where the brief goes besides the app follows their notification preferences (kind `daily_brief`). Every
 * user has one, made with the user (older users get theirs from an hourly backfill).
 */
#[ORM\Entity(repositoryClass: DailyBriefSettingsRepository::class)]
#[ORM\Index(name: 'daily_brief_settings_due', columns: ['next_run_at'], options: ['where' => '(enabled = true)'])]
class DailyBriefSettings
{
    public const bool DEFAULT_ENABLED = true;
    public const string DEFAULT_DELIVER_AT = '08:00';

    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private bool $enabled = self::DEFAULT_ENABLED;

    /** HH:MM in the user's notification time zone. */
    #[ORM\Column(length: 5)]
    private string $deliverAt = self::DEFAULT_DELIVER_AT;

    /** The next brief is made at this moment; it moves on after each run and when the time or the zone changes. */
    #[ORM\Column]
    private \DateTimeImmutable $nextRunAt;

    /** The last brief covered mail classified up to here; null before the first run. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $coveredUntil = null;

    public function __construct(User $user, string $timeZone, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->nextRunAt = BriefTime::nextRun($this->deliverAt, $timeZone, $now);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getDeliverAt(): string
    {
        return $this->deliverAt;
    }

    public function getNextRunAt(): \DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function getCoveredUntil(): ?\DateTimeImmutable
    {
        return $this->coveredUntil;
    }

    public function change(bool $enabled, string $deliverAt, string $timeZone, \DateTimeImmutable $now): void
    {
        $this->enabled = $enabled;
        $this->deliverAt = $deliverAt;
        $this->reschedule($timeZone, $now);
    }

    /** The user's zone changed (or the time did): the next brief comes at the delivery time in that zone. */
    public function reschedule(string $timeZone, \DateTimeImmutable $now): void
    {
        $this->nextRunAt = BriefTime::nextRun($this->deliverAt, $timeZone, $now);
    }

    /**
     * A run made the brief up to $until: the next one covers what comes after, at the next delivery time after $now.
     */
    public function ran(\DateTimeImmutable $until, string $timeZone, \DateTimeImmutable $now): void
    {
        $this->coveredUntil = $until;
        $this->reschedule($timeZone, $now);
    }
}
