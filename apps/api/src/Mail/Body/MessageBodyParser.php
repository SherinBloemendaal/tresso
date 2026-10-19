<?php

declare(strict_types=1);

namespace App\Mail\Body;

use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Reads the displayable parts of a raw RFC 5322 message, decoded to UTF-8.
 */
final readonly class MessageBodyParser
{
    public function parse(string $raw): ParsedBody
    {
        $message = new MailMimeParser()->parse($raw, false);

        return new ParsedBody($message->getHtmlContent(), $message->getTextContent());
    }
}
