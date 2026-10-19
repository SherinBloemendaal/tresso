<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Calendar\CreateCalendar;
use App\ApiResource\Input\Calendar\UpdateCalendar;
use App\Entity\Calendar;
use App\Enum\CalendarSource;
use App\Security\Scope;
use App\State\CalendarProcessor;
use App\State\CalendarProvider;

/**
 * A calendar of the signed-in user: at the provider of one of their mail accounts, or local to Tresso
 * (mailAccountId null). `color` is the user's color when they chose one, else the provider's.
 */
#[ApiResource(
    shortName: 'Calendar',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/calendars',
            paginationEnabled: false,
            security: "is_granted('".Scope::ROLE_CALENDAR_READ."')",
            provider: CalendarProvider::class,
        ),
        new Get(
            uriTemplate: '/calendars/{id}',
            security: "is_granted('".Scope::ROLE_CALENDAR_READ."')",
            provider: CalendarProvider::class,
        ),
        new Post(
            uriTemplate: '/calendars',
            security: "is_granted('".Scope::ROLE_CALENDAR_WRITE."')",
            input: CreateCalendar::class,
            read: false,
            processor: CalendarProcessor::class,
        ),
        new Patch(
            uriTemplate: '/calendars/{id}',
            security: "is_granted('".Scope::ROLE_CALENDAR_WRITE."')",
            input: UpdateCalendar::class,
            provider: CalendarProvider::class,
            processor: CalendarProcessor::class,
        ),
        new Delete(
            uriTemplate: '/calendars/{id}',
            security: "is_granted('".Scope::ROLE_CALENDAR_WRITE."')",
            provider: CalendarProvider::class,
            processor: CalendarProcessor::class,
        ),
    ],
)]
final readonly class CalendarResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public ?string $mailAccountId,
        public CalendarSource $source,
        public string $name,
        public string $color,
        public ?string $timeZone,
        public bool $writable,
        public bool $isDefault,
        public bool $visible,
        public bool $work,
        public bool $providerSchedules,
        public int $position,
    ) {
    }

    public static function fromEntity(Calendar $calendar): self
    {
        return new self(
            $calendar->getId()->toRfc4122(),
            $calendar->getMailAccount()?->getId()->toRfc4122(),
            $calendar->getSource(),
            $calendar->getName(),
            $calendar->getUserColor() ?? $calendar->getColor() ?? Calendar::DEFAULT_COLOR,
            $calendar->getTimeZone(),
            $calendar->isWritable(),
            $calendar->isDefault(),
            $calendar->isVisible(),
            $calendar->isWork(),
            $calendar->providerSchedules(),
            $calendar->getPosition(),
        );
    }
}
