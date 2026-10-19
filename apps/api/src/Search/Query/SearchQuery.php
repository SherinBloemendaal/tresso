<?php

declare(strict_types=1);

namespace App\Search\Query;

/**
 * A parsed search: the words to look for and the filters around them. See {@see SearchQueryParser}.
 */
final readonly class SearchQuery
{
    /**
     * @param string       $text   words and "quoted phrases" for full-text search, may be empty
     * @param list<string> $from   each value must match the sender
     * @param list<string> $to     each value must match a To, Cc or Bcc recipient
     * @param list<string> $in     each value names a folder role, an account or a folder
     * @param ?\DateTimeImmutable $after  received on or after this moment
     * @param ?\DateTimeImmutable $before received before this moment
     */
    public function __construct(
        public string $text,
        public array $from = [],
        public array $to = [],
        public array $in = [],
        public bool $hasAttachment = false,
        public ?\DateTimeImmutable $after = null,
        public ?\DateTimeImmutable $before = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return '' === $this->text && [] === $this->from && [] === $this->to && [] === $this->in && !$this->hasAttachment
            && null === $this->after && null === $this->before;
    }
}
