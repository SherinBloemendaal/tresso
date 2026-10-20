<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

use App\Entity\SenderRule;

/**
 * The sender rule a correction was remembered as.
 */
final readonly class RememberedSender
{
    public function __construct(
        public string $id,
        /** An address, or `@domain`. */
        public string $sender,
        /** null: the smart inbox's verdict. */
        public ?string $verdict,
        /**
         * Why new mail like this keeps going to Spam despite the rule, or null when the rule applies:
         * `authentication_failed` (it fails DMARC with a reject or quarantine policy, so anyone could have written that
         * From address) or `server_spam` (the mail server marks it as spam and its sender is not authenticated in a
         * header Tresso can trust). The corrected conversation stays where the user put it either way.
         */
        public ?string $spamOverrules,
    ) {
    }

    public static function fromEntity(SenderRule $rule, ?string $spamOverrules): self
    {
        return new self($rule->getId()->toRfc4122(), $rule->getSender(), $rule->getVerdict()?->value, $spamOverrules);
    }
}
