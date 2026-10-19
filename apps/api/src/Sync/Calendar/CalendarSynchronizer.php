<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

use App\ApiResource\Input\Sync\Calendar\CalendarListInput;
use App\ApiResource\Input\Sync\Calendar\InventoryInput;
use App\ApiResource\Input\Sync\Calendar\ObjectBatchInput;
use App\ApiResource\Input\Sync\Calendar\RemoteObjectInput;
use App\Calendar\CalendarStore;
use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\InvalidCalendarData;
use App\Entity\Calendar;
use App\Entity\CalendarObject;
use App\Entity\CalendarOperation;
use App\Entity\MailAccount;
use App\Enum\CalendarSyncStatus;
use App\Repository\CalendarObjectRepository;
use App\Repository\CalendarRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * What the worker reports about an account's provider calendars: the calendar list, the inventory of a calendar
 * (for servers without sync-token) and changed or removed resources. The provider's data wins, except for
 * resources with a local change still on its way to the provider: those are written first, and a conflict is
 * settled when the write reports back. Runs inside the caller's transaction.
 */
final readonly class CalendarSynchronizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalendarRepository $calendars,
        private CalendarObjectRepository $objects,
        private CalendarStore $store,
        private LoggerInterface $logger,
    ) {
    }

    public function syncCalendars(MailAccount $account, CalendarListInput $input): CalendarListResult
    {
        if (null !== $input->serverUrl) {
            $account->setCalendarServer($input->serverUrl);
        }
        $account->setCalendarStatus(CalendarSyncStatus::Active);

        $existing = $this->calendars->findByAccount($account);
        $seen = [];
        $position = 0;
        foreach ($input->calendars as $remote) {
            if (isset($seen[$remote->remoteId])) {
                continue;
            }
            $calendar = $existing[$remote->remoteId] ?? null;
            if (null === $calendar) {
                $calendar = Calendar::remote($account, $input->source, $remote->remoteId, trim($remote->name), $remote->writable);
                $this->entityManager->persist($calendar);
            }
            $calendar->rename(trim($remote->name));
            $calendar->setColor($remote->color);
            $calendar->setTimeZone($remote->timeZone);
            $calendar->setWritable($remote->writable);
            $calendar->setProviderSchedules($input->providerSchedules);
            $calendar->setPosition($position++);
            $seen[$remote->remoteId] = $calendar;
        }

        foreach ($existing as $remoteId => $calendar) {
            if (!isset($seen[$remoteId])) {
                $this->entityManager->remove($calendar);
            }
        }
        $this->chooseDefault($input, $seen);
        $this->entityManager->flush();

        return new CalendarListResult(array_values(array_map(
            static fn (Calendar $calendar): array => ['remoteId' => (string) $calendar->getRemoteId(), 'id' => $calendar->getId()->toRfc4122(), 'cursor' => $calendar->getSyncCursor()],
            $seen,
        )));
    }

    public function inventory(Calendar $calendar, InventoryInput $input): InventoryResult
    {
        $known = $this->objects->remoteVersions($calendar);
        $listed = [];
        $fetch = [];
        foreach ($input->objects as $object) {
            $listed[$object->remoteId] = true;
            if (!\array_key_exists($object->remoteId, $known) || null === $object->etag || $known[$object->remoteId] !== $object->etag) {
                $fetch[] = $object->remoteId;
            }
        }

        $gone = array_values(array_filter(array_keys($known), static fn (string $remoteId): bool => !isset($listed[$remoteId])));
        $removed = $this->removeRemote($calendar, $gone);

        return new InventoryResult($fetch, $removed);
    }

    public function objects(Calendar $calendar, ObjectBatchInput $input): ObjectBatchResult
    {
        $stored = $skipped = 0;
        $byRemoteId = $this->objects->findByRemoteIds($calendar, array_map(static fn (RemoteObjectInput $object): string => $object->remoteId, $input->changed));
        foreach ($input->changed as $remote) {
            if (null === $remote->ical || !$this->upsert($calendar, $byRemoteId[$remote->remoteId] ?? null, $remote)) {
                ++$skipped;
                continue;
            }
            ++$stored;
        }
        $removed = $this->removeRemote($calendar, array_values(array_unique($input->removed)));

        if (null !== $input->cursor) {
            $calendar->setSyncCursor($input->cursor);
        }
        $this->entityManager->flush();

        return new ObjectBatchResult($stored, $removed, $skipped);
    }

    /**
     * Stores the provider's version of a resource. Returns false when it was not taken (unreadable, or a local change
     * is waiting to be written).
     */
    public function upsert(Calendar $calendar, ?CalendarObject $object, RemoteObjectInput $remote): bool
    {
        try {
            $parsed = CalendarDocument::parse((string) $remote->ical);
            $groups = CalendarDocument::eventsByUid($parsed);
            if (1 !== \count($groups)) {
                // Not an event resource (a VTODO, an empty or a malformed one): nothing for the calendar.
                if (null !== $object) {
                    $this->store->remove($object, true);
                }

                return false;
            }
            $uid = (string) array_key_first($groups);
            $vcalendar = CalendarDocument::forUid($parsed, $uid);

            if (null !== $object && $object->getUid() !== $uid) {
                $this->store->remove($object, true);
                $object = null;
            }
            $object ??= $this->objects->findOneByUid($calendar, $uid);
            if (null !== $object && $this->hasPendingWrite($object)) {
                return false;
            }
            $object ??= new CalendarObject($calendar, $uid, '');
            $this->store->save($object, $vcalendar, true);
            $object->stored($remote->remoteId, $remote->etag);

            return true;
        } catch (InvalidCalendarData $e) {
            $this->logger->info('Skipping an unreadable calendar resource', ['calendar' => $calendar->getId()->toRfc4122(), 'remoteId' => $remote->remoteId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param list<string> $remoteIds
     */
    private function removeRemote(Calendar $calendar, array $remoteIds): int
    {
        $removed = 0;
        foreach (array_chunk($remoteIds, 500) as $chunk) {
            foreach ($this->objects->findByRemoteIds($calendar, $chunk) as $object) {
                if ($this->hasPendingWrite($object)) {
                    continue;
                }
                $this->store->remove($object, true);
                ++$removed;
            }
        }

        return $removed;
    }

    private function hasPendingWrite(CalendarObject $object): bool
    {
        return null !== $this->entityManager->getRepository(CalendarOperation::class)->findOneBy(['object' => $object]);
    }

    /**
     * @param array<string, Calendar> $calendars
     */
    private function chooseDefault(CalendarListInput $input, array $calendars): void
    {
        $current = array_filter($calendars, static fn (Calendar $calendar): bool => $calendar->isDefault() && $calendar->isWritable());
        if ([] !== $current) {
            return;
        }
        $hinted = null;
        foreach ($input->calendars as $remote) {
            if ($remote->isDefault && isset($calendars[$remote->remoteId]) && $calendars[$remote->remoteId]->isWritable()) {
                $hinted = $calendars[$remote->remoteId];
                break;
            }
        }
        $choice = $hinted ?? array_values(array_filter($calendars, static fn (Calendar $calendar): bool => $calendar->isWritable()))[0] ?? null;
        foreach ($calendars as $calendar) {
            $calendar->setDefault($calendar === $choice);
        }
    }
}
