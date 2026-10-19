<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One reminder of a rule: minutes before the start, or (all-day events) a time on the day before.
 */
final class RuleReminderInput
{
    #[Assert\Range(min: 0, max: 40320)]
    public ?int $minutesBefore = null;

    #[Assert\Regex('/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Use HH:MM.')]
    public ?string $dayBeforeAt = null;

    /** @var list<string> */
    #[Assert\Count(min: 1, max: 2)]
    #[Assert\All([new Assert\Choice(choices: ['push', 'email'])])]
    public array $channels = ['push'];

    #[Assert\IsTrue(message: 'Give minutesBefore or dayBeforeAt, not both.')]
    public function isOneWhen(): bool
    {
        return (null === $this->minutesBefore) !== (null === $this->dayBeforeAt);
    }

    /**
     * @return array{minutesBefore: ?int, dayBeforeAt: ?string, channels: list<string>}
     */
    public function toArray(): array
    {
        return ['minutesBefore' => $this->minutesBefore, 'dayBeforeAt' => $this->dayBeforeAt, 'channels' => array_values(array_unique($this->channels))];
    }
}
