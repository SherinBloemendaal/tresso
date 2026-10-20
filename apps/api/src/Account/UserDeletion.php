<?php

declare(strict_types=1);

namespace App\Account;

use App\Security\Crypto\UserKeys;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes a user, crypto-shredding first: their keys go in a transaction of their own, so from that commit on
 * nothing they stored can be decrypted, wherever copies are (object storage, database dumps, backups). Then the user
 * row goes, which the database cascades to everything they own, with their OAuth tokens; their search documents and
 * objects are purged after that by {@see PurgeDeletedUser}, retried until done.
 *
 * Content stored before encryption is not covered by the shredding: it was never encrypted. Re-encrypt it first.
 */
final readonly class UserDeletion
{
    public function __construct(
        private Connection $connection,
        private UserKeys $keys,
        private MessageBusInterface $bus,
    ) {
    }

    public function delete(Uuid $user): void
    {
        $email = $this->connection->fetchOne('SELECT email FROM app_user WHERE id = :id', ['id' => $user->toRfc4122()]);
        if (!\is_string($email)) {
            throw new \InvalidArgumentException('There is no such user.');
        }

        $this->keys->shred($user);

        $this->connection->transactional(function () use ($user, $email): void {
            $this->connection->executeStatement(
                'DELETE FROM oauth2_refresh_token WHERE access_token IN (SELECT identifier FROM oauth2_access_token WHERE user_identifier = :email)',
                ['email' => $email],
            );
            $this->connection->executeStatement('DELETE FROM oauth2_access_token WHERE user_identifier = :email', ['email' => $email]);
            $this->connection->executeStatement('DELETE FROM oauth2_authorization_code WHERE user_identifier = :email', ['email' => $email]);
            $this->connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $user->toRfc4122()]);
        });

        $this->bus->dispatch(new PurgeDeletedUser($user->toRfc4122()));
    }
}
