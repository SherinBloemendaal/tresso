<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync\Calendar;

use App\ApiResource\Input\Sync\WorkerRequest;
use App\Enum\CalendarSource;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The complete calendar list of an account. Calendars missing from it are removed.
 */
final class CalendarListInput extends WorkerRequest
{
    #[Assert\Choice(choices: [CalendarSource::CalDav, CalendarSource::Google, CalendarSource::Microsoft])]
    public CalendarSource $source = CalendarSource::CalDav;

    /** The CalDAV URL that worked, kept with the account so discovery is not repeated. */
    #[Assert\Url(protocols: ['https', 'http'])]
    #[Assert\Length(max: 2048)]
    public ?string $serverUrl = null;

    /** The server sends invitations and replies itself (RFC 6638 calendar-auto-schedule). */
    public bool $providerSchedules = false;

    /** @var list<RemoteCalendarInput> */
    #[Assert\Count(max: 500)]
    #[Assert\Valid]
    public array $calendars = [];
}
