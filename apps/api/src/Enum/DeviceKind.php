<?php

declare(strict_types=1);

namespace App\Enum;

/** Where a device gets notifications from. */
enum DeviceKind: string
{
    /** A browser or the web app, through its push service (RFC 8030). */
    case WebPush = 'web_push';
    /** The mobile app, through Expo's push service (APNs and FCM). */
    case Expo = 'expo';
    /** The desktop app, through its realtime connection while it runs. */
    case Desktop = 'desktop';
}
