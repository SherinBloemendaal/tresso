<?php

declare(strict_types=1);

namespace App\Enum;

enum BuiltinCategory: string
{
    case Spam = 'spam';
    case Notification = 'notification';
    case Advertise = 'advertise';
    case Todo = 'todo';
    case Informational = 'informational';

    public function defaultVerdict(): Verdict
    {
        return match ($this) {
            self::Todo => Verdict::Do,
            self::Informational, self::Notification, self::Advertise => Verdict::Know,
            self::Spam => Verdict::None,
        };
    }
}
