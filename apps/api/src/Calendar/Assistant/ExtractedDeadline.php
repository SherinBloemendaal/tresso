<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * A deadline found in a message ("send the slides by Friday"), to offer as a todo.
 */
final readonly class ExtractedDeadline
{
    public function __construct(
        public string $title,
        public \DateTimeImmutable $dueAt,
    ) {
    }
}
