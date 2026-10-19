<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use Symfony\Component\Validator\Constraints as Assert;

final class LocationInput
{
    #[Assert\Range(min: -90, max: 90)]
    public float $latitude = 0.0;

    #[Assert\Range(min: -180, max: 180)]
    public float $longitude = 0.0;
}
