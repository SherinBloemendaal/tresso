<?php

declare(strict_types=1);

namespace App\Enum;

enum ScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';

    /**
     * One status for several attachments: infected wins, then pending, then failed; clean only when all are.
     *
     * @param list<self> $statuses
     */
    public static function summarize(array $statuses): ?self
    {
        foreach ([self::Infected, self::Pending, self::Failed, self::Clean] as $status) {
            if (\in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return null;
    }
}
