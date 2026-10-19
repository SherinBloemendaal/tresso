<?php

declare(strict_types=1);

namespace App\Service;

use App\Calendar\LocalCalendars;
use App\Classification\BuiltinSmartInboxes;
use App\Entity\User;
use App\Entity\UserSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a user together with everything a new account needs: settings, the built-in smart inboxes and the local
 * Tresso calendar.
 */
final readonly class UserProvisioner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private BuiltinSmartInboxes $builtinSmartInboxes,
    ) {
    }

    public function provision(string $email, string $displayName, #[\SensitiveParameter] string $plainPassword): User
    {
        $user = new User($email, $displayName);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->persist($user);
        $this->entityManager->persist(new UserSettings($user));
        $this->entityManager->persist(LocalCalendars::create($user));

        $this->builtinSmartInboxes->ensure($user);

        $this->entityManager->flush();

        return $user;
    }
}
