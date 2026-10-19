<?php

declare(strict_types=1);

namespace App\Enum;

enum ScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
