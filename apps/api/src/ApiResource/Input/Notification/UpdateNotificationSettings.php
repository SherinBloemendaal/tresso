<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use App\Enum\NotificationChannel;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    /**
     * Channels per kind of notification ("leave_now", "brief", ...): a list of channel names (empty: not sent), or null
     * to go back to the default. Kinds left out stay as they are.
     *
     * @var array<mixed>|null as sent; the callback below checks the shape
     */
    public ?array $channelsByKind = null;

    #[Assert\Callback]
    public function validateChannels(ExecutionContextInterface $context): void
    {
        foreach ($this->channelsByKind ?? [] as $kind => $channels) {
            if (!\is_string($kind) || 1 !== preg_match('/^[a-z][a-z_]{0,31}$/', $kind)) {
                $context->buildViolation('A kind of notification is a short lowercase name.')->atPath('channelsByKind')->addViolation();

                continue;
            }
            if (null === $channels) {
                continue;
            }
            if (!\is_array($channels) || !array_is_list($channels)) {
                $context->buildViolation('Give a list of channels.')->atPath('channelsByKind['.$kind.']')->addViolation();

                continue;
            }
            foreach ($channels as $channel) {
                if (!\is_string($channel) || null === NotificationChannel::tryFrom($channel)) {
                    $context->buildViolation('Channels are phone, desktop and email.')->atPath('channelsByKind['.$kind.']')->addViolation();
                }
            }
        }
    }
}
