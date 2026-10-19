<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SyncRemoteUids;
use App\Sync\Ingest\FolderLookup;
use App\Sync\LeaseGuard;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProviderInterface<SyncRemoteUids>
 */
final readonly class SyncRemoteUidsProvider implements ProviderInterface
{
    private const int DEFAULT_LIMIT = 1000;
    private const int MAX_LIMIT = 5000;

    public function __construct(
        private LeaseGuard $leaseGuard,
        private FolderLookup $folders,
        private Connection $connection,
        private RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SyncRemoteUids
    {
        $query = ($this->requests->getCurrentRequest() ?? throw new \LogicException('A request is being handled.'))->query;
        $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $query->get('workerId'));

        $remoteName = $query->getString('folder');
        if ('' === $remoteName) {
            throw new BadRequestHttpException('A folder is required.');
        }
        $after = $query->has('after') ? $query->getString('after') : null;
        $limit = $query->getString('limit', (string) self::DEFAULT_LIMIT);
        if (1 !== preg_match('/^[0-9]{1,4}$/', $limit) || (int) $limit < 1 || (int) $limit > self::MAX_LIMIT) {
            throw new BadRequestHttpException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        $folder = $this->folders->get($account, $remoteName);

        // COLLATE "C": byte order, the same order the worker sorts in and that the "after" comparison uses.
        /** @var list<string> $uids */
        $uids = $this->connection->fetchFirstColumn(
            'SELECT remote_uid FROM message_location
             WHERE folder_id = :folder AND (CAST(:after AS TEXT) IS NULL OR remote_uid COLLATE "C" > CAST(:after AS TEXT) COLLATE "C")
             ORDER BY remote_uid COLLATE "C"
             LIMIT :limit',
            ['folder' => $folder->getId()->toRfc4122(), 'after' => $after, 'limit' => (int) $limit + 1],
        );

        $next = null;
        if (\count($uids) > (int) $limit) {
            array_pop($uids);
            $next = $uids[array_key_last($uids)];
        }

        return new SyncRemoteUids($account->getId()->toRfc4122(), $remoteName, $uids, $next);
    }
}
