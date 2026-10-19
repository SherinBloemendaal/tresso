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
    ) {
    }

    public static function fromEntity(SenderRule $rule): self
    {
        return new self($rule->getId()->toRfc4122(), $rule->getSender(), $rule->getVerdict()?->value);
    }
}
