<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The owner's answer to an invitation.
 */
final class EventResponseInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['accepted', 'tentative', 'declined'])]
    public string $response = '';

    /** For one occurrence of a series (its recurrenceId); the whole series when omitted. */
    #[Assert\Regex('/^\d{8}(T\d{6}Z)?$/')]
    public ?string $recurrenceId = null;

    #[Assert\Length(max: 2000)]
    public ?string $comment = null;

    /** Tell the organizer (default); false only changes the own calendar. */
    public bool $notify = true;
}
