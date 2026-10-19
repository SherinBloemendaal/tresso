<?php

declare(strict_types=1);

namespace App\Classification;

use App\Enum\FolderRole;

/**
 * What a classifier knows about one incoming message: the envelope, a few headers and where the server keeps it.
 */
final readonly class MailToClassify
{
    /**
     * @param list<string>          $to          lower-cased addresses
     * @param list<string>          $cc          lower-cased addresses
     * @param array<string, string> $headers     lower-cased header name to value
     * @param list<FolderRole>      $folderRoles roles of the folders the message is in
     */
    public function __construct(
        public string $messageId,
        public string $fromAddress,
        public ?string $fromName,
        public array $to,
        public array $cc,
        public string $subject,
        public string $snippet,
        public array $headers,
        public bool $junk,
        public array $folderRoles,
        /** It answers another message (In-Reply-To is set). */
        public bool $isReply = false,
        /**
         * Domains the receiving server's Authentication-Results header names; null when the topmost header is the
         * provider's own (see {@see ReceivingServers}).
         *
         * @var list<string>|null
         */
        public ?array $receivingDomains = null,
    ) {
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return null === $value ? null : trim($value);
    }
}
