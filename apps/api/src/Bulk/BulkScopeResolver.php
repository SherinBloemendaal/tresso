<?php

declare(strict_types=1);

namespace App\Bulk;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Bulk\BulkScopeInput;
use App\Entity\User;
use App\Enum\BulkArea;
use App\Repository\CategoryRepository;
use App\Repository\MailAccountRepository;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/** Turns a validated scope from a request into a {@see BulkScope} of the user's own smart inbox and account. */
final readonly class BulkScopeResolver
{
    public function __construct(
        private CategoryRepository $smartInboxes,
        private MailAccountRepository $accounts,
    ) {
    }

    /**
     * @throws ValidationException when the smart inbox or account is not the user's
     */
    public function resolve(User $owner, BulkScopeInput $input, \DateTimeImmutable $now): BulkScope
    {
        $area = $input->in ?? throw new \LogicException('Validation guarantees an area.');
        $violations = new ConstraintViolationList();

        $smartInbox = null;
        if (null !== $input->smartInboxId) {
            $smartInbox = $this->smartInboxes->findOneOwnedBy($owner, $input->smartInboxId);
            if (null === $smartInbox) {
                $violations->add(new ConstraintViolation('This smart inbox does not exist.', null, [], $input, 'smartInboxId', $input->smartInboxId));
            }
        }
        $account = null;
        if (null !== $input->mailAccountId) {
            $account = $this->accounts->findOneOwnedBy($owner, $input->mailAccountId);
            if (null === $account) {
                $violations->add(new ConstraintViolation('This account does not exist.', null, [], $input, 'mailAccountId', $input->mailAccountId));
            }
        }
        if (\count($violations) > 0 || (BulkArea::SmartInbox === $area && null === $smartInbox)) {
            throw new ValidationException($violations);
        }

        return new BulkScope(
            $area,
            $smartInbox,
            $account,
            null === $input->sender ? null : mb_strtolower(trim($input->sender)),
            null === $input->olderThanDays ? null : $now->modify(\sprintf('-%d days', $input->olderThanDays)),
            $input->largerThan,
        );
    }
}
