<?php

declare(strict_types=1);

namespace App\Mailbox;

use App\Entity\MailAccount;
use App\Enum\FolderRole;

/** The account has no folder for an action, e.g. no Archive folder to archive into. */
final class MissingFolder extends \RuntimeException
{
    public function __construct(public readonly MailAccount $account, public readonly FolderRole $role)
    {
        parent::__construct(\sprintf('%s has no %s folder.', $account->getEmailAddress(), ucfirst($role->value)));
    }
}
