<?php

declare(strict_types=1);

namespace App\Search;

use App\ApiResource\Output\HighlightSegment;
use App\ApiResource\Output\SearchAttachment;
use App\ApiResource\Output\SearchHit;
use App\ApiResource\SearchResults;
use App\Entity\MailAccount;
use App\Entity\User;
use App\Enum\FolderRole;
use App\Enum\ScanStatus;
use App\Repository\MailAccountRepository;
use App\Search\Query\Scope;
use App\Search\Query\SearchFilter;
use App\Search\Query\SearchQuery;
use Doctrine\DBAL\Connection;

/**
 * Runs a parsed search for one user against the shared index and turns the hits into results.
 */
final readonly class MessageSearch
{
    private const array RETRIEVE = ['id', 'threadId', 'accountId', 'folderIds', 'subject', 'fromAddress', 'fromName', 'snippet', 'receivedAt', 'seen', 'flagged', 'hasAttachments', 'attachments'];

    /** Words of text around the first match in the preview. */
    private const int PREVIEW_WORDS = 24;

    public function __construct(
        private MessageIndex $index,
        private MailAccountRepository $accounts,
        private Connection $connection,
    ) {
    }

    public function search(User $owner, string $rawQuery, SearchQuery $query, int $page, int $perPage): SearchResults
    {
        $empty = new SearchResults($rawQuery, $page, $perPage, 0, 0, []);

        $accounts = $this->accounts->findOwnedBy($owner);
        if ([] === $accounts) {
            return $empty;
        }
        $scopes = [];
        foreach ($query->in as $value) {
            $scope = $this->resolve($owner, $accounts, $value);
            if (null === $scope) {
                return $empty;
            }
            $scopes[] = $scope;
        }

        $accountIds = array_map(static fn (MailAccount $account): string => $account->getId()->toRfc4122(), $accounts);
        $response = $this->index->search([
            'q' => $query->text,
            'filter' => SearchFilter::build($owner->getId()->toRfc4122(), $accountIds, $query, $scopes),
            'sort' => ['receivedAt:desc'],
            'matchingStrategy' => 'all',
            'page' => $page,
            'hitsPerPage' => $perPage,
            'attributesToRetrieve' => self::RETRIEVE,
            'attributesToHighlight' => ['subject', 'fromName', 'fromAddress', 'snippet', 'bodyText', 'attachments.filename'],
            'attributesToCrop' => ['bodyText'],
            'cropLength' => self::PREVIEW_WORDS,
            'cropMarker' => '…',
        ]);
        if (null === $response) {
            return $empty;
        }

        $hits = [];
        foreach (self::list($response['hits'] ?? []) as $hit) {
            $hits[] = $this->hit(self::map($hit));
        }

        return new SearchResults($rawQuery, $page, $perPage, self::int($response['totalHits'] ?? 0), self::int($response['totalPages'] ?? 0), $hits);
    }

    /**
     * An in: value names a folder role, else an account (address or name), else folders by name.
     *
     * @param non-empty-list<MailAccount> $accounts
     */
    private function resolve(User $owner, array $accounts, string $value): ?Scope
    {
        $value = mb_strtolower($value);

        $role = FolderRole::tryFrom($value);
        if (null !== $role && FolderRole::Custom !== $role) {
            $ids = $this->folderIds($owner, 'f.role = :value', $role->value);

            return [] === $ids ? null : Scope::folders($ids);
        }

        $accountIds = [];
        foreach ($accounts as $account) {
            if ($account->getEmailAddress() === $value || mb_strtolower($account->getDisplayName()) === $value) {
                $accountIds[] = $account->getId()->toRfc4122();
            }
        }
        if ([] !== $accountIds) {
            return Scope::accounts($accountIds);
        }

        $ids = $this->folderIds($owner, 'LOWER(f.display_name) = :value', $value);

        return [] === $ids ? null : Scope::folders($ids);
    }

    /**
     * @return list<string>
     */
    private function folderIds(User $owner, string $condition, string $value): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT f.id FROM folder f JOIN mail_account a ON a.id = f.mail_account_id WHERE a.owner_id = :owner AND '.$condition.' ORDER BY f.id',
            ['owner' => $owner->getId()->toRfc4122(), 'value' => $value],
        );
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function hit(array $hit): SearchHit
    {
        $formatted = self::map($hit['_formatted'] ?? []);
        $fromName = \is_string($hit['fromName'] ?? null) ? $hit['fromName'] : null;

        $preview = self::string($formatted['bodyText'] ?? '');
        if (!Highlights::hasMatch($preview)) {
            $preview = self::string($formatted['snippet'] ?? $hit['snippet'] ?? '');
        }

        $formattedAttachments = self::list($formatted['attachments'] ?? []);
        $attachments = [];
        foreach (self::list($hit['attachments'] ?? []) as $i => $attachment) {
            $attachment = self::map($attachment);
            $filename = self::string(self::map($formattedAttachments[$i] ?? [])['filename'] ?? $attachment['filename'] ?? '');
            $attachments[] = new SearchAttachment(
                self::string($attachment['id'] ?? ''),
                Highlights::segments($filename),
                self::string($attachment['contentType'] ?? ''),
                self::int($attachment['size'] ?? 0),
                Highlights::hasMatch($filename),
                // Documents indexed before scanning existed have none until they are indexed again.
                ScanStatus::tryFrom(self::string($attachment['scanStatus'] ?? '')),
            );
        }

        return new SearchHit(
            self::string($hit['id'] ?? ''),
            self::string($hit['threadId'] ?? ''),
            self::string($hit['accountId'] ?? ''),
            array_map(self::string(...), self::list($hit['folderIds'] ?? [])),
            self::string($hit['fromAddress'] ?? ''),
            $fromName,
            new \DateTimeImmutable('@'.self::int($hit['receivedAt'] ?? 0)),
            true === ($hit['seen'] ?? false),
            true === ($hit['flagged'] ?? false),
            true === ($hit['hasAttachments'] ?? false),
            self::segmentsOrPlain($formatted['subject'] ?? null, $hit['subject'] ?? ''),
            null === $fromName ? self::segmentsOrPlain($formatted['fromAddress'] ?? null, $hit['fromAddress'] ?? '') : self::segmentsOrPlain($formatted['fromName'] ?? null, $fromName),
            Highlights::segments($preview),
            $attachments,
        );
    }

    /**
     * @return list<HighlightSegment>
     */
    private static function segmentsOrPlain(mixed $formatted, mixed $plain): array
    {
        return Highlights::segments(\is_string($formatted) ? $formatted : self::string($plain));
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected an object in the search response, got %s.', get_debug_type($value)));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected a list in the search response, got %s.', get_debug_type($value)));
        }

        return $value;
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected a string in the search response, got %s.', get_debug_type($value)));
        }

        return $value;
    }

    private static function int(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected an integer in the search response, got %s.', get_debug_type($value)));
        }

        return $value;
    }
}
