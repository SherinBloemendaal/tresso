<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class StatementHookConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): Statement
    {
        StatementHook::run($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        StatementHook::run($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        StatementHook::run($sql);

        return parent::exec($sql);
    }
}
