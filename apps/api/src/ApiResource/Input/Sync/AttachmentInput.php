<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * An attachment the worker already uploaded to object storage under attachments/<owner id>/<fp[0:2]>/<fp>, encrypted
 * with the message's key version.
 */
final class AttachmentInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $filename = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $contentType = '';

    #[Assert\PositiveOrZero]
    public int $size = 0;

    /** The owner's fingerprint of the content: hex(HMAC-SHA256(index key, SHA-256 of the content)). */
    #[Assert\Regex('/^[0-9a-f]{64}$/')]
    public string $fingerprint = '';

    #[Assert\Length(max: 255)]
    public ?string $contentId = null;

    public bool $inline = false;
}
