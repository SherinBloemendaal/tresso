<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * New messages for one folder, plus the folder's sync cursor after this batch. Safe to resend.
 */
final class MessageBatchInput extends WorkerRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 512)]
    public string $folder = '';

    /** Opaque to the API; stored on the folder and handed back to the next worker. */
    #[Assert\Length(max: 8192)]
    public ?string $cursor = null;

    /** @var list<MessageInput> */
    #[Assert\Count(max: 200)]
    #[Assert\Valid]
    public array $messages = [];
}
