<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a provider grant is used for. Each feature needs its own scopes at the provider (see
 * {@see \App\ProviderAuth\ProviderScopes}); asking for a feature later adds its scopes to the existing grant
 * (incremental consent) instead of starting a new one.
 *
 * Calendars (Google Calendar, Microsoft Graph calendars) and online meetings (Teams, Meet) are added here as new cases
 * with their scopes once the calendar is built.
 */
enum ProviderFeature: string
{
    case Mail = 'mail';
}
