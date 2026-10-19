<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Enum\ItipMethod;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\TextPart;

/**
 * text/calendar with the METHOD parameter iMIP requires (RFC 6047 2.4), so mail clients offer to accept or decline.
 */
final class CalendarTextPart extends TextPart
{
    public function __construct(string $ical, private readonly ItipMethod $method)
    {
        parent::__construct($ical, 'utf-8', 'calendar', 'quoted-printable');
    }

    public function getPreparedHeaders(): Headers
    {
        $headers = parent::getPreparedHeaders();
        $headers->setHeaderParameter('Content-Type', 'method', $this->method->value);

        return $headers;
    }
}
