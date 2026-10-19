<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationPreferencesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When and how the user wants to be disturbed: quiet hours, focus during busy events, and where email notifications
 * go. Also how far ahead their notifications are planned.
 */
#[ORM\Entity(repositoryClass: NotificationPreferencesRepository::class)]
#[ORM\Index(columns: ['planned_until'])]
class NotificationPreferences
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** IANA zone the quiet hours and all-day reminders are read in. */
    #[ORM\Column(length: 64)]
    private string $timeZone = 'UTC';

    #[ORM\Column]
    private bool $quietHoursEnabled = false;

    /** @var list<int> ISO days (1 = Monday) the quiet hours start on */
    #[ORM\Column(type: Types::JSON)]
    private array $quietDays = [1, 2, 3, 4, 5, 6, 7];

    /** HH:MM; quiet hours past midnight end the next day. */
    #[ORM\Column(length: 5)]
    private string $quietStart = '22:00';

    #[ORM\Column(length: 5)]
    private string $quietEnd = '07:00';

    /** While a busy event runs, only the user's own meetings and leave-now alerts break through. */
    #[ORM\Column]
    private bool $focusDuringBusy = true;

    /** Where email notifications go; the sign-in address when null. */
    #[ORM\Column(length: 320, nullable: true)]
    private ?string $emailAddress = null;

    /** Notifications are planned up to here; the sweep extends it. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $plannedUntil = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTimeZone(): string
    {
        return $this->timeZone;
    }

    public function setTimeZone(string $timeZone): void
    {
        $this->timeZone = $timeZone;
    }

    public function isQuietHoursEnabled(): bool
    {
        return $this->quietHoursEnabled;
    }

    /** @return list<int> */
    public function getQuietDays(): array
    {
        return $this->quietDays;
    }

    public function getQuietStart(): string
    {
        return $this->quietStart;
    }

    public function getQuietEnd(): string
    {
        return $this->quietEnd;
    }

    /**
     * @param list<int> $days
     */
    public function setQuietHours(bool $enabled, array $days, string $start, string $end): void
    {
        $this->quietHoursEnabled = $enabled;
        $this->quietDays = array_values(array_unique($days));
        sort($this->quietDays);
        $this->quietStart = $start;
        $this->quietEnd = $end;
    }

    public function isFocusDuringBusy(): bool
    {
        return $this->focusDuringBusy;
    }

    public function setFocusDuringBusy(bool $focusDuringBusy): void
    {
        $this->focusDuringBusy = $focusDuringBusy;
    }

    public function getEmailAddress(): ?string
    {
        return $this->emailAddress;
    }

    public function setEmailAddress(?string $emailAddress): void
    {
        $this->emailAddress = null === $emailAddress ? null : mb_strtolower($emailAddress);
    }

    public function getPlannedUntil(): ?\DateTimeImmutable
    {
        return $this->plannedUntil;
    }

    public function setPlannedUntil(?\DateTimeImmutable $plannedUntil): void
    {
        $this->plannedUntil = $plannedUntil;
    }
}
