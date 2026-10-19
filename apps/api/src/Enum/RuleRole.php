<?php

declare(strict_types=1);

namespace App\Enum;

/** Which of the user's events a reminder rule is about, by their part in it. */
enum RuleRole: string
{
    case Any = 'any';
    /** The user organizes it (or nobody is invited). */
    case Organized = 'organized';
    /** The user is invited and did not decline. */
    case Invited = 'invited';
}
