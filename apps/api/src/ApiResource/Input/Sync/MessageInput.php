<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One message in one folder, as parsed by the worker. The raw MIME is already in object storage.
 */
final class MessageInput
{
    /** IMAP UID (or provider message id) within the folder. */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $remoteUid = '';

    #[Assert\PositiveOrZero]
    public ?int $modSeq = null;

    /** raw/<sha256[0:2]>/<sha256> */
    #[Assert\Regex('#^raw/[0-9a-f]{2}/[0-9a-f]{64}$#')]
    public string $rawObjectKey = '';

    #[Assert\PositiveOrZero]
    public int $size = 0;

    /** When the server received it (IMAP INTERNALDATE). */
    #[Assert\NotNull]
    public ?\DateTimeImmutable $receivedAt = null;

    /** Date header. */
    public ?\DateTimeImmutable $sentAt = null;

    #[Assert\Valid]
    public FlagsInput $flags;

    /** Message-ID header, with or without angle brackets. */
    #[Assert\Length(max: 998)]
    public ?string $messageId = null;

    #[Assert\Length(max: 998)]
    public ?string $inReplyTo = null;

    /** @var list<string> */
    #[Assert\Count(max: 200)]
    #[Assert\All([new Assert\Length(max: 998)])]
    public array $references = [];

    #[Assert\Length(max: 998)]
    public string $subject = '';

    #[Assert\NotNull]
    #[Assert\Valid]
    public ?AddressInput $from = null;

    /** @var list<AddressInput> */
    #[Assert\Count(max: 1000)]
    #[Assert\Valid]
    public array $to = [];

    /** @var list<AddressInput> */
    #[Assert\Count(max: 1000)]
    #[Assert\Valid]
    public array $cc = [];

    /** @var list<AddressInput> */
    #[Assert\Count(max: 1000)]
    #[Assert\Valid]
    public array $bcc = [];

    /** @var list<AddressInput> */
    #[Assert\Count(max: 100)]
    #[Assert\Valid]
    public array $replyTo = [];

    #[Assert\Length(max: 2000)]
    public string $snippet = '';

    /** @var list<AttachmentInput> */
    #[Assert\Count(max: 500)]
    #[Assert\Valid]
    public array $attachments = [];

    public function __construct()
    {
        $this->flags = new FlagsInput();
    }
}
