<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

use App\Bulk\BulkScope;

/** The conversations a bulk action takes, with the age turned into the instant it was counted from. */
final readonly class BulkScopeOutput
{
    public function __construct(
        /** `smartInbox`, `inbox` or `mailbox`. */
        public string $in,
        public ?string $smartInboxId,
        public ?string $mailAccountId,
        public ?string $sender,
        /** Conversations whose newest message is older than this. */
        public ?\DateTimeImmutable $before,
        public ?int $largerThan,
    ) {
    }

    public static function fromScope(BulkScope $scope): self
    {
        return new self(
            $scope->area->value,
            $scope->smartInbox?->getId()->toRfc4122(),
            $scope->mailAccount?->getId()->toRfc4122(),
            $scope->sender,
            $scope->before,
            $scope->largerThan,
        );
    }
}
