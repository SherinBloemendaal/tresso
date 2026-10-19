<?php

declare(strict_types=1);

namespace App\Search\Meilisearch;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The parts of the Meilisearch HTTP API the search index uses. Writes are asynchronous in Meilisearch: they return a
 * task, which Meilisearch runs in order per index.
 *
 * The key never leaves the API: clients search through the API, which adds the owner filter itself.
 */
final readonly class MeilisearchClient
{
    private HttpClientInterface $http;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'MEILISEARCH_URL')]
        string $url,
        #[Autowire(env: 'MEILISEARCH_KEY')]
        string $key,
    ) {
        $this->http = ScopingHttpClient::forBaseUri($httpClient, rtrim($url, '/').'/', [
            'auth_bearer' => $key,
            'timeout' => 10,
        ]);
    }

    /**
     * Creates the index when it does not exist yet.
     *
     * @param array<string, mixed> $settings
     */
    public function updateSettings(string $index, array $settings): int
    {
        return $this->task('PATCH', 'indexes/'.rawurlencode($index).'/settings', $settings);
    }

    /**
     * Adds documents or replaces the stored ones with the same primary key.
     *
     * @param list<array<string, mixed>> $documents
     */
    public function replaceDocuments(string $index, string $primaryKey, array $documents): int
    {
        return $this->task('POST', 'indexes/'.rawurlencode($index).'/documents?primaryKey='.rawurlencode($primaryKey), $documents);
    }

    /**
     * @param list<string> $ids
     */
    public function deleteDocuments(string $index, array $ids): int
    {
        return $this->task('POST', 'indexes/'.rawurlencode($index).'/documents/delete-batch', $ids);
    }

    public function deleteDocumentsWhere(string $index, string $filter): int
    {
        return $this->task('POST', 'indexes/'.rawurlencode($index).'/documents/delete', ['filter' => $filter]);
    }

    public function deleteAllDocuments(string $index): int
    {
        return $this->task('DELETE', 'indexes/'.rawurlencode($index).'/documents');
    }

    public function deleteIndex(string $index): int
    {
        return $this->task('DELETE', 'indexes/'.rawurlencode($index));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>|null the response, or null when the index does not exist yet
     */
    public function search(string $index, array $query): ?array
    {
        try {
            $response = $this->http->request('POST', 'indexes/'.rawurlencode($index).'/search', ['json' => $query]);
            if (404 === $response->getStatusCode() && 'index_not_found' === ($response->toArray(false)['code'] ?? null)) {
                return null;
            }

            /** @var array<string, mixed> */
            return self::decode($response, 'Searching');
        } catch (ExceptionInterface $exception) {
            throw new MeilisearchException('Searching failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Waits until Meilisearch has run every task up to and including this one.
     */
    public function waitForTask(int $taskUid, float $timeoutSeconds = 30.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            try {
                $task = self::decode($this->http->request('GET', 'tasks/'.$taskUid), \sprintf('Reading task %d', $taskUid));
            } catch (ExceptionInterface $exception) {
                throw new MeilisearchException(\sprintf('Reading task %d failed: %s', $taskUid, $exception->getMessage()), previous: $exception);
            }

            $status = $task['status'] ?? null;
            if ('succeeded' === $status) {
                return;
            }
            if ('failed' === $status || 'canceled' === $status) {
                $error = \is_array($task['error'] ?? null) && \is_string($task['error']['message'] ?? null) ? $task['error']['message'] : 'unknown error';

                throw new MeilisearchException(\sprintf('Task %d %s: %s', $taskUid, $status, $error));
            }
            if (microtime(true) > $deadline) {
                throw new MeilisearchException(\sprintf('Task %d did not finish within %.0f seconds.', $taskUid, $timeoutSeconds));
            }
            usleep(50_000);
        }
    }

    /**
     * @param array<mixed>|null $body
     */
    private function task(string $method, string $path, ?array $body = null): int
    {
        try {
            $response = $this->http->request($method, $path, null === $body ? [] : ['json' => $body]);
            $uid = self::decode($response, $method.' '.$path)['taskUid'] ?? null;
        } catch (ExceptionInterface $exception) {
            throw new MeilisearchException(\sprintf('%s %s failed: %s', $method, $path, $exception->getMessage()), previous: $exception);
        }
        if (!\is_int($uid)) {
            throw new MeilisearchException(\sprintf('%s %s returned no task.', $method, $path));
        }

        return $uid;
    }

    /**
     * @return array<mixed>
     *
     * @throws ExceptionInterface when the body is not JSON
     */
    private static function decode(ResponseInterface $response, string $action): array
    {
        $body = $response->toArray(false);
        $status = $response->getStatusCode();
        if ($status >= 300) {
            throw new MeilisearchException(\sprintf('%s failed with status %d: %s', $action, $status, \is_string($body['message'] ?? null) ? $body['message'] : 'no details'));
        }

        return $body;
    }
}
