<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Runs a callback right before the first statement that starts with a given text, once: a way to make something
 * happen between two statements of one transaction, as a concurrent writer could.
 */
final class StatementHook implements Middleware
{
    /** @var array<string, \Closure(): void> */
    private static array $before = [];

    /**
     * @param \Closure(): void $callback
     */
    public static function before(string $sqlPrefix, \Closure $callback): void
    {
        self::$before[$sqlPrefix] = $callback;
    }

    public static function reset(): void
    {
        self::$before = [];
    }

    public static function run(string $sql): void
    {
        $sql = ltrim($sql);
        foreach (self::$before as $prefix => $callback) {
            if (str_starts_with($sql, $prefix)) {
                unset(self::$before[$prefix]);
                $callback();
            }
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new StatementHookConnection(parent::connect($params));
            }
        };
    }
}
