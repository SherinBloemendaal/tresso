<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Sync\FolderInput;
use App\Entity\Folder;
use App\Entity\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Makes the stored folders match the server's folder list.
 *
 * - New folders are created, renamed ones keep their messages (the remote name is the key).
 * - Folders missing from the list were deleted on the server: they go, with their message locations.
 * - A changed UIDVALIDITY invalidates every UID in that folder: its locations are dropped and the cursor reset, so
 *   the worker downloads the folder again (messages that still exist elsewhere are linked, not duplicated).
 */
final readonly class FolderSynchronizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageJanitor $janitor,
    ) {
    }

    /**
     * @param list<FolderInput> $inputs
     *
     * @return list<FolderState>
     */
    public function sync(MailAccount $account, array $inputs): array
    {
        $this->assertUniqueNames($inputs);

        /** @var array<string, Folder> $existing */
        $existing = [];
        foreach ($this->entityManager->getRepository(Folder::class)->findBy(['mailAccount' => $account]) as $folder) {
            $existing[$folder->getRemoteName()] = $folder;
        }

        $states = [];
        foreach ($inputs as $input) {
            $folder = $existing[$input->remoteName] ?? null;
            unset($existing[$input->remoteName]);

            if (null === $folder) {
                $folder = new Folder($account, $input->remoteName, $input->displayName, $input->role);
                $folder->updateSyncState($input->uidValidity, null);
                $this->entityManager->persist($folder);
                $states[] = new FolderState($input->remoteName, $input->uidValidity, null, false);
                continue;
            }

            $folder->rename($input->displayName, $input->role);
            $reset = null !== $folder->getUidValidity() && null !== $input->uidValidity && $folder->getUidValidity() !== $input->uidValidity;
            if ($reset) {
                $this->entityManager->createQuery('DELETE FROM App\Entity\MessageLocation l WHERE l.folder = :folder')
                    ->setParameter('folder', $folder->getId(), 'uuid')
                    ->execute();
                $folder->updateSyncState($input->uidValidity, null);
            } elseif (null === $folder->getUidValidity() && null !== $input->uidValidity) {
                $folder->updateSyncState($input->uidValidity, $folder->getSyncCursor());
            }

            $states[] = new FolderState($input->remoteName, $folder->getUidValidity(), $folder->getSyncCursor(), $reset);
        }

        foreach ($existing as $deleted) {
            $this->entityManager->remove($deleted);
        }

        $this->entityManager->flush();
        $this->janitor->removeOrphans($account);

        return $states;
    }

    /**
     * @param list<FolderInput> $inputs
     */
    private function assertUniqueNames(array $inputs): void
    {
        $seen = [];
        foreach ($inputs as $index => $input) {
            if (isset($seen[$input->remoteName])) {
                throw new ValidationException(new ConstraintViolationList([
                    new ConstraintViolation('Folder names must be unique.', null, [], $inputs, \sprintf('folders[%d].remoteName', $index), $input->remoteName),
                ]));
            }
            $seen[$input->remoteName] = true;
        }
    }
}
