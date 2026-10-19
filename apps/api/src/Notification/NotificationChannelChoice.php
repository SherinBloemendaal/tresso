<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\NotificationChannel;

/**
 * The channels one notification goes out on. Empty means it is not sent at all.
 */
final readonly class NotificationChannelChoice
{
    /** @var list<NotificationChannel> */
    public array $channels;

    public function __construct(NotificationChannel ...$channels)
    {
        $unique = [];
        foreach ($channels as $channel) {
            $unique[$channel->value] = $channel;
        }
        // The order of the enum, so equal choices serialize alike.
        $this->channels = array_values(array_filter(NotificationChannel::cases(), static fn (NotificationChannel $c): bool => isset($unique[$c->value])));
    }

    /**
     * Reads stored channel names; unknown ones are left out.
     *
     * @param list<mixed> $values
     */
    public static function fromValues(array $values): self
    {
        return new self(...array_values(array_filter(array_map(static fn (mixed $value): ?NotificationChannel => \is_string($value) ? NotificationChannel::tryFrom($value) : null, $values))));
    }

    public function includes(NotificationChannel $channel): bool
    {
        return \in_array($channel, $this->channels, true);
    }

    public function isEmpty(): bool
    {
        return [] === $this->channels;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(static fn (NotificationChannel $channel): string => $channel->value, $this->channels);
    }
}
