<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use Symfony\Component\Validator\Constraints as Assert;

/** Partial update (JSON merge patch). */
final class UpdateDevice
{
    #[Assert\Length(min: 1, max: 120)]
    public ?string $name = null;

    public ?bool $enabled = null;
}
