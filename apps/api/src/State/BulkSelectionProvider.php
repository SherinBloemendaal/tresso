<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\BulkSelectionResource;
use App\ApiResource\Input\Bulk\BulkScopeInput;
use App\Bulk\BulkScopeResolver;
use App\Bulk\BulkSelection;
use App\Enum\BulkArea;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @implements ProviderInterface<BulkSelectionResource>
 */
final readonly class BulkSelectionProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private BulkScopeResolver $scopes,
        private BulkSelection $selection,
        private RequestStack $requests,
        private ValidatorInterface $validator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BulkSelectionResource
    {
        $input = $this->input($this->requests->getCurrentRequest() ?? throw new \LogicException('Provided for a request.'));
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        $owner = $this->currentUser->get();
        $now = new \DateTimeImmutable();
        $count = $this->selection->count($owner, $this->scopes->resolve($owner, $input, $now), Uuid::v7());

        return new BulkSelectionResource($count['conversations'], $count['messages'], $count['mailAccountIds']);
    }

    private function input(Request $request): BulkScopeInput
    {
        $query = $request->query;
        $input = new BulkScopeInput();
        $in = $query->getString('in');
        $input->in = BulkArea::tryFrom($in);
        if ('' !== $in && null === $input->in) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('Choose smartInbox, inbox or mailbox.', null, [], $input, 'in', $in)]));
        }
        $input->smartInboxId = self::text($query->getString('smartInboxId'));
        $input->mailAccountId = self::text($query->getString('mailAccountId'));
        $input->sender = self::text($query->getString('sender'));
        $input->olderThanDays = self::number($query->getString('olderThanDays'), 'olderThanDays', $input);
        $input->largerThan = self::number($query->getString('largerThan'), 'largerThan', $input);

        return $input;
    }

    private static function text(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    private static function number(string $value, string $field, BulkScopeInput $input): ?int
    {
        if ('' === $value) {
            return null;
        }
        if (1 !== preg_match('/^\d{1,15}$/', $value)) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('This is not a whole number.', null, [], $input, $field, $value)]));
        }

        return (int) $value;
    }
}
