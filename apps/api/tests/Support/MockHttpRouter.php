<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Answers every outgoing HTTP request in the test environment (framework.http_client.mock_response_factory):
 * Mercure publishes go to the {@see RecordingMercureHub}, other URLs get the response a test registered, and anything
 * else a 404. Requests are recorded so tests can check what was asked.
 */
final class MockHttpRouter
{
    /** @var array<string, array{body: string, status: int}> */
    private array $routes = [];

    /** @var array<string, list<array{body: string, status: int}>> */
    private array $queued = [];

    /** @var list<array{method: string, url: string, body: string, headers: list<string>}> */
    private array $requests = [];

    public function __construct(private readonly RecordingMercureHub $hub)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        if (str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/.well-known/mercure')) {
            return ($this->hub)($method, $url, $options);
        }

        $body = $options['body'] ?? '';
        /** @var list<string> $headers normalized "Name: value" lines */
        $headers = $options['headers'] ?? [];
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => \is_string($body) ? $body : '', 'headers' => $headers];

        $key = strtok($url, '?') ?: $url;
        $route = isset($this->queued[$key]) && [] !== $this->queued[$key]
            ? array_shift($this->queued[$key])
            : ($this->routes[$key] ?? ['body' => '', 'status' => 404]);

        return new MockResponse($route['body'], ['http_code' => $route['status']]);
    }

    /** Matches the URL without its query string. */
    public function respond(string $url, string $body, int $status = 200): void
    {
        $this->routes[$url] = ['body' => $body, 'status' => $status];
    }

    /** Answers the next request to the URL (without query) with this, before any {@see self::respond()} route. */
    public function respondOnce(string $url, string $body, int $status = 200): void
    {
        $this->queued[$url][] = ['body' => $body, 'status' => $status];
    }

    /** @return list<string> */
    public function requested(): array
    {
        return array_column($this->requests, 'url');
    }

    /**
     * Requests to a URL (without query), oldest first.
     *
     * @return list<array{method: string, url: string, body: string, headers: list<string>}>
     */
    public function requestsTo(string $url): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => (strtok($request['url'], '?') ?: $request['url']) === $url));
    }

    /**
     * Every request with its headers and body, oldest first, for tests that check what was sent.
     *
     * @return list<array{method: string, url: string, body: string, headers: list<string>}>
     */
    public function calls(): array
    {
        return $this->requests;
    }
}
