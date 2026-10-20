<?php

declare(strict_types=1);

namespace App\Enum;

use App\Notification\NotificationChannelChoice;

/**
 * The kinds of notification: the calendar's, and new mail that needs the user. The dispatcher and the per-kind channel
 * preferences take any kind name.
 */
enum NotificationKind: string
{
    /** A reminder before (or at) an event. */
    case Reminder = 'reminder';
    /** Time to leave for an event somewhere else. */
    case LeaveNow = 'leave_now';
    /** Who is coming and what was said with them, just before a meeting. */
    case Brief = 'brief';
    /** After a meeting: notes and follow-up. */
    case FollowUp = 'follow_up';
    /** New mail judged `do` or `delegate`, unread in an inbox. */
    case Mail = 'mail';

    /**
     * The kinds whose channels the user chooses per kind (channelsByKind). Reminders carry their own channels, so they
     * are not among them.
     *
     * @return list<string>
     */
    public static function withChosenChannels(): array
    {
        return [self::LeaveNow->value, self::Brief->value, self::FollowUp->value, self::Mail->value];
    }

    /** Where this kind goes unless the user chose otherwise. Reminders carry their own channels. */
    public function defaultChannels(): NotificationChannelChoice
    {
        return match ($this) {
            self::Reminder, self::Mail => new NotificationChannelChoice(NotificationChannel::Phone, NotificationChannel::Desktop),
            self::LeaveNow => new NotificationChannelChoice(NotificationChannel::Phone),
            self::Brief, self::FollowUp => new NotificationChannelChoice(NotificationChannel::Desktop),
        };
    }
}
