<?php

declare(strict_types=1);

namespace App\Calendar\Invitation;

/**
 * What applying calendar mail did: changed the calendar, ignored it (with the reason), or found no calendar to write
 * to (with the reason); the last leaves the mail unapplied, so it can be applied once there is one.
 */
final readonly class InvitationOutcome
{
    public const string APPLIED = 'applied';
    public const string IGNORED = 'ignored';
    public const string NO_TARGET = 'no_target';

    private function __construct(
        public string $status,
        public ?string $reason,
    ) {
    }

    public static function applied(): self
    {
        return new self(self::APPLIED, null);
    }

    public static function ignored(string $reason): self
    {
        return new self(self::IGNORED, $reason);
    }

    public static function noTarget(string $reason): self
    {
        return new self(self::NO_TARGET, $reason);
    }
}
