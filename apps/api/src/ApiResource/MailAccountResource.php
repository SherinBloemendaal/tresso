<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\CreateMailAccount;
use App\ApiResource\Input\UpdateMailAccount;
use App\Entity\MailAccount;
use App\Enum\MailAccountStatus;
use App\Enum\MailProvider;
use App\Mail\ServerSettings;
use App\Security\Scope;
use App\State\MailAccountProcessor;
use App\State\MailAccountProvider;

/**
 * A mail account of the signed-in user. Credentials can be written but are never returned.
 */
#[ApiResource(
    shortName: 'MailAccount',
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/mail-accounts',
            paginationEnabled: false,
            security: "is_granted('".Scope::ROLE_SETTINGS_READ."')",
            provider: MailAccountProvider::class,
        ),
        new Get(
            uriTemplate: '/mail-accounts/{id}',
            security: "is_granted('".Scope::ROLE_SETTINGS_READ."')",
            provider: MailAccountProvider::class,
        ),
        new Post(
            uriTemplate: '/mail-accounts',
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            input: CreateMailAccount::class,
            read: false,
            processor: MailAccountProcessor::class,
        ),
        new Patch(
            uriTemplate: '/mail-accounts/{id}',
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            input: UpdateMailAccount::class,
            provider: MailAccountProvider::class,
            processor: MailAccountProcessor::class,
        ),
        new Delete(
            uriTemplate: '/mail-accounts/{id}',
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            provider: MailAccountProvider::class,
            processor: MailAccountProcessor::class,
        ),
    ],
)]
final class MailAccountResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $emailAddress,
        public string $displayName,
        public MailProvider $provider,
        public MailAccountStatus $status,
        public string $username,
        public ServerSettings $imap,
        public ServerSettings $smtp,
        public ?\DateTimeImmutable $lastSyncedAt,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromEntity(MailAccount $account): self
    {
        return new self(
            $account->getId()->toRfc4122(),
            $account->getEmailAddress(),
            $account->getDisplayName(),
            $account->getProvider(),
            $account->getStatus(),
            $account->getUsername(),
            $account->getImap(),
            $account->getSmtp(),
            $account->getLastSyncedAt(),
            $account->getCreatedAt(),
        );
    }
}
