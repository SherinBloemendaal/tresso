<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProviderAuth;

use App\Enum\OAuthProvider;
use App\Enum\ProviderFeature;
use App\ProviderAuth\ProviderScopes;
use PHPUnit\Framework\TestCase;

final class ProviderScopesTest extends TestCase
{
    public function testMicrosoftScopesCompareWithoutTheGraphPrefixAndCase(): void
    {
        $long = ProviderScopes::normalize(OAuthProvider::Microsoft, 'https://graph.microsoft.com/Mail.ReadWrite https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read');
        $short = ProviderScopes::normalize(OAuthProvider::Microsoft, 'User.Read mail.send  Mail.ReadWrite');

        self::assertSame(['mail.readwrite', 'mail.send', 'user.read'], $long);
        self::assertSame($long, $short);
        self::assertSame($long, ProviderScopes::required(OAuthProvider::Microsoft, ProviderFeature::Mail));
    }

    public function testGoogleScopesAreKeptAsReturned(): void
    {
        self::assertSame(
            ['https://www.googleapis.com/auth/gmail.modify', 'openid'],
            ProviderScopes::normalize(OAuthProvider::Google, 'openid https://www.googleapis.com/auth/gmail.modify openid'),
        );
    }

    public function testRequestsCarryTheIdentityScopesAndTheFullGraphNames(): void
    {
        self::assertSame(['openid', 'email', 'profile', 'https://www.googleapis.com/auth/gmail.modify'], ProviderScopes::request(OAuthProvider::Google, [ProviderFeature::Mail]));
        self::assertSame(
            ['openid', 'email', 'profile', 'offline_access', 'https://graph.microsoft.com/Mail.ReadWrite', 'https://graph.microsoft.com/Mail.Send', 'https://graph.microsoft.com/User.Read'],
            ProviderScopes::request(OAuthProvider::Microsoft, [ProviderFeature::Mail, ProviderFeature::Mail]),
        );
    }

    public function testCoveredFeaturesNeedEveryScopeOfTheFeature(): void
    {
        self::assertSame([ProviderFeature::Mail], ProviderScopes::coveredFeatures(OAuthProvider::Microsoft, ['mail.readwrite', 'mail.send', 'openid', 'user.read']));
        self::assertSame([], ProviderScopes::coveredFeatures(OAuthProvider::Microsoft, ['mail.readwrite', 'user.read']));
    }
}
