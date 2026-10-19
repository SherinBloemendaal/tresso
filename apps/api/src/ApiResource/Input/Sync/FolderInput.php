<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use App\Enum\FolderRole;
use Symfony\Component\Validator\Constraints as Assert;

final class FolderInput
{
    /** Mailbox name on the server (IMAP), label or folder id (Gmail, Graph). */
    #[Assert\NotBlank]
    #[Assert\Length(max: 512)]
    public string $remoteName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $displayName = '';

    public FolderRole $role = FolderRole::Custom;

    /** IMAP UIDVALIDITY; null for providers without it. */
    #[Assert\PositiveOrZero]
    public ?int $uidValidity = null;
}
