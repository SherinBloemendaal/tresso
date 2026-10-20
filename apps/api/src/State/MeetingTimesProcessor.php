<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Calendar\MeetingTimesInput;
use App\ApiResource\MeetingTimesResource;
use App\ApiResource\Output\ProposedSlot;
use App\Calendar\Assistant\InvalidMeetingTimeQuery;
use App\Calendar\Assistant\MeetingAttendee;
use App\Calendar\Assistant\MeetingTimeFinder;
use App\Calendar\Assistant\MeetingTimeQuery;
use App\Calendar\TooManyOccurrences;
use App\Entity\Calendar;
use App\Repository\CalendarRepository;
use App\Security\CurrentUser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<MeetingTimesInput, MeetingTimesResource>
 */
final readonly class MeetingTimesProcessor implements ProcessorInterface
{
    public function __construct(
        private MeetingTimeFinder $finder,
        private CalendarRepository $calendars,
        private CurrentUser $currentUser,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MeetingTimesResource
    {
        $user = $this->currentUser->get();
        if (null !== $data->calendarIds) {
            $owned = array_map(static fn (Calendar $calendar): string => $calendar->getId()->toRfc4122(), $this->calendars->findOwnedBy($user));
            foreach ($data->calendarIds as $i => $id) {
                if (!\in_array(mb_strtolower($id), $owned, true)) {
                    throw self::invalid($data, \sprintf('calendarIds[%d]', $i), 'This calendar does not exist.', $id);
                }
            }
        }

        $from = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data->from);
        $to = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data->to);
        if (false === $from || false === $to) {
            throw new \LogicException('from and to were validated as date-times.');
        }
        $attendees = [];
        foreach ($data->attendees as $input) {
            $attendee = new MeetingAttendee($input->address, $input->optional);
            // Named twice: required wins over optional.
            $attendees[$attendee->address] = new MeetingAttendee($attendee->address, $attendee->optional && ($attendees[$attendee->address]->optional ?? true));
        }

        try {
            $query = new MeetingTimeQuery(
                $from,
                $to,
                $data->durationMinutes,
                array_values($attendees),
                $data->stepMinutes,
                $data->limit,
                $data->perDay,
                $data->inPerson,
                $data->workingHoursOnly,
                null === $data->calendarIds ? null : array_map(mb_strtolower(...), $data->calendarIds),
                $data->ignore,
                null === $data->timeZone ? null : new \DateTimeZone($data->timeZone),
            );
        } catch (InvalidMeetingTimeQuery $e) {
            throw self::invalid($data, $e->field, $e->getMessage(), null);
        }

        try {
            $times = $this->finder->find($user, $query, $this->clock->now());
        } catch (TooManyOccurrences $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new MeetingTimesResource(
            array_map(ProposedSlot::fromSlot(...), $times->slots),
            $times->noSlotReason?->value,
            $times->rules->zone->getName(),
            array_map(static fn (string $address, bool $known): array => ['address' => $address, 'availability' => $known ? 'known' : 'unknown'], array_keys($times->attendeeAvailability), array_values($times->attendeeAvailability)),
        );
    }

    private static function invalid(mixed $input, string $path, string $message, mixed $value): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], $input, $path, $value)]));
    }
}
