<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\RotateDataKeysCommand;
use App\Entity\User;
use App\Security\Crypto\KeyRing;
use App\Security\Crypto\UserKeys;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class RotateDataKeysCommandTest extends DatabaseTestCase
{
    public function testRewrapsEveryUserKeyWithTheCurrentMasterKey(): void
    {
        $oldKey = base64_encode(random_bytes(32));
        $newKey = base64_encode(random_bytes(32));
        $connection = static::getContainer()->get(Connection::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $before = new UserKeys($connection, new KeyRing('old:'.$oldKey, 'old'));
        $keys = [];
        foreach (['kim@example.test', 'lisa@example.test'] as $email) {
            $user = new User($email, 'User');
            $entityManager->persist($user);
            $entityManager->flush();
            $before->rotate($user->getId(), new \DateTimeImmutable());
            $keys[$email] = ['id' => $user->getId(), 'versions' => [1 => $before->version($user->getId(), 1), 2 => $before->version($user->getId(), 2)], 'index' => $before->indexKey($user->getId())];
        }
        $connection->executeStatement("INSERT INTO scan_result (content_key, key_id, status, engine, scanned_at) VALUES ('a', 'old', 'clean', 'x', NOW()), ('b', NULL, 'clean', 'x', NOW()), ('c', 'new', 'clean', 'x', NOW())");

        $afterRing = new KeyRing('old:'.$oldKey.',new:'.$newKey, 'new');
        $application = new Application();
        $application->addCommand(new Command(null, new RotateDataKeysCommand($connection, new UserKeys($connection, $afterRing), $afterRing)));
        $tester = new CommandTester($application->find('app:crypto:rotate-data-keys'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Re-wrapped 6 keys', $tester->getDisplay(), 'Two versions and an index key per user.');
        self::assertSame(['c'], $connection->fetchFirstColumn('SELECT content_key FROM scan_result'), 'Cached verdicts of other master keys are dropped.');

        $onlyNew = new UserKeys($connection, new KeyRing('new:'.$newKey, 'new'));
        foreach ($keys as $user) {
            self::assertInstanceOf(Uuid::class, $user['id']);
            self::assertSame($user['versions'][1], $onlyNew->version($user['id'], 1), 'The user keys themselves are unchanged.');
            self::assertSame($user['versions'][2], $onlyNew->version($user['id'], 2));
            self::assertSame($user['index'], $onlyNew->indexKey($user['id']));
        }
    }
}
