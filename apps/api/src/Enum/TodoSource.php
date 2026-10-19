<?php

declare(strict_types=1);

namespace App\Enum;

enum TodoSource: string
{
    /** Created by the user from scratch. */
    case Manual = 'manual';
    /** Suggested by the AI and accepted by the user. */
    case Suggested = 'suggested';
    /** Created automatically by a category rule. */
    case Automatic = 'automatic';
}
