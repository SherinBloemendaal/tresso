<?php

declare(strict_types=1);

namespace App\Outbox;

/** An uploaded file that a draft stopped referring to: removed from object storage if no draft refers to it now. */
final readonly class CollectUpload
{
    public function __construct(public string $objectKey)
    {
    }
}
