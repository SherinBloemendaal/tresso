<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\Channel\VapidKeys;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a new VAPID key pair for Web Push, to put in the environment once. Changing it later makes every browser
 * subscription invalid, so browsers subscribe again.
 */
#[AsCommand(name: 'app:notifications:vapid-keys', description: 'Generate a VAPID key pair for Web Push')]
final class GenerateVapidKeysCommand
{
    public function __invoke(OutputInterface $output): int
    {
        $keys = VapidKeys::generate();
        $output->writeln('WEB_PUSH_PUBLIC_KEY='.$keys['public']);
        $output->writeln('WEB_PUSH_PRIVATE_KEY='.$keys['private']);
        $output->writeln('WEB_PUSH_SUBJECT=mailto:you@example.com');

        return Command::SUCCESS;
    }
}
