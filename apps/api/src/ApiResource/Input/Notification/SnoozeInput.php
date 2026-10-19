<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use Symfony\Component\Validator\Constraints as Assert;

final class SnoozeInput
{
    #[Assert\Range(min: 1, max: 1440)]
    public int $minutes = 5;
}
