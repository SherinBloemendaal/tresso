<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Batch\BatchScheduleInput;
use App\Batch\BatchTimes;
use App\Security\Scope;
use App\State\BatchDeliveryProcessor;
use App\State\BatchDeliveryProvider;

/**
 * When the signed-in user's batches go out. Mail of smart inboxes that deliver in batches (`batchDelivery`) waits for
 * the next of these times, in the user's time zone, and is then delivered at once: in the lists, without a
 * notification in between. Changing the times moves mail that waits to the new next batch.
 */
#[ApiResource(
    shortName: 'BatchDelivery',
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/batch-delivery',
            uriVariables: [],
            security: "is_granted('".Scope::ROLE_SETTINGS_READ."')",
            provider: BatchDeliveryProvider::class,
        ),
        new Put(
            uriTemplate: '/batch-delivery',
            uriVariables: [],
            status: 200,
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            input: BatchScheduleInput::class,
            read: false,
            allowCreate: true,
            processor: BatchDeliveryProcessor::class,
        ),
    ],
)]
final readonly class BatchDeliveryResource
{
    /**
     * @param list<string> $times
     */
    public function __construct(
        /** Times of day as "HH:MM", sorted. */
        public array $times,
        /** An IANA time zone, e.g. Europe/Amsterdam. */
        public string $timeZone,
        /** False while the user has the defaults (09:00 and 16:00, UTC), so a client can offer its own time zone. */
        public bool $configured,
        /** The next batch. */
        public \DateTimeImmutable $nextBatchAt,
    ) {
    }

    public static function from(BatchTimes $times, bool $configured, \DateTimeImmutable $now): self
    {
        return new self($times->times, $times->timeZone->getName(), $configured, $times->next($now));
    }
}
