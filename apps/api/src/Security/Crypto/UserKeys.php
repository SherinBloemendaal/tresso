<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Per-user keys: numbered user keys (the highest is current) and one index key, all wrapped by the master keys in the
 * KeyRing. Nothing encrypts with a user key directly: {@see blobKey()} and {@see fieldKey()} derive the keys for
 * objects and database fields, so the sync worker can be given the first without being able to open the second.
 *
 * Keys are created only to write something new ({@see current()}, {@see indexKey()}, {@see ensure()}), in SQL under a
 * lock on the user with ON CONFLICT DO NOTHING, so concurrent first uses agree on one key. Reading never creates keys:
 * a user without keys has nothing readable. Once a user's keys are shredded they are never created again: writing
 * fails instead (fail closed). A data key from before (user.wrapped_data_key) becomes version 1, which keeps
 * credentials sealed with it opening.
 *
 * Unwrapped keys are kept for the rest of the request or message; {@see reset()} wipes them.
 */
final class UserKeys implements ResetInterface
{
    /**
     * How old the current version must be before older ones may go: longer than a sync worker keeps keys (5 minutes),
     * so nothing new is written with an older version once it is retired.
     */
    public const string RETIRE_GRACE = 'PT15M';

    /** @var array<string, array{keys: array<int, string>, current: int, currentSince: \DateTimeImmutable, index: string}> */
    private array $loaded = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly KeyRing $keyRing,
    ) {
    }

    /** The key new data is written with; creates the owner's keys if they have none yet. */
    public function current(Uuid $owner): KeyVersion
    {
        $set = $this->load($owner, create: true);

        return new KeyVersion($set['current'], $set['keys'][$set['current']]);
    }

    /** The user key of $version, which the owner must still have. */
    public function version(Uuid $owner, int $version): string
    {
        return $this->load($owner)['keys'][$version] ?? throw new CryptoException(\sprintf('The user has no key version %d.', $version));
    }

    /**
     * Every version the owner still has, oldest first.
     *
     * @return list<int>
     */
    public function versions(Uuid $owner): array
    {
        return array_keys($this->load($owner)['keys']);
    }

    /** Whether the owner has keys, without creating any. */
    public function hasKeys(Uuid $owner): bool
    {
        return isset($this->loaded[$owner->toRfc4122()]) || null !== $this->read($owner);
    }

    public function currentSince(Uuid $owner): \DateTimeImmutable
    {
        return $this->load($owner)['currentSince'];
    }

    /** Names content, also content about to be written; creates the owner's keys if they have none yet. */
    public function indexKey(Uuid $owner): string
    {
        return $this->load($owner, create: true)['index'];
    }

    /** Names content within one owner: hex(HMAC-SHA256(index key, SHA-256 of the content)). */
    public function fingerprint(Uuid $owner, string $sha256): string
    {
        if (32 !== \strlen($sha256)) {
            throw new \InvalidArgumentException('A fingerprint is taken of a raw 32-byte SHA-256.');
        }

        return hash_hmac('sha256', $sha256, $this->indexKey($owner));
    }

    /** The key objects are encrypted with; also what a sync worker gets. */
    public static function blobKey(#[\SensitiveParameter] string $userKey): string
    {
        return hash_hkdf('sha256', $userKey, 32, 'tresso:blob-key:v1');
    }

    /** The key database fields and credentials are sealed with. */
    public static function fieldKey(#[\SensitiveParameter] string $userKey): string
    {
        return hash_hkdf('sha256', $userKey, 32, 'tresso:field-key:v1');
    }

    /** Creates the owner's keys if they have none yet. */
    public function ensure(Uuid $owner): void
    {
        $this->load($owner, create: true);
    }

    /** Adds a new current version; new data uses it at once. Returns the new version. */
    public function rotate(Uuid $owner, \DateTimeImmutable $now): int
    {
        $version = $this->connection->transactional(function () use ($owner, $now): int {
            $this->lockOwner($owner);
            $this->forget($owner);
            $next = $this->load($owner, create: true)['current'] + 1;
            $key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
            $this->insertVersion($owner, $next, $key, $now);
            sodium_memzero($key);

            return $next;
        });
        $this->forget($owner);

        return $version;
    }

    /**
     * Deletes the versions older than the current one, once the current one is old enough ({@see RETIRE_GRACE}) and
     * $unused, asked while the owner is locked, confirms nothing uses them any more. Ingest locks the owner shared
     * while it checks and stores key versions ({@see lockOwnerShared()}), so nothing new can name an older version in
     * between. Returns the versions deleted.
     *
     * @param callable(): bool $unused
     *
     * @return list<int>
     */
    public function retireOlderVersions(Uuid $owner, \DateTimeImmutable $now, callable $unused): array
    {
        $retired = $this->connection->transactional(function () use ($owner, $now, $unused): array {
            $this->lockOwner($owner);
            $this->forget($owner);
            $set = $this->load($owner);
            if ($set['currentSince'] > $now->sub(new \DateInterval(self::RETIRE_GRACE)) || !$unused()) {
                return [];
            }
            $older = array_values(array_filter(array_keys($set['keys']), static fn (int $version): bool => $version < $set['current']));
            if ([] !== $older) {
                $this->connection->executeStatement('DELETE FROM user_key WHERE owner_id = :owner AND version < :current', ['owner' => $owner->toRfc4122(), 'current' => $set['current']]);
            }

            return $older;
        });
        $this->forget($owner);

        return $retired;
    }

    /**
     * Crypto-shredding: deletes every key of the owner and marks them, so no keys are created for them again. Once this
     * commits, what was encrypted with those keys cannot be decrypted any more, also in copies.
     */
    public function shred(Uuid $owner): void
    {
        $this->connection->transactional(function () use ($owner): void {
            // The same lock creating keys takes: keys created meanwhile are deleted here, and none come after.
            $this->lockOwner($owner);
            // Marked first: whatever wants keys after this point is refused, also within this transaction.
            $this->connection->executeStatement(
                'UPDATE app_user SET wrapped_index_key = NULL, wrapped_data_key = NULL, keys_shredded_at = :now WHERE id = :owner',
                ['owner' => $owner->toRfc4122(), 'now' => new \DateTimeImmutable()],
                ['now' => Types::DATETIME_IMMUTABLE],
            );
            $this->connection->executeStatement('DELETE FROM user_key WHERE owner_id = :owner', ['owner' => $owner->toRfc4122()]);
        });
        $this->forget($owner);
    }

    /**
     * Re-wraps the owner's keys that are not wrapped with the current master key. Returns how many were re-wrapped.
     */
    public function rewrap(Uuid $owner): int
    {
        $id = $owner->toRfc4122();
        $count = 0;
        /** @var list<array{id: string, version: int, wrapped_key: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, version, wrapped_key FROM user_key WHERE owner_id = :owner', ['owner' => $id]);
        foreach ($rows as $row) {
            if ($this->keyRing->isCurrent($row['wrapped_key'])) {
                continue;
            }
            $additionalData = self::userKeyContext($owner, $row['version']);
            $key = $this->keyRing->unwrap($row['wrapped_key'], $additionalData);
            $count += (int) $this->connection->executeStatement(
                'UPDATE user_key SET wrapped_key = :new WHERE id = :id AND wrapped_key = :old',
                ['new' => $this->keyRing->wrap($key, $additionalData), 'id' => $row['id'], 'old' => $row['wrapped_key']],
            );
            sodium_memzero($key);
        }

        /** @var array{wrapped_index_key: ?string, wrapped_data_key: ?string}|false $user */
        $user = $this->connection->fetchAssociative('SELECT wrapped_index_key, wrapped_data_key FROM app_user WHERE id = :owner', ['owner' => $id]);
        if (false === $user) {
            return $count;
        }
        foreach (['wrapped_index_key' => self::indexKeyContext($owner), 'wrapped_data_key' => self::legacyContext($owner)] as $column => $additionalData) {
            $wrapped = $user[$column];
            if (null === $wrapped || $this->keyRing->isCurrent($wrapped)) {
                continue;
            }
            $key = $this->keyRing->unwrap($wrapped, $additionalData);
            $count += (int) $this->connection->executeStatement(
                "UPDATE app_user SET {$column} = :new WHERE id = :owner AND {$column} = :old",
                ['new' => $this->keyRing->wrap($key, $additionalData), 'owner' => $id, 'old' => $wrapped],
            );
            sodium_memzero($key);
        }
        $this->forget($owner);

        return $count;
    }

    public function reset(): void
    {
        foreach (array_keys($this->loaded) as $id) {
            $this->forget(Uuid::fromString($id));
        }
    }

    /**
     * @return array{keys: array<int, string>, current: int, currentSince: \DateTimeImmutable, index: string}
     */
    private function load(Uuid $owner, bool $create = false): array
    {
        $id = $owner->toRfc4122();
        if (isset($this->loaded[$id])) {
            return $this->loaded[$id];
        }
        $set = $this->read($owner);
        if (null === $set && !$create) {
            throw new CryptoException('The user has no keys.');
        }

        return $this->loaded[$id] = $set ?? $this->create($owner);
    }

    /**
     * @return ?array{keys: array<int, string>, current: int, currentSince: \DateTimeImmutable, index: string}
     */
    private function read(Uuid $owner): ?array
    {
        $user = $this->user($owner);
        /** @var list<array{version: int, wrapped_key: string, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT version, wrapped_key, created_at FROM user_key WHERE owner_id = :owner ORDER BY version', ['owner' => $owner->toRfc4122()]);
        if ([] === $rows || null === $user['wrapped_index_key']) {
            return null;
        }

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['version']] = $this->keyRing->unwrap($row['wrapped_key'], self::userKeyContext($owner, $row['version']));
        }
        $last = $rows[array_key_last($rows)];

        if (null !== $user['wrapped_data_key']) {
            $this->dropLegacyKey($owner, $user['wrapped_data_key'], $keys[1] ?? null);
        }

        return [
            'keys' => $keys,
            'current' => $last['version'],
            'currentSince' => new \DateTimeImmutable($last['created_at']),
            'index' => $this->keyRing->unwrap($user['wrapped_index_key'], self::indexKeyContext($owner)),
        ];
    }

    /**
     * @return array{keys: array<int, string>, current: int, currentSince: \DateTimeImmutable, index: string}
     */
    private function create(Uuid $owner): array
    {
        return $this->connection->transactional(function () use ($owner): array {
            // Serialized with shredding: keys are never created for a user whose keys were shredded.
            $this->lockOwner($owner);
            $user = $this->user($owner);
            if (null !== $user['keys_shredded_at']) {
                throw new CryptoException('The user\'s keys were shredded; nothing is written for them any more.');
            }
            $legacy = $user['wrapped_data_key'];
            $first = null === $legacy ? sodium_crypto_aead_xchacha20poly1305_ietf_keygen() : $this->keyRing->unwrap($legacy, self::legacyContext($owner));
            $this->insertVersion($owner, 1, $first, new \DateTimeImmutable());
            sodium_memzero($first);

            $index = random_bytes(32);
            $this->connection->executeStatement(
                'UPDATE app_user SET wrapped_index_key = :wrapped WHERE id = :owner AND wrapped_index_key IS NULL',
                ['wrapped' => $this->keyRing->wrap($index, self::indexKeyContext($owner)), 'owner' => $owner->toRfc4122()],
            );
            sodium_memzero($index);

            return $this->read($owner) ?? throw new CryptoException('The user keys could not be created.');
        });
    }

    private function insertVersion(Uuid $owner, int $version, #[\SensitiveParameter] string $key, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'INSERT INTO user_key (id, owner_id, version, wrapped_key, created_at) VALUES (:id, :owner, :version, :wrapped, :now)
             ON CONFLICT (owner_id, version) DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'owner' => $owner->toRfc4122(),
                'version' => $version,
                'wrapped' => $this->keyRing->wrap($key, self::userKeyContext($owner, $version)),
                'now' => $now,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * The data key from before is version 1 once that exists; only then, and only if they are the same key, it goes.
     */
    private function dropLegacyKey(Uuid $owner, string $wrapped, ?string $versionOne): void
    {
        $legacy = $this->keyRing->unwrap($wrapped, self::legacyContext($owner));
        $same = null !== $versionOne && hash_equals($versionOne, $legacy);
        sodium_memzero($legacy);
        if (!$same) {
            throw new CryptoException('The user\'s earlier data key differs from key version 1.');
        }
        $this->connection->executeStatement('UPDATE app_user SET wrapped_data_key = NULL WHERE id = :owner AND wrapped_data_key = :wrapped', ['owner' => $owner->toRfc4122(), 'wrapped' => $wrapped]);
    }

    /**
     * @return array{wrapped_index_key: ?string, wrapped_data_key: ?string, keys_shredded_at: ?string}
     */
    private function user(Uuid $owner): array
    {
        /** @var array{wrapped_index_key: ?string, wrapped_data_key: ?string, keys_shredded_at: ?string}|false $user */
        $user = $this->connection->fetchAssociative('SELECT wrapped_index_key, wrapped_data_key, keys_shredded_at FROM app_user WHERE id = :owner', ['owner' => $owner->toRfc4122()]);

        return false === $user ? throw new CryptoException('There is no such user.') : $user;
    }

    /**
     * Keeps the owner's key versions from being retired until the caller's transaction ends, and returns the versions
     * the owner has. For writers that store rows naming a key version.
     *
     * @return list<int>
     */
    public function lockOwnerShared(Uuid $owner): array
    {
        $this->ensure($owner);
        $this->connection->executeQuery('SELECT id FROM app_user WHERE id = :owner FOR SHARE', ['owner' => $owner->toRfc4122()]);
        /** @var list<int> */
        return $this->connection->fetchFirstColumn('SELECT version FROM user_key WHERE owner_id = :owner ORDER BY version', ['owner' => $owner->toRfc4122()]);
    }

    private function lockOwner(Uuid $owner): void
    {
        $this->connection->executeQuery('SELECT id FROM app_user WHERE id = :owner FOR UPDATE', ['owner' => $owner->toRfc4122()]);
    }

    private function forget(Uuid $owner): void
    {
        $id = $owner->toRfc4122();
        if (!isset($this->loaded[$id])) {
            return;
        }
        $set = $this->loaded[$id];
        unset($this->loaded[$id]);
        // Best effort: a key still held elsewhere (a caller's variable) is only dropped here, not overwritten.
        foreach (array_keys($set['keys']) as $version) {
            sodium_memzero($set['keys'][$version]);
        }
        sodium_memzero($set['index']);
    }

    private static function userKeyContext(Uuid $owner, int $version): string
    {
        return 'tresso:user-key:'.$owner->toRfc4122().':'.$version;
    }

    private static function indexKeyContext(Uuid $owner): string
    {
        return 'tresso:user-index-key:'.$owner->toRfc4122();
    }

    private static function legacyContext(Uuid $owner): string
    {
        return 'tresso:user-data-key:'.$owner->toRfc4122();
    }
}
