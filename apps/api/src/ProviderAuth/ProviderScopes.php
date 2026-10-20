<?php

declare(strict_types=1);

namespace App\ProviderAuth;

use App\Enum\OAuthProvider;
use App\Enum\ProviderFeature;

/**
 * The scopes Tresso asks each provider for, per feature.
 *
 * Scopes are compared in normalized form: Google's as the token endpoint returns them, Microsoft's lowercased and
 * without the "https://graph.microsoft.com/" resource prefix (the token endpoint returns either form).
 *
 * Adding a feature means adding a {@see ProviderFeature} case and its scopes in {@see self::featureScopes()}. A
 * sign-in for the new feature then asks for the scopes of every feature the grant already covers plus the new ones,
 * so the refresh token keeps working for all of them (incremental consent).
 */
final class ProviderScopes
{
    private const string GRAPH_RESOURCE = 'https://graph.microsoft.com/';

    /**
     * Scopes for signing in and reading who signed in. Microsoft's offline_access is what returns a refresh token;
     * Google returns one for access_type=offline instead.
     *
     * @return list<string>
     */
    public static function identityScopes(OAuthProvider $provider): array
    {
        return match ($provider) {
            OAuthProvider::Google => ['openid', 'email', 'profile'],
            OAuthProvider::Microsoft => ['openid', 'email', 'profile', 'offline_access'],
        };
    }

    /**
     * Scopes a feature needs, as the provider documents them.
     *
     * @return list<string>
     */
    public static function featureScopes(OAuthProvider $provider, ProviderFeature $feature): array
    {
        return match ([$provider, $feature]) {
            // Read, label, delete and send mail through the Gmail API (a restricted scope: see docs/ARCHITECTURE.md).
            [OAuthProvider::Google, ProviderFeature::Mail] => ['https://www.googleapis.com/auth/gmail.modify'],
            // Read and change mail, send it, and read the signed-in user's address (/me).
            [OAuthProvider::Microsoft, ProviderFeature::Mail] => ['Mail.ReadWrite', 'Mail.Send', 'User.Read'],
            // Read and change events (Google Meet meetings included) and see the calendar list; nothing broader, such
            // as sharing settings or other users' calendars.
            [OAuthProvider::Google, ProviderFeature::Calendar] => ['https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.calendarlist.readonly'],
            // Read and change the user's calendars and answer invitations. Teams meetings are made through an event's
            // isOnlineMeeting, which needs no OnlineMeetings permission.
            [OAuthProvider::Microsoft, ProviderFeature::Calendar] => ['Calendars.ReadWrite'],
        };
    }

    /**
     * Normalized scopes a grant must hold for the feature.
     *
     * @return list<string>
     */
    public static function required(OAuthProvider $provider, ProviderFeature $feature): array
    {
        return self::normalizeList($provider, self::featureScopes($provider, $feature));
    }

    /**
     * Scopes to send to the authorization endpoint for these features.
     *
     * @param list<ProviderFeature> $features
     *
     * @return list<string>
     */
    public static function request(OAuthProvider $provider, array $features): array
    {
        $scopes = self::identityScopes($provider);
        foreach ($features as $feature) {
            foreach (self::featureScopes($provider, $feature) as $scope) {
                $scopes[] = OAuthProvider::Microsoft === $provider ? self::GRAPH_RESOURCE.$scope : $scope;
            }
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Features whose scopes the granted scopes all include.
     *
     * @param list<string> $granted normalized
     *
     * @return list<ProviderFeature>
     */
    public static function coveredFeatures(OAuthProvider $provider, array $granted): array
    {
        $covered = [];
        foreach (ProviderFeature::cases() as $feature) {
            if ([] === array_diff(self::required($provider, $feature), $granted)) {
                $covered[] = $feature;
            }
        }

        return $covered;
    }

    /**
     * Normalizes a space-separated scope string from a token response.
     *
     * @return list<string>
     */
    public static function normalize(OAuthProvider $provider, string $scope): array
    {
        return self::normalizeList($provider, preg_split('/\s+/', trim($scope), -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param list<string> $scopes
     *
     * @return list<string>
     */
    private static function normalizeList(OAuthProvider $provider, array $scopes): array
    {
        if (OAuthProvider::Microsoft === $provider) {
            $scopes = array_map(
                static fn (string $scope): string => strtolower(str_starts_with(strtolower($scope), self::GRAPH_RESOURCE) ? substr($scope, \strlen(self::GRAPH_RESOURCE)) : $scope),
                $scopes,
            );
        }
        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        return $scopes;
    }
}
