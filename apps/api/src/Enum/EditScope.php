<?php

declare(strict_types=1);

namespace App\Enum;

/** Which occurrences of a recurring event an edit or delete applies to. */
enum EditScope: string
{
    case This = 'this';
    case Following = 'following';
    case All = 'all';
}
