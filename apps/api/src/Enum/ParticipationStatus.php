<?php

declare(strict_types=1);

namespace App\Enum;

/** iCalendar PARTSTAT of an attendee (RFC 5545 3.2.12). */
enum ParticipationStatus: string
{
    case NeedsAction = 'needs-action';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Tentative = 'tentative';
    case Delegated = 'delegated';

    public static function fromICalendar(?string $value): self
    {
        return match (strtoupper(trim((string) $value))) {
            'ACCEPTED' => self::Accepted,
            'DECLINED' => self::Declined,
            'TENTATIVE' => self::Tentative,
            'DELEGATED' => self::Delegated,
            default => self::NeedsAction,
        };
    }

    public function toICalendar(): string
    {
        return strtoupper($this->value);
    }

    /** Words for the subject of a reply ("Accepted: Weekly sync"). */
    public function replyLabel(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Tentative => 'Tentatively accepted',
            self::Delegated => 'Delegated',
            self::NeedsAction => 'No response',
        };
    }
}
