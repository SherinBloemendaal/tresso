<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * An attachment the worker already uploaded to object storage under attachments/<sha256[0:2]>/<sha256>.
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

    #[Assert\Regex('/^[0-9a-f]{64}$/')]
    public string $sha256 = '';

    #[Assert\Length(max: 255)]
    public ?string $contentId = null;

    public bool $inline = false;
}
