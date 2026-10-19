<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for the Mercure hub in tests (requests reach it through the {@see MockHttpRouter}) and records what the
 * API published.
 */
final class RecordingMercureHub
{
    /** @var list<array{url: string, authorization: string, body: array<string, string>}> */
    private array $published = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        if ('POST' !== $method || !str_ends_with($url, '/.well-known/mercure')) {
            throw new \LogicException(\sprintf('Unexpected outgoing request in a test: %s %s', $method, $url));
        }

        parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $body);
        $authorization = '';
        foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $header) {
            if (\is_string($header) && str_starts_with(strtolower($header), 'authorization: ')) {
                $authorization = substr($header, \strlen('authorization: '));
            }
        }
        $fields = [];
        foreach ($body as $name => $value) {
            $fields[(string) $name] = \is_string($value) ? $value : '';
        }
        $this->published[] = ['url' => $url, 'authorization' => $authorization, 'body' => $fields];

        return new MockResponse('urn:uuid:'.bin2hex(random_bytes(16)));
    }

    /**
     * @return list<array{url: string, authorization: string, body: array<string, string>}>
     */
    public function takePublished(): array
    {
        $published = $this->published;
        $this->published = [];

        return $published;
    }
}
