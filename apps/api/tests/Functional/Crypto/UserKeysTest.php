<?php

declare(strict_types=1);

namespace App\Tests\Functional\Crypto;

use App\Entity\User;
use App\Security\Crypto\CryptoException;
use App\Security\Crypto\KeyRing;
use App\Security\Crypto\UserKeys;
use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\StatementHook;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class UserKeysTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        StatementHook::reset();
        parent::tearDown();
    }

    public function testCreatesKeysOnFirstUseAndKeepsThem(): void
    {
        $user = $this->user('kim@example.test');

        $first = $this->keys()->current($user);
        $again = $this->freshKeys()->current($user);

        self::assertSame(1, $first->version);
        self::assertSame($first->key, $again->key, 'Another process reads the same key.');
        self::assertSame($this->keys()->indexKey($user), $this->freshKeys()->indexKey($user));
        $wrapped = $this->connection()->fetchOne('SELECT wrapped_key FROM user_key WHERE owner_id = :id', ['id' => $user->toRfc4122()]);
        self::assertIsString($wrapped);
        self::assertStringStartsWith('v1.test.', $wrapped);
        self::assertStringNotContainsString(base64_encode($first->key), $wrapped);
    }

    public function testConcurrentFirstUsesAgreeOnOneKey(): void
    {
        $user = $this->user('kim@example.test');
        $theirs = null;
        // Another process creates the keys after this one found none, right before this one inserts its own.
        StatementHook::before('INSERT INTO user_key', function () use ($user, &$theirs): void {
            $theirs = $this->freshKeys()->current($user)->key;
        });

        $mine = $this->keys()->current($user)->key;

        self::assertNotNull($theirs);
        self::assertSame($theirs, $mine, 'This process takes the key the other one stored.');
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM user_key WHERE owner_id = :id', ['id' => $user->toRfc4122()]));
    }

    public function testTheDataKeyFromBeforeBecomesVersionOne(): void
    {
        $user = $this->user('kim@example.test');
        $dataKey = random_bytes(32);
        $this->connection()->executeStatement('UPDATE app_user SET wrapped_data_key = :wrapped WHERE id = :id', [
            'wrapped' => static::getContainer()->get(KeyRing::class)->wrap($dataKey, 'tresso:user-data-key:'.$user->toRfc4122()),
            'id' => $user->toRfc4122(),
        ]);

        self::assertSame($dataKey, $this->keys()->version($user, 1));
        self::assertNull($this->connection()->fetchOne('SELECT wrapped_data_key FROM app_user WHERE id = :id', ['id' => $user->toRfc4122()]), 'Cleared once it is version 1.');
    }

    public function testRotationAddsACurrentVersionAndKeepsTheOlderOne(): void
    {
        $user = $this->user('kim@example.test');
        $old = $this->keys()->current($user);

        self::assertSame(2, $this->keys()->rotate($user, new \DateTimeImmutable()));

        $current = $this->keys()->current($user);
        self::assertSame(2, $current->version);
        self::assertNotSame($old->key, $current->key);
        self::assertSame($old->key, $this->keys()->version($user, 1));
        self::assertSame([1, 2], $this->keys()->versions($user));
    }

    public function testOlderVersionsRetireOnlyOnceTheCurrentOneOutlastsTheWorkersCaches(): void
    {
        $user = $this->user('kim@example.test');
        $this->keys()->current($user);
        $this->keys()->rotate($user, new \DateTimeImmutable());

        self::assertSame([], $this->keys()->retireOlderVersions($user, new \DateTimeImmutable('+14 minutes'), static fn (): bool => true));
        self::assertSame([1], $this->keys()->retireOlderVersions($user, new \DateTimeImmutable('+16 minutes'), static fn (): bool => true));

        self::assertSame([2], $this->keys()->versions($user));
        $this->expectException(CryptoException::class);
        $this->keys()->version($user, 1);
    }

    public function testOlderVersionsStayWhileSomethingStillUsesThem(): void
    {
        $user = $this->user('kim@example.test');
        $this->keys()->current($user);
        $this->keys()->rotate($user, new \DateTimeImmutable());

        self::assertSame([], $this->keys()->retireOlderVersions($user, new \DateTimeImmutable('+1 hour'), static fn (): bool => false));
        self::assertSame([1, 2], $this->keys()->versions($user));
    }

    public function testShreddingDeletesEveryKey(): void
    {
        $user = $this->user('kim@example.test');
        $this->keys()->current($user);
        $this->keys()->rotate($user, new \DateTimeImmutable());

        $this->keys()->shred($user);

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM user_key WHERE owner_id = :id', ['id' => $user->toRfc4122()]));
        self::assertSame([null, null], array_values((array) $this->connection()->fetchAssociative('SELECT wrapped_index_key, wrapped_data_key FROM app_user WHERE id = :id', ['id' => $user->toRfc4122()])));
    }

    public function testFingerprintsOfTheSameContentDifferPerUser(): void
    {
        $kim = $this->user('kim@example.test');
        $lisa = $this->user('lisa@example.test');
        $sha = hash('sha256', 'the same invoice', true);

        self::assertNotSame($this->keys()->fingerprint($kim, $sha), $this->keys()->fingerprint($lisa, $sha));
        self::assertSame($this->keys()->fingerprint($kim, $sha), $this->freshKeys()->fingerprint($kim, $sha));
    }

    public function testDerivedKeysDifferFromTheUserKeyAndEachOther(): void
    {
        $key = $this->keys()->current($this->user('kim@example.test'))->key;

        self::assertCount(3, array_unique([$key, UserKeys::blobKey($key), UserKeys::fieldKey($key)]));
    }

    public function testAWrappedKeyCopiedToAnotherUserDoesNotOpen(): void
    {
        $kim = $this->user('kim@example.test');
        $lisa = $this->user('lisa@example.test');
        $this->keys()->current($kim);
        $this->keys()->current($lisa);
        $this->connection()->executeStatement('UPDATE user_key SET wrapped_key = (SELECT wrapped_key FROM user_key WHERE owner_id = :kim) WHERE owner_id = :lisa', ['kim' => $kim->toRfc4122(), 'lisa' => $lisa->toRfc4122()]);

        $this->expectException(CryptoException::class);
        $this->freshKeys()->current($lisa);
    }

    private function user(string $email): Uuid
    {
        $user = new User($email, 'User');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user->getId();
    }

    private function keys(): UserKeys
    {
        return static::getContainer()->get(UserKeys::class);
    }

    /** Another process: nothing cached. */
    private function freshKeys(): UserKeys
    {
        return new UserKeys(static::getContainer()->get(Connection::class), static::getContainer()->get(KeyRing::class));
    }
}
