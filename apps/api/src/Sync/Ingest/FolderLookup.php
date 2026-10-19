<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use App\Entity\Folder;
use App\Entity\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class FolderLookup
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function get(MailAccount $account, string $remoteName): Folder
    {
        return $this->entityManager->getRepository(Folder::class)->findOneBy(['mailAccount' => $account, 'remoteName' => $remoteName])
            ?? throw new UnprocessableEntityHttpException(\sprintf('Unknown folder "%s"; report the folder list first.', $remoteName));
    }
}
