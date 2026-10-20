<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Bulk\BulkActionInput;
use App\Entity\BulkAction;
use App\Enum\BulkActionStatus;
use App\Security\Scope;
use App\State\BulkActionProcessor;
use App\State\BulkActionProvider;

/**
 * One action on every conversation of a scope: a whole smart inbox, one of its senders, mail older than an age. The
 * server works through it in batches in the background (`status` `running`, then `done`); each batch shows in Tresso
 * at once and reaches clients through the delta, and the mail servers follow as for a single action. Only mail that
 * reached Tresso before the action started is taken.
 *
 * While a mail server is behind on the user's changes, the action waits (`waitingSince`), and stops after an hour.
 * `POST /bulk-actions/{id}/cancel` stops it, or its undo, at once. Either way what was done stays done.
 *
 * An archive, trash or move can be undone for a day (`canUndo`): only what it moved goes back, each message from
 * where it went into the one folder it came from; `unrestorable` counts the messages no move puts back exactly
 * (they were in several folders, or were archived into Gmail's All Mail).
 */
#[ApiResource(
    shortName: 'BulkAction',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/bulk-actions',
            status: 202,
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: BulkActionInput::class,
            read: false,
            processor: BulkActionProcessor::class,
        ),
        new Get(
            uriTemplate: '/bulk-actions/{id}',
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: BulkActionProvider::class,
        ),
        new Post(
            uriTemplate: '/bulk-actions/{id}/undo',
            status: 202,
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: false,
            // The processor looks the action up itself, under its row lock.
            read: false,
            processor: BulkActionProcessor::class,
            name: 'bulk_action_undo',
        ),
        // Stops the action or its undo; what it did so far stays done and can be undone.
        new Post(
            uriTemplate: '/bulk-actions/{id}/cancel',
            status: 200,
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: false,
            read: false,
            processor: BulkActionProcessor::class,
            name: 'bulk_action_cancel',
        ),
    ],
)]
final readonly class BulkActionResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        /** `markRead`, `archive`, `trash` or `move`. */
        public string $action,
        public ?string $folderId,
        /** The conversations it takes; null once the smart inbox it was about is deleted. */
        public ?Output\BulkScopeOutput $scope,
        public BulkActionStatus $status,
        /** Conversations worked through so far. */
        public int $conversations,
        /** Messages that changed; the others already were that way. */
        public int $changed,
        public int $unrestorable,
        /** Messages the undo put back. */
        public int $restored,
        public bool $canUndo,
        public ?string $error,
        /** Since when it waits for a mail server that is behind on the user's changes; null while it goes on. */
        public ?\DateTimeImmutable $waitingSince,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $finishedAt,
    ) {
    }

    public static function fromEntity(BulkAction $bulkAction): self
    {
        $scope = $bulkAction->getScope();

        return new self(
            $bulkAction->getId()->toRfc4122(),
            $bulkAction->getAction()->value,
            $bulkAction->getTargetFolder()?->getId()->toRfc4122(),
            null === $scope ? null : Output\BulkScopeOutput::fromScope($scope),
            $bulkAction->getStatus(),
            $bulkAction->getConversations(),
            $bulkAction->getChanged(),
            $bulkAction->getUnrestorable(),
            $bulkAction->getRestored(),
            $bulkAction->canUndo(),
            $bulkAction->getError(),
            $bulkAction->getWaitingSince(),
            $bulkAction->getCreatedAt(),
            $bulkAction->getFinishedAt(),
        );
    }
}
