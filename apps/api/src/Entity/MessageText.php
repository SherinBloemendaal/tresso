<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The plain text of a message body, as the worker extracted it, for the search index. Kept apart from Message so
 * loading messages never drags the text along, and so the index can be rebuilt without reading object storage.
 *
 * Sealed with the owner's field key ({@see \App\Mail\MessageFields}); $text only holds text stored before encryption.
 */
#[ORM\Entity]
class MessageText
{
    /** Longest text the worker sends; the worker cuts at a word boundary below this. */
    public const int MAX_LENGTH = 32768;

    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sealedText;

    public function __construct(Message $message, string $sealedText)
    {
        $this->message = $message;
        $this->sealedText = $sealedText;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    /** Text stored before encryption; null once it is sealed. */
    public function getLegacyText(): ?string
    {
        return $this->text;
    }

    public function getSealedText(): ?string
    {
        return $this->sealedText;
    }

    public function seal(string $sealedText): void
    {
        $this->sealedText = $sealedText;
        $this->text = null;
    }
}
