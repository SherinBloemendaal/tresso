<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\RotateDataKeysCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Crypto\DataKeyManager;
use App\Security\Crypto\KeyRing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RotateDataKeysCommandTest extends DatabaseTestCase
{
    public function testRewrapsEveryUserWithTheCurrentKey(): void
    {
        $oldKey = base64_encode(random_bytes(32));
        $newKey = base64_encode(random_bytes(32));
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users = static::getContainer()->get(UserRepository::class);

        $before = new DataKeyManager(new KeyRing('old:'.$oldKey, 'old'));
        $dataKeys = [];
        foreach (['kim@example.test', 'lisa@example.test', 'pieter@example.test'] as $email) {
            $user = new User($email, 'User');
            $dataKeys[$email] = $before->dataKeyFor($user);
            $entityManager->persist($user);
        }
        $withoutKey = new User('nokey@example.test', 'No key yet');
        $entityManager->persist($withoutKey);
        $entityManager->flush();

        $after = new DataKeyManager(new KeyRing('old:'.$oldKey.',new:'.$newKey, 'new'));
        $application = new Application();
        $application->addCommand(new Command(null, new RotateDataKeysCommand($users, $after, $entityManager)));
        $tester = new CommandTester($application->find('app:crypto:rotate-data-keys'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Re-wrapped 3 of 4 data keys.', $tester->getDisplay());

        $onlyNew = new DataKeyManager(new KeyRing('new:'.$newKey, 'new'));
        foreach ($dataKeys as $email => $dataKey) {
            $user = $users->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);
            self::assertStringStartsWith('v1.new.', (string) $user->getWrappedDataKey());
            self::assertSame($dataKey, $onlyNew->dataKeyFor($user), 'The data key itself is unchanged.');
        }
        self::assertNull($users->findOneByEmail('nokey@example.test')?->getWrappedDataKey());
    }
}
