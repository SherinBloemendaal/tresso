<?php

declare(strict_types=1);

namespace App\Notification\Travel;

/**
 * How long it takes to get from a position to a place, for leave-now reminders. The open edition routes with a
 * self-hosted OSRM server and a Nominatim geocoder when they are configured; an add-on may provide another.
 */
interface TravelTimeEstimator
{
    public function isAvailable(): bool;

    /**
     * Minutes from the position to the place, or null when the place cannot be found or reached.
     */
    public function minutes(float $latitude, float $longitude, string $place, \DateTimeImmutable $arriveBy): ?int;
}
