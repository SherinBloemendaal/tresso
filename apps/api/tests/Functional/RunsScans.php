<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Runs the attachment scans the last request queued, the way the scan worker would, with the fake scanner of the
 * test environment. Call it right after the request: the test client empties the in-memory queues before the next.
 */
trait RunsScans
{
    protected function runQueuedScans(): void
    {
        $transport = static::getContainer()->get('messenger.transport.scan');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $bus = static::getContainer()->get(MessageBusInterface::class);
        while ([] !== $envelopes = array_values([...$transport->getSent()])) {
            $transport->reset();
            foreach ($envelopes as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('scan')));
            }
        }
    }
}
