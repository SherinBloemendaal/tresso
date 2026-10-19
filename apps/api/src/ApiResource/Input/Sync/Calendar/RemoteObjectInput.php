<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync\Calendar;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One calendar object resource at the provider, with its iCalendar text when the worker fetched it.
 */
final class RemoteObjectInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 2048)]
    public string $remoteId = '';

    #[Assert\Length(max: 512)]
    public ?string $etag = null;

    #[Assert\Length(max: 1048576)]
    public ?string $ical = null;
}
