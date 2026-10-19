<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\CreateSignature;
use App\ApiResource\Input\UpdateSignature;
use App\ApiResource\SignatureResource;
use App\Entity\MailAccount;
use App\Entity\Signature;
use App\Entity\User;
use App\Mail\Body\SignatureHtmlSanitizer;
use App\Repository\MailAccountRepository;
use App\Repository\SignatureRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Creates, updates and deletes the signed-in user's signatures, sanitizing the HTML on the way in, and moves the
 * default signature of the user's mail accounts.
 *
 * @implements ProcessorInterface<CreateSignature|UpdateSignature|SignatureResource, SignatureResource|null>
 */
final readonly class SignatureProcessor implements ProcessorInterface
{
    public function __construct(
        private SignatureRepository $signatures,
        private MailAccountRepository $accounts,
        private CurrentUser $currentUser,
        private SignatureHtmlSanitizer $sanitizer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?SignatureResource
    {
        // One transaction per write, so change numbers for client sync commit in order.
        return $this->entityManager->wrapInTransaction(fn (): ?SignatureResource => $this->write($data, $operation, $uriVariables));
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function write(mixed $data, Operation $operation, array $uriVariables): ?SignatureResource
    {
        $owner = $this->currentUser->get();
        $accounts = $this->accounts->findOwnedBy($owner);

        if ($data instanceof CreateSignature) {
            $signature = new Signature($owner, trim($data->name), $this->sanitizer->sanitize($data->html));
            $this->entityManager->persist($signature);
            $this->useAsDefaultFor($signature, $accounts, $data->defaultFor, $data);
            $this->entityManager->flush();

            return SignatureResource::fromEntity($signature, $accounts);
        }

        $signature = $this->ownedSignature($owner, $uriVariables);

        if ($operation instanceof DeleteOperationInterface) {
            // Cleared here rather than by the foreign key, so the accounts reach clients as changed.
            $this->useAsDefaultFor($signature, $accounts, [], $data);
            $this->entityManager->remove($signature);
            $this->entityManager->flush();

            return null;
        }

        if (!$data instanceof UpdateSignature) {
            throw new \LogicException(\sprintf('Unexpected input %s for %s.', get_debug_type($data), $operation->getName()));
        }

        $signature->update(
            null === $data->name ? $signature->getName() : trim($data->name),
            null === $data->html ? $signature->getHtml() : $this->sanitizer->sanitize($data->html),
        );
        if (null !== $data->defaultFor) {
            $this->useAsDefaultFor($signature, $accounts, $data->defaultFor, $data);
        }
        $this->entityManager->flush();

        return SignatureResource::fromEntity($signature, $accounts);
    }

    /**
     * Makes the signature the default of exactly the given accounts.
     *
     * @param list<MailAccount> $accounts   all of the owner's accounts
     * @param list<string>      $accountIds
     */
    private function useAsDefaultFor(Signature $signature, array $accounts, array $accountIds, mixed $input): void
    {
        $byId = [];
        foreach ($accounts as $account) {
            $byId[$account->getId()->toRfc4122()] = $account;
        }

        $selected = [];
        foreach ($accountIds as $index => $id) {
            $account = $byId[strtolower($id)] ?? throw new ValidationException(new ConstraintViolationList([
                new ConstraintViolation('This mail account does not exist.', null, [], $input, \sprintf('defaultFor[%d]', $index), $id),
            ]));
            $selected[spl_object_id($account)] = $account;
        }

        foreach ($accounts as $account) {
            if (isset($selected[spl_object_id($account)])) {
                $account->setDefaultSignature($signature);
                continue;
            }
            if ($account->getDefaultSignature() === $signature) {
                $account->setDefaultSignature(null);
            }
        }
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function ownedSignature(User $owner, array $uriVariables): Signature
    {
        $id = $uriVariables['id'] ?? null;

        return (\is_string($id) ? $this->signatures->findOneOwnedBy($owner, $id) : null)
            ?? throw new NotFoundHttpException('Signature not found.');
    }
}
