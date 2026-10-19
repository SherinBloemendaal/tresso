<?php

declare(strict_types=1);

namespace App\Classification;

/**
 * Asks for a user's conversations to be classified again, e.g. after their smart inboxes changed: those with mail
 * since `since` (all of them when null), and those whose smart inbox was deleted; only those with mail from `sender`
 * (an address or `@domain`) when given. Conversations the user put in a smart inbox themselves stay where they are.
 * A long run goes on in a new message from thread id `after`.
 */
final readonly class ReclassifyMail
{
    /**
     * @param string|null $since ATOM date
     */
    public function __construct(
        public string $userId,
        public ?string $since,
        public ?string $sender = null,
        public ?string $after = null,
    ) {
    }
}
