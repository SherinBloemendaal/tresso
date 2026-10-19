<?php

declare(strict_types=1);

namespace App\Sync\Drafts;

final readonly class DraftWorkResult
{
    public function __construct(public bool $accepted)
    {
    }
}
