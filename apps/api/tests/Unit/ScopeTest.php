<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\Scope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ScopeTest extends TestCase
{
    public function testEnumMatchesConfiguredScopes(): void
    {
        /** @var array{league_oauth2_server: array{scopes: array{available: list<string>}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/league_oauth2_server.yaml');

        self::assertSame(
            $config['league_oauth2_server']['scopes']['available'],
            array_map(static fn (Scope $scope): string => $scope->value, Scope::cases()),
        );
    }

    public function testRoleConstantsMatchScopes(): void
    {
        self::assertSame(Scope::MailRead->role(), Scope::ROLE_MAIL_READ);
        self::assertSame(Scope::MailSend->role(), Scope::ROLE_MAIL_SEND);
        self::assertSame(Scope::SettingsRead->role(), Scope::ROLE_SETTINGS_READ);
        self::assertSame(Scope::SettingsWrite->role(), Scope::ROLE_SETTINGS_WRITE);
        self::assertSame(Scope::SignaturesRead->role(), Scope::ROLE_SIGNATURES_READ);
        self::assertSame(Scope::SignaturesWrite->role(), Scope::ROLE_SIGNATURES_WRITE);
        self::assertSame(Scope::SyncLease->role(), Scope::ROLE_SYNC_LEASE);
        self::assertSame(Scope::SyncIngest->role(), Scope::ROLE_SYNC_INGEST);
        self::assertSame(Scope::SyncCredentials->role(), Scope::ROLE_SYNC_CREDENTIALS);
    }

    public function testUserAndSyncScopesDoNotOverlap(): void
    {
        self::assertSame([], array_intersect(
            array_map(static fn (Scope $s): string => $s->value, Scope::userScopes()),
            array_map(static fn (Scope $s): string => $s->value, Scope::syncScopes()),
        ));
        self::assertCount(\count(Scope::cases()), [...Scope::userScopes(), ...Scope::syncScopes()]);
    }
}
