<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partial update (JSON merge patch). Omitted or null fields stay as they are; the name can only change for local
 * calendars, the rest are the user's own settings and never go to the provider.
 */
final class UpdateCalendar
{
    #[Assert\Length(min: 1, max: 255)]
    public ?string $name = null;

    #[Assert\Regex('/^#[0-9a-fA-F]{6}$/')]
    public ?string $color = null;

    public ?bool $visible = null;

    /** Makes this the calendar where new events and accepted invitations of its account (or of Tresso) go. */
    public ?bool $isDefault = null;
}
