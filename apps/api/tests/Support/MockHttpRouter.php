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

    /** @var list<string> */
    private array $requested = [];

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

        $this->requested[] = $url;
        $route = $this->routes[strtok($url, '?') ?: $url] ?? ['body' => '', 'status' => 404];

        return new MockResponse($route['body'], ['http_code' => $route['status']]);
    }

    /** Matches the URL without its query string. */
    public function respond(string $url, string $body, int $status = 200): void
    {
        $this->routes[$url] = ['body' => $body, 'status' => $status];
    }

    /** @return list<string> */
    public function requested(): array
    {
        return $this->requested;
    }
}
