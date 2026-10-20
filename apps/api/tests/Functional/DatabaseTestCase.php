<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base for HTTP tests that touch the database. DAMADoctrineTestBundle rolls every test back.
 */
abstract class DatabaseTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * The number a counting query returns.
     *
     * @param array<string, mixed> $parameters
     */
    protected function rowCount(string $sql, array $parameters = []): int
    {
        $count = $this->connection()->fetchOne($sql, $parameters);
        self::assertIsInt($count);

        return $count;
    }
}
