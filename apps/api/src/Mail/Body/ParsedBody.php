<?php

declare(strict_types=1);

namespace App\Mail\Body;

final readonly class ParsedBody
{
    public function __construct(
        public ?string $html,
        public ?string $text,
    ) {
    }
}
