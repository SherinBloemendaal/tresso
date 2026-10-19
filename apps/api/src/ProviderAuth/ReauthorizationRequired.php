<?php

declare(strict_types=1);

namespace App\ProviderAuth;

/**
 * The grant can no longer be used: the provider refused it, or it lacks the scopes the feature needs. The linked mail
 * accounts were marked as failed sign-ins; the user has to sign in with the provider again.
 */
final class ReauthorizationRequired extends \RuntimeException
{
}
