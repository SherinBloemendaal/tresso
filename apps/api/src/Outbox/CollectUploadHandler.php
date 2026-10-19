<?php

declare(strict_types=1);

namespace App\Outbox;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CollectUploadHandler
{
    public function __construct(private UploadCollector $collector)
    {
    }

    public function __invoke(CollectUpload $message): void
    {
        $this->collector->collect($message->objectKey);
    }
}
