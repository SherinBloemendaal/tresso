<?php

declare(strict_types=1);

namespace App\ProviderAuth;

/**
 * The provider could not be reached or answered with an error that is not about the user's grant. Retrying later
 * may work; the grant stays as it is.
 */
final class ProviderUnavailable extends \RuntimeException
{
}
