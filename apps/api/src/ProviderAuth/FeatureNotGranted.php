<?php

declare(strict_types=1);

namespace App\ProviderAuth;

/**
 * The grant does not cover a feature the user can allow later (calendars): the account's other features go on.
 */
final class FeatureNotGranted extends \RuntimeException
{
}
