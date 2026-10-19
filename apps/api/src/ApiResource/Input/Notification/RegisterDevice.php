<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use App\Enum\DeviceKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A device that wants notifications. Registering the same endpoint again updates it.
 */
final class RegisterDevice
{
    #[Assert\NotNull]
    public ?DeviceKind $kind = null;

    /** Web push: the subscription endpoint (https). Expo: the ExponentPushToken. Desktop: an installation id. */
    #[Assert\NotBlank]
    #[Assert\Length(max: 2048)]
    public string $endpoint = '';

    /** Web push: keys.p256dh of the subscription (base64url). */
    #[Assert\Length(max: 128)]
    #[Assert\Regex('/^[A-Za-z0-9_-]+$/')]
    public ?string $publicKey = null;

    /** Web push: keys.auth of the subscription (base64url). */
    #[Assert\Length(max: 64)]
    #[Assert\Regex('/^[A-Za-z0-9_-]+$/')]
    public ?string $authSecret = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $name = '';
}
