<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use Symfony\Component\Validator\Constraints as Assert;

/** Partial update (JSON merge patch). Omitted or null fields stay as they are. */
final class UpdateNotificationSettings
{
    #[Assert\Timezone]
    public ?string $timeZone = null;

    public ?bool $quietHoursEnabled = null;

    /** @var list<int>|null ISO days (1 = Monday) */
    #[Assert\All([new Assert\Range(min: 1, max: 7)])]
    public ?array $quietDays = null;

    #[Assert\Regex('/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Use HH:MM.')]
    public ?string $quietStart = null;

    #[Assert\Regex('/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Use HH:MM.')]
    public ?string $quietEnd = null;

    public ?bool $focusDuringBusy = null;

    /** Where email notifications go; an empty string goes back to the sign-in address. */
    #[Assert\AtLeastOneOf([new Assert\Blank(), new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)])]
    #[Assert\Length(max: 320)]
    public ?string $emailAddress = null;
}
