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
         * The sender's mail fails authentication (DMARC with a reject or quarantine policy), so the rule does not apply
         * to it: such mail goes to Spam, because anyone could have written that From address. The corrected
         * conversation stays where the user put it.
         */
        public bool $failsAuthentication,
    ) {
    }

    public static function fromEntity(SenderRule $rule, bool $failsAuthentication): self
    {
        return new self($rule->getId()->toRfc4122(), $rule->getSender(), $rule->getVerdict()?->value, $failsAuthentication);
    }
}
