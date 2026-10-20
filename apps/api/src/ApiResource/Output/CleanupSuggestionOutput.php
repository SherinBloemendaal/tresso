<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

use App\Bulk\CleanupSuggestion;

/** One cleanup suggestion; see {@see \App\ApiResource\CleanupSuggestionList}. */
final readonly class CleanupSuggestionOutput
{
    /**
     * @param array{in: string, smartInboxId: ?string, sender: ?string, olderThanDays: ?int, largerThan: ?int} $scope
     */
    public function __construct(
        public string $id,
        public string $kind,
        public int $conversations,
        public ?int $unread,
        public ?int $bytes,
        public ?string $sender,
        public ?string $senderName,
        public ?string $smartInboxId,
        public ?int $windowDays,
        public ?int $windowReceived,
        public ?int $olderThanDays,
        public ?int $largerThan,
        public string $action,
        public array $scope,
    ) {
    }

    public static function fromSuggestion(CleanupSuggestion $suggestion): self
    {
        return new self(
            $suggestion->id,
            $suggestion->kind,
            $suggestion->conversations,
            $suggestion->unread,
            $suggestion->bytes,
            $suggestion->sender,
            $suggestion->senderName,
            $suggestion->smartInboxId,
            $suggestion->windowDays,
            $suggestion->windowReceived,
            $suggestion->olderThanDays,
            $suggestion->largerThan,
            $suggestion->action->value,
            [
                'in' => $suggestion->in->value,
                'smartInboxId' => $suggestion->smartInboxId,
                'sender' => $suggestion->sender,
                'olderThanDays' => $suggestion->olderThanDays,
                'largerThan' => $suggestion->largerThan,
            ],
        );
    }
}
