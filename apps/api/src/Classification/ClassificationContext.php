<?php

declare(strict_types=1);

namespace App\Classification;

use App\Entity\Category;
use App\Entity\SenderRule;
use App\Enum\BuiltinCategory;

/**
 * Everything about the user a classifier needs, loaded once per run: their addresses, smart inboxes and remembered
 * corrections.
 */
final readonly class ClassificationContext
{
    /** @var array<string, true> */
    private array $ownAddresses;

    /** @var array<string, SenderRule> */
    private array $senderRules;

    /**
     * @param list<string>     $ownAddresses the user's mail account addresses
     * @param list<Category>   $smartInboxes in sidebar order
     * @param list<SenderRule> $senderRules
     */
    public function __construct(array $ownAddresses, public array $smartInboxes, array $senderRules)
    {
        $own = [];
        foreach ($ownAddresses as $address) {
            $own[mb_strtolower($address)] = true;
        }
        $this->ownAddresses = $own;

        $rules = [];
        foreach ($senderRules as $rule) {
            $rules[$rule->getSender()] = $rule;
        }
        $this->senderRules = $rules;
    }

    public function isOwnAddress(string $address): bool
    {
        return isset($this->ownAddresses[mb_strtolower($address)]);
    }

    /** @return list<string> */
    public function ownAddresses(): array
    {
        return array_keys($this->ownAddresses);
    }

    /** The most specific remembered correction for this sender: the address, then its domain, then parent domains. */
    public function senderRule(string $address): ?SenderRule
    {
        foreach (SenderPattern::lookupKeys($address) as $key) {
            if (isset($this->senderRules[$key])) {
                return $this->senderRules[$key];
            }
        }

        return null;
    }

    public function builtin(BuiltinCategory $builtin): ?Category
    {
        foreach ($this->smartInboxes as $smartInbox) {
            if ($smartInbox->getBuiltin() === $builtin) {
                return $smartInbox;
            }
        }

        return null;
    }

    /**
     * Smart inboxes in the order their include lists are checked: the user's own first, then the built-in ones, each
     * in sidebar order.
     *
     * @return list<Category>
     */
    public function includeOrder(): array
    {
        $custom = array_filter($this->smartInboxes, static fn (Category $c): bool => !$c->isBuiltin());
        $builtin = array_filter($this->smartInboxes, static fn (Category $c): bool => $c->isBuiltin());

        return [...array_values($custom), ...array_values($builtin)];
    }
}
