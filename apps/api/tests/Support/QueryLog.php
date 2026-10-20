<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Records the SQL the app sends, in order, so a test can check which statement came first. The record is static:
 * DoctrineBundle wraps each connection in its own copy of a middleware, so the service a test gets is another object.
 */
final class QueryLog implements Middleware
{
    /** @var list<string> */
    private static array $queries = [];

    public function wrap(Driver $driver): Driver
    {
        $log = $this;

        return new class($driver, $log) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryLog $log)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $log = $this->log;

                return new class(parent::connect($params), $log) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly QueryLog $log)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->log->record($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->log->record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->log->record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public function record(string $sql): void
    {
        self::$queries[] = $sql;
    }

    /** @return list<string> */
    public function queries(): array
    {
        return self::$queries;
    }

    public function clear(): void
    {
        self::$queries = [];
    }
}
