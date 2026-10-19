<?php

declare(strict_types=1);

namespace App\Realtime;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Told when the outermost database transaction ends, by {@see Doctrine\TransactionTrackingMiddleware}.
 */
#[AutoconfigureTag(self::TAG)]
interface TransactionListener
{
    public const string TAG = 'app.transaction_listener';

    public function transactionCommitted(): void;

    public function transactionRolledBack(): void;
}
