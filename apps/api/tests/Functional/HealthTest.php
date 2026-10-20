<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeVirusScanner;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $_SERVER['ATTACHMENTS_REQUIRE_SCAN'] = $_ENV['ATTACHMENTS_REQUIRE_SCAN'] = '0';
        parent::tearDown();
    }

    public function testReportsDependencies(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","checks":{"database":"ok","redis":"ok","scanner":"ok"}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testScannerTurnedOffIsFineWhenFilesMayBeConfirmed(): void
    {
        $client = static::createClient();
        static::getContainer()->get(FakeVirusScanner::class)->enabled = false;

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"scanner":"off"', (string) $client->getResponse()->getContent());
    }

    public function testRequiredScansWithoutAScannerFailTheCheck(): void
    {
        $_SERVER['ATTACHMENTS_REQUIRE_SCAN'] = $_ENV['ATTACHMENTS_REQUIRE_SCAN'] = '1';
        $client = static::createClient();
        static::getContainer()->get(FakeVirusScanner::class)->enabled = false;

        $client->request('GET', '/health');

        self::assertResponseStatusCodeSame(503);
        self::assertJsonStringEqualsJsonString(
            '{"status":"degraded","checks":{"database":"ok","redis":"ok","scanner":"unconfigured"}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testUnreachableScannerDoesNotFailTheCheck(): void
    {
        $client = static::createClient();
        static::getContainer()->get(FakeVirusScanner::class)->unavailable = true;

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"scanner":"unreachable"', (string) $client->getResponse()->getContent());
    }
}
