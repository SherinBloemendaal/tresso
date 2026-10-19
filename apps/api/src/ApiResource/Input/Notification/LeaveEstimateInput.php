<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The phone's position, for one leave-now estimate. It is used for that estimate only: never stored or logged.
 */
final class LeaveEstimateInput
{
    #[Assert\Range(min: -90, max: 90)]
    public float $latitude = 0.0;

    #[Assert\Range(min: -180, max: 180)]
    public float $longitude = 0.0;

    /** The occurrence of a series; null for a single event or an override. */
    #[Assert\Regex('/^\d{8}(T\d{6}Z)?$/')]
    public ?string $recurrenceId = null;
}
