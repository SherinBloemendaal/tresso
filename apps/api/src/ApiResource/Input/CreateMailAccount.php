<?php

declare(strict_types=1);

namespace App\ApiResource\Input;

use App\Mail\ServerSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Connects an IMAP/SMTP account. Gmail and Microsoft 365 connect through OAuth instead (phase 1.6).
 */
final class CreateMailAccount
{
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 320)]
    public string $emailAddress = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $displayName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 320)]
    public string $username = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 1024)]
    public string $password = '';

    #[Assert\NotNull]
    #[Assert\Valid]
    public ?ServerSettings $imap = null;

    #[Assert\NotNull]
    #[Assert\Valid]
    public ?ServerSettings $smtp = null;

    /** The CalDAV address of the account's calendars; found automatically when omitted. */
    #[Assert\Url(protocols: ['https'], requireTld: true)]
    #[Assert\Length(max: 2048)]
    public ?string $calendarUrl = null;
}
