<?php

declare(strict_types=1);

namespace App\Search;

use App\Search\Meilisearch\MeilisearchClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The Meilisearch index of all users' messages, one document per message ({@see MessageDocuments}).
 *
 * Users share the index, so every search must filter on `ownerId`; {@see MessageSearch} adds that filter itself and
 * clients never talk to Meilisearch. Highlights are marked with two private-use characters, which documents never
 * contain, so the API can split them into plain text segments instead of passing markup on.
 */
final class MessageIndex
{
    public const string HIGHLIGHT_START = "\u{E000}";
    public const string HIGHLIGHT_END = "\u{E001}";

    private const array SETTINGS = [
        // Earlier attributes rank higher.
        'searchableAttributes' => ['subject', 'fromName', 'fromAddress', 'recipients', 'attachments.filename', 'snippet', 'bodyText'],
        'filterableAttributes' => ['ownerId', 'accountId', 'folderIds', 'fromTokens', 'toTokens', 'hasAttachments', 'receivedAt'],
        'sortableAttributes' => ['receivedAt'],
        // Mail is read newest first: among messages that contain all query words, the newest wins.
        'rankingRules' => ['words', 'sort', 'typo', 'proximity', 'attribute', 'exactness'],
    ];

    private bool $configured = false;

    public function __construct(
        private readonly MeilisearchClient $client,
        #[Autowire(env: 'MEILISEARCH_INDEX')]
        private readonly string $name,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Creates the index and applies its settings. Meilisearch runs tasks in order, so documents added after this
     * are indexed with these settings.
     */
    public function configure(): int
    {
        $task = $this->client->updateSettings($this->name, self::SETTINGS);
        $this->configured = true;

        return $task;
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    public function replace(array $documents): int
    {
        $this->ensureConfigured();

        return $this->client->replaceDocuments($this->name, 'id', $documents);
    }

    /**
     * @param list<string> $messageIds
     */
    public function delete(array $messageIds): int
    {
        $this->ensureConfigured();

        return $this->client->deleteDocuments($this->name, $messageIds);
    }

    public function deleteOwnedBy(Uuid $ownerId): int
    {
        $this->ensureConfigured();

        return $this->client->deleteDocumentsWhere($this->name, 'ownerId = '.self::quote($ownerId->toRfc4122()));
    }

    public function deleteAccount(string $accountId): int
    {
        $this->ensureConfigured();

        return $this->client->deleteDocumentsWhere($this->name, 'accountId = '.self::quote($accountId));
    }

    public function clear(): int
    {
        $this->ensureConfigured();

        return $this->client->deleteAllDocuments($this->name);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>|null null when nothing was indexed yet
     */
    public function search(array $query): ?array
    {
        return $this->client->search($this->name, [
            ...$query,
            'highlightPreTag' => self::HIGHLIGHT_START,
            'highlightPostTag' => self::HIGHLIGHT_END,
        ]);
    }

    public function waitFor(int $task, float $timeoutSeconds = 30.0): void
    {
        $this->client->waitForTask($task, $timeoutSeconds);
    }

    /**
     * A filter value: Meilisearch filters take strings in double quotes, with quotes and backslashes escaped.
     */
    public static function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }

    /**
     * Removes the highlight markers from indexed text, so a highlight can only come from Meilisearch.
     */
    public static function clean(string $text): string
    {
        return str_replace([self::HIGHLIGHT_START, self::HIGHLIGHT_END], '', $text);
    }

    private function ensureConfigured(): void
    {
        if (!$this->configured) {
            $this->configure();
        }
    }
}
