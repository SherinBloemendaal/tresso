<?php

declare(strict_types=1);

namespace App\Realtime;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Publishes after the work is done: for HTTP after the response went out, so a slow hub never delays the caller.
 */
final readonly class PublishChangesSubscriber implements EventSubscriberInterface
{
    public function __construct(private ChangeNotifier $notifier)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'publish',
            ConsoleEvents::TERMINATE => 'publish',
            WorkerMessageHandledEvent::class => 'publish',
            WorkerMessageFailedEvent::class => 'publish',
        ];
    }

    public function publish(): void
    {
        $this->notifier->publish();
    }
}
