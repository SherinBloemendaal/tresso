<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A new local calendar (in Tresso only, not at a provider).
 */
final class CreateCalendar
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\Regex('/^#[0-9a-fA-F]{6}$/')]
    public ?string $color = null;
}
