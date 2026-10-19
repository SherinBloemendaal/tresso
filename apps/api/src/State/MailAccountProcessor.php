<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\CreateMailAccount;
use App\ApiResource\Input\UpdateMailAccount;
use App\ApiResource\MailAccountResource;
use App\Entity\MailAccount;
use App\Entity\User;
use App\Enum\MailAccountStatus;
use App\Enum\MailProvider;
use App\Repository\MailAccountRepository;
use App\Security\CurrentUser;
use App\Security\Crypto\CredentialVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Creates, updates and deletes the signed-in user's mail accounts. Passwords go straight into the credential vault.
 *
 * @implements ProcessorInterface<CreateMailAccount|UpdateMailAccount|MailAccountResource, MailAccountResource|null>
 */
final readonly class MailAccountProcessor implements ProcessorInterface
{
    public function __construct(
        private MailAccountRepository $accounts,
        private CurrentUser $currentUser,
        private CredentialVault $vault,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?MailAccountResource
    {
        // One transaction per write, so change numbers for client sync commit in order.
        return $this->entityManager->wrapInTransaction(fn (): ?MailAccountResource => $this->write($data, $operation, $uriVariables));
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function write(mixed $data, Operation $operation, array $uriVariables): ?MailAccountResource
    {
        $owner = $this->currentUser->get();

        if ($data instanceof CreateMailAccount) {
            return $this->create($owner, $data);
        }

        $account = $this->ownedAccount($owner, $uriVariables);

        if ($operation instanceof DeleteOperationInterface) {
            $this->entityManager->remove($account);
            $this->entityManager->flush();

            return null;
        }

        if (!$data instanceof UpdateMailAccount) {
            throw new \LogicException(\sprintf('Unexpected input %s for %s.', get_debug_type($data), $operation->getName()));
        }

        return $this->update($account, $data);
    }

    private function create(User $owner, CreateMailAccount $input): MailAccountResource
    {
        if ($this->accounts->existsForOwner($owner, $input->emailAddress)) {
            throw new ValidationException(new ConstraintViolationList([
                new ConstraintViolation('You already connected this email address.', null, [], $input, 'emailAddress', $input->emailAddress),
            ]));
        }

        $imap = $input->imap ?? throw new \LogicException('Validation guarantees IMAP settings.');
        $smtp = $input->smtp ?? throw new \LogicException('Validation guarantees SMTP settings.');

        $account = new MailAccount($owner, $input->emailAddress, $input->displayName, MailProvider::Imap);
        $account->configureServers($imap, $smtp, $input->username);
        $this->vault->seal($account, ['type' => 'password', 'password' => $input->password]);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return MailAccountResource::fromEntity($account);
    }

    private function update(MailAccount $account, UpdateMailAccount $input): MailAccountResource
    {
        if (null !== $input->displayName) {
            $account->setDisplayName($input->displayName);
        }

        if (null !== $input->imap || null !== $input->smtp || null !== $input->username) {
            $account->configureServers(
                $input->imap ?? $account->getImap(),
                $input->smtp ?? $account->getSmtp(),
                $input->username ?? $account->getUsername(),
            );
        }

        if (null !== $input->password) {
            $this->vault->seal($account, ['type' => 'password', 'password' => $input->password]);
            if (MailAccountStatus::AuthFailed === $account->getStatus()) {
                $account->setStatus(MailAccountStatus::Active);
            }
        }

        if (null !== $input->status) {
            $account->setStatus($input->status);
        }

        $this->entityManager->flush();

        return MailAccountResource::fromEntity($account);
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function ownedAccount(User $owner, array $uriVariables): MailAccount
    {
        $id = $uriVariables['id'] ?? null;

        return (\is_string($id) ? $this->accounts->findOneOwnedBy($owner, $id) : null)
            ?? throw new NotFoundHttpException('Mail account not found.');
    }
}
