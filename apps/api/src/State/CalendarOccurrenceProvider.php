<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CalendarOccurrence;
use App\Calendar\OccurrenceExpander;
use App\Calendar\TooManyOccurrences;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<CalendarOccurrence>
 */
final readonly class CalendarOccurrenceProvider implements ProviderInterface
{
    public function __construct(
        private OccurrenceExpander $expander,
        private CurrentUser $currentUser,
        private RequestStack $requests,
    ) {
    }

    /**
     * @return list<CalendarOccurrence>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $query = $this->requests->getCurrentRequest()?->query;
        $from = \DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) $query?->get('from'));
        $to = \DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) $query?->get('to'));
        if (false === $from || false === $to) {
            throw new BadRequestHttpException('from and to must be date-times (RFC 3339).');
        }
        $zone = (string) ($query?->get('timeZone') ?? 'UTC');
        $calendarIds = $query?->all('calendarId') ?? [];

        try {
            $timeZone = new \DateTimeZone('' === $zone ? 'UTC' : $zone);
        } catch (\DateInvalidTimeZoneException $e) {
            throw new BadRequestHttpException('timeZone must be an IANA time zone such as Europe/Amsterdam.', $e);
        }

        try {
            return $this->expander->expand($this->currentUser->get(), array_values(array_filter($calendarIds, \is_string(...))), $from, $to, $timeZone);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        } catch (TooManyOccurrences $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }
}
