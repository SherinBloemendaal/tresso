<?php

declare(strict_types=1);

namespace App\Search;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class IndexUserMailHandler
{
    public function __construct(private SearchIndexer $indexer)
    {
    }

    public function __invoke(IndexUserMail $message): void
    {
        $this->indexer->index(Uuid::fromString($message->userId));
    }
}
