<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Identity platforms users connect through OAuth: one grant per signed-in provider account, shared by every feature
 * (mail now, calendars and online meetings later) that uses that provider.
 */
enum OAuthProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Microsoft => 'Microsoft',
        };
    }

    /** The mail provider of an account connected through this identity platform. */
    public function mailProvider(): MailProvider
    {
        return match ($this) {
            self::Google => MailProvider::Gmail,
            self::Microsoft => MailProvider::Microsoft,
        };
    }

    public static function forMailProvider(MailProvider $provider): ?self
    {
        return match ($provider) {
            MailProvider::Gmail => self::Google,
            MailProvider::Microsoft => self::Microsoft,
            MailProvider::Imap => null,
        };
    }
}
