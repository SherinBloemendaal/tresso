<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The plain text of a message body, as the worker extracted it, for the search index. Kept apart from Message so
 * loading messages never drags the text along, and so the index can be rebuilt without reading object storage.
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

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    public function __construct(Message $message, string $text)
    {
        $this->message = $message;
        $this->text = mb_substr($text, 0, self::MAX_LENGTH);
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
