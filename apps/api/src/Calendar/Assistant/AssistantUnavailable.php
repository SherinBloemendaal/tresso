<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/** The feature is not part of this edition; `GET /api/v1/calendar/capabilities` says so beforehand. */
final class AssistantUnavailable extends \RuntimeException
{
}
