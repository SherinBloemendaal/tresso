<?php

declare(strict_types=1);

namespace App\Enum;

/** How a notification reaches the user. */
enum NotificationChannel: string
{
    /** The phone app, through Expo push. */
    case Phone = 'phone';
    /** The desktop app and browsers with web push. */
    case Desktop = 'desktop';
    case Email = 'email';

    /** The channel a device belongs to. */
    public static function ofDevice(DeviceKind $kind): self
    {
        return DeviceKind::Expo === $kind ? self::Phone : self::Desktop;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
