<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

final class FlagsInput
{
    public bool $seen = false;
    public bool $flagged = false;
    public bool $answered = false;
    public bool $draft = false;
}
