<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Brief;

use App\Enum\NotificationChannel;
use Symfony\Component\Validator\Constraints as Assert;

final class DailyBriefSettingsInput
{
    #[Assert\NotNull]
    public ?bool $enabled = null;

    #[Assert\NotBlank]
    #[Assert\Regex('/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Use a time of day as HH:MM.')]
    public ?string $deliverAt = null;

    /**
     * Where the brief goes besides the app; stored as the notification preferences' channels for kind `daily_brief`.
     *
     * @var list<string>|null
     */
    #[Assert\NotNull]
    #[Assert\Unique]
    #[Assert\All([new Assert\Choice(callback: [NotificationChannel::class, 'values'], message: 'Channels are phone, desktop and email.')])]
    public ?array $channels = null;
}
