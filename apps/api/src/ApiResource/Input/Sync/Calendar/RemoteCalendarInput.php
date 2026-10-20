<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync\Calendar;

use App\Entity\Calendar;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One calendar collection at the provider.
 */
final class RemoteCalendarInput
{
    /** CalDAV collection URL (or the provider's calendar id). */
    #[Assert\NotBlank]
    #[Assert\Length(max: 2048)]
    public string $remoteId = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\Regex('/^#[0-9a-fA-F]{6}$/')]
    public ?string $color = null;

    #[Assert\Timezone]
    public ?string $timeZone = null;

    public bool $writable = true;

    /** The provider's default calendar for new events (CalDAV schedule-default-calendar-URL). */
    public bool $isDefault = false;

    /**
     * Online meetings the provider makes for events of this calendar.
     *
     * @var list<string>
     */
    #[Assert\All([new Assert\Choice(choices: Calendar::CONFERENCE_PROVIDERS)])]
    #[Assert\Unique]
    public array $conferenceProviders = [];
}
