<?php

declare(strict_types=1);

namespace App\ProviderAuth;

/**
 * The provider refused the refresh token or the authorization code (invalid_grant): the user revoked access, changed
 * the password, or the token expired. Only signing in again helps.
 */
final class GrantRejected extends \RuntimeException
{
}
