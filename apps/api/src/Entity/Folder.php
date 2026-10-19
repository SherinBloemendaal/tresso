<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FolderRole;
use App\Sync\Change\TracksChanges;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['mail_account_id', 'remote_name'])]
#[ORM\Index(columns: ['mail_account_id', 'change_seq'])]
class Folder implements TracksChanges
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MailAccount $mailAccount;

    /** Folder path on the server (IMAP mailbox name, Gmail label id, Graph folder id). */
    #[ORM\Column(length: 512)]
    private string $remoteName;

    #[ORM\Column(length: 255)]
    private string $displayName;

    #[ORM\Column(length: 16, enumType: FolderRole::class)]
    private FolderRole $role;

    /** IMAP UIDVALIDITY; a change means all cached UIDs for this folder are invalid. */
    #[ORM\Column(nullable: true)]
    private ?int $uidValidity = null;

    /** Provider sync cursor: IMAP HIGHESTMODSEQ, Gmail historyId or Graph delta token. */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $syncCursor = null;

    public function __construct(MailAccount $mailAccount, string $remoteName, string $displayName, FolderRole $role)
    {
        $this->id = Uuid::v7();
        $this->mailAccount = $mailAccount;
        $this->remoteName = $remoteName;
        $this->displayName = $displayName;
        $this->role = $role;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function getRemoteName(): string
    {
        return $this->remoteName;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getRole(): FolderRole
    {
        return $this->role;
    }

    public function getUidValidity(): ?int
    {
        return $this->uidValidity;
    }

    public function getSyncCursor(): ?string
    {
        return $this->syncCursor;
    }

    public function rename(string $displayName, FolderRole $role): void
    {
        $this->displayName = $displayName;
        $this->role = $role;
    }

    public function updateSyncState(?int $uidValidity, ?string $syncCursor): void
    {
        $this->uidValidity = $uidValidity;
        $this->syncCursor = $syncCursor;
    }

    public function getChangeOwner(): User
    {
        return $this->mailAccount->getOwner();
    }

    public static function clientFields(): ?array
    {
        return ['remoteName', 'displayName', 'role'];
    }

    public static function changeType(): string
    {
        return 'folder';
    }

    public function getChangeSeq(): int
    {
        return $this->changeSeq;
    }

    public function setChangeSeq(int $changeSeq): void
    {
        $this->changeSeq = $changeSeq;
    }
}
