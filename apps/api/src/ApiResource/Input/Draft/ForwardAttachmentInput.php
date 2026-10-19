<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Draft;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /api/v1/drafts/{draftId}/forwarded-attachments: a file of a received message, for a forward. */
final class ForwardAttachmentInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $attachmentId = '';
}
