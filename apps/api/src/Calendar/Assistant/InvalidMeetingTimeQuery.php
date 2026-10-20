<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * A query the finder cannot run, with the field that is wrong, so the API and the todo planner can say which.
 */
final class InvalidMeetingTimeQuery extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        /** from, to, durationMinutes, stepMinutes, limit or perDay. */
        public readonly string $field,
    ) {
        parent::__construct($message);
    }
}
