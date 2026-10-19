<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\UserProvisioner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:create', description: 'Create a user with default settings and built-in categories')]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserProvisioner $provisioner,
        private UserRepository $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Login email address')] string $email,
        #[Argument(description: 'Display name')] string $name,
    ): int {
        if (null !== $this->users->findOneByEmail($email)) {
            $io->error(\sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $password = $io->askHidden('Password (min. 12 characters)', static function (mixed $value): string {
            if (!\is_string($value) || mb_strlen($value) < 12) {
                throw new \RuntimeException('The password must be at least 12 characters.');
            }

            return $value;
        });

        \assert(\is_string($password));
        $user = $this->provisioner->provision($email, $name, $password);
        $io->success(\sprintf('Created user %s (%s).', $user->getEmail(), $user->getId()->toRfc4122()));

        return Command::SUCCESS;
    }
}
