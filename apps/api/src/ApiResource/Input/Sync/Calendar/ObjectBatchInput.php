<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync\Calendar;

use App\ApiResource\Input\Sync\WorkerRequest;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changed and removed resources of one calendar, plus the calendar's cursor after this batch. Safe to resend.
 */
final class ObjectBatchInput extends WorkerRequest
{
    /** Opaque to the API (sync-token, ctag); stored on the calendar and handed back with the calendar list. */
    #[Assert\Length(max: 4096)]
    public ?string $cursor = null;

    /** @var list<RemoteObjectInput> */
    #[Assert\Count(max: 100)]
    #[Assert\Valid]
    public array $changed = [];

    /** @var list<string> */
    #[Assert\Count(max: 5000)]
    #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 2048)])]
    public array $removed = [];
}
