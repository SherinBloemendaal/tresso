<?php

declare(strict_types=1);

namespace App\ApiResource\Input;

use App\Enum\MailAccountStatus;
use App\Mail\ServerSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partial update (JSON merge patch). Omitted or null fields stay as they are.
 */
final class UpdateMailAccount
{
    #[Assert\Length(min: 1, max: 120)]
    public ?string $displayName = null;

    #[Assert\Length(min: 1, max: 320)]
    public ?string $username = null;

    /** A new password; also clears an authentication failure so syncing resumes. */
    #[Assert\Length(min: 1, max: 1024)]
    public ?string $password = null;

    #[Assert\Valid]
    public ?ServerSettings $imap = null;

    #[Assert\Valid]
    public ?ServerSettings $smtp = null;

    /** Users may pause and resume syncing; the other statuses are set by the system. */
    #[Assert\Choice(choices: [MailAccountStatus::Active, MailAccountStatus::Paused])]
    public ?MailAccountStatus $status = null;

    /** The CalDAV address of the account's calendars; an empty string finds it automatically again. */
    #[Assert\AtLeastOneOf([new Assert\Blank(), new Assert\Url(protocols: ['https'], requireTld: true)])]
    #[Assert\Length(max: 2048)]
    public ?string $calendarUrl = null;
}
