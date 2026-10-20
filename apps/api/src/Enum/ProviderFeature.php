<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a provider grant is used for. Each feature needs its own scopes at the provider (see
 * {@see \App\ProviderAuth\ProviderScopes}); asking for a feature later adds its scopes to the existing grant
 * (incremental consent) instead of starting a new one.
 */
enum ProviderFeature: string
{
    case Mail = 'mail';
    /** The account's calendars, with Google Meet and Teams meetings on their events. */
    case Calendar = 'calendar';

    /** How a sentence names the feature: "the mail", "the calendars". */
    public function label(): string
    {
        return match ($this) {
            self::Mail => 'mail',
            self::Calendar => 'calendars',
        };
    }
}
