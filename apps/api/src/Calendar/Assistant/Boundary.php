<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * One edge of free time and what makes it: "free from 10:15, after the buffer of 'Standup'".
 */
final readonly class Boundary
{
    public function __construct(
        public \DateTimeImmutable $at,
        public BlockKind $kind,
        /** The busy time's or focus block's title, or the attendee's address; '' when there is nothing to name. */
        public string $label = '',
    ) {
    }
}
