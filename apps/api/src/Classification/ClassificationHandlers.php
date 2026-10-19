<?php

declare(strict_types=1);

namespace App\Classification;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

final readonly class ClassificationHandlers
{
    public function __construct(private MailClassification $classification)
    {
    }

    #[AsMessageHandler]
    public function classifyNew(ClassifyNewMail $message): void
    {
        $this->classification->classifyNew(Uuid::fromString($message->userId));
    }

    #[AsMessageHandler]
    public function reclassify(ReclassifyMail $message): void
    {
        $this->classification->reclassify(
            Uuid::fromString($message->userId),
            null === $message->since ? null : new \DateTimeImmutable($message->since),
        );
    }
}
