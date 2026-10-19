<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use App\Enum\RuleRole;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A reminder rule as the settings screen saves it: complete.
 */
final class ReminderRuleInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $name = '';

    #[Assert\Uuid]
    public ?string $calendarId = null;

    public ?bool $allDay = null;

    public RuleRole $role = RuleRole::Any;

    public ?bool $withAttendees = null;

    public ?bool $withConference = null;

    public ?bool $withLocation = null;

    /** @var list<RuleReminderInput> */
    #[Assert\Count(max: 10)]
    #[Assert\Valid]
    public array $reminders = [];

    public bool $leaveNow = false;

    #[Assert\Range(min: 0, max: 1440)]
    public ?int $briefMinutes = null;

    public bool $followUp = false;

    #[Assert\PositiveOrZero]
    public int $position = 0;
}
