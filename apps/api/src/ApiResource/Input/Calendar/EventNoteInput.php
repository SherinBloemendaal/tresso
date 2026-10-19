<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use Symfony\Component\Validator\Constraints as Assert;

/** The user's private note on an event or one occurrence; empty removes it. */
final class EventNoteInput
{
    #[Assert\Regex('/^\d{8}(T\d{6}Z)?$/')]
    public ?string $recurrenceId = null;

    #[Assert\Length(max: 100000)]
    public string $text = '';
}
