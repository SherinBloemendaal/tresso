<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use Symfony\Component\Validator\Constraints as Assert;

final class MeetingAttendeeInput
{
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 320)]
    public string $address = '';

    /** An optional attendee's busy time ranks a slot lower instead of ruling it out. */
    public bool $optional = false;
}
