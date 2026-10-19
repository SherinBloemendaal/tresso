<?php

declare(strict_types=1);

namespace App\Outbox;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * How large attachments may be, together, on one message. Most mail servers take about 25 MB per message, and the
 * encoding for sending makes files about a third larger, so the default keeps a message within that.
 */
final readonly class AttachmentLimits
{
    public function __construct(
        #[Autowire(env: 'int:MAIL_MAX_ATTACHMENT_BYTES')]
        public int $maxAttachmentBytes,
    ) {
        if ($maxAttachmentBytes < 1) {
            throw new \InvalidArgumentException('MAIL_MAX_ATTACHMENT_BYTES must be a positive number of bytes.');
        }
    }

    /** "18 MB", for messages to the user. */
    public static function describe(int $bytes): string
    {
        return $bytes >= 1_048_576 ? \sprintf('%d MB', intdiv($bytes, 1_048_576)) : \sprintf('%d KB', max(1, intdiv($bytes, 1024)));
    }
}
