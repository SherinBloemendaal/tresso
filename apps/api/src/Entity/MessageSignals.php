<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the worker saw on a message that tells automated, bulk and junk mail apart: a few headers (List-Unsubscribe,
 * Precedence, Auto-Submitted, spam verdicts of the server, authentication results) and whether the server marked it
 * as junk. Kept apart from Message, only for messages that have any, and read by classification.
 */
#[ORM\Entity]
class MessageSignals
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    /**
     * Lower-cased header name to value.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $headers;

    /** The server flagged it as junk (IMAP keyword `$Junk`). */
    #[ORM\Column]
    private bool $junk;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(Message $message, array $headers, bool $junk)
    {
        $this->message = $message;
        $this->headers = $headers;
        $this->junk = $junk;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function isJunk(): bool
    {
        return $this->junk;
    }
}
