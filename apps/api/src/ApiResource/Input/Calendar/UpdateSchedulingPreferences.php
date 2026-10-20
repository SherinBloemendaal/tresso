<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use App\Calendar\Assistant\SchedulingRules;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partial update (JSON merge patch). Omitted or null fields stay as they are.
 */
final class UpdateSchedulingPreferences
{
    #[Assert\Range(min: 0, max: SchedulingRules::MAX_BUFFER_MINUTES)]
    public ?int $bufferMinutes = null;

    #[Assert\Range(min: 0, max: SchedulingRules::MAX_TRAVEL_MINUTES)]
    public ?int $travelMinutes = null;

    #[Assert\Range(min: 0, max: SchedulingRules::MAX_NOTICE_MINUTES)]
    public ?int $minimumNoticeMinutes = null;

    public ?bool $planTodos = null;
}
