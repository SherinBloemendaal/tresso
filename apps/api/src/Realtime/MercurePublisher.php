<?php

declare(strict_types=1);

namespace App\Realtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts private updates to the Mercure hub. A failed publish is logged, not raised: the writes are committed already,
 * and clients catch up through the delta endpoint when they reconnect.
 */
final readonly class MercurePublisher
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private MercureTokens $tokens,
        private LoggerInterface $logger,
        #[Autowire(env: 'MERCURE_INTERNAL_URL')]
        private string $internalHubUrl,
    ) {
    }

    /**
     * @param array<string, int> $cursors change cursor per user id
     */
    public function publishCursors(array $cursors): void
    {
        $topics = [];
        foreach (array_keys($cursors) as $userId) {
            $topics[$userId] = MercureTokens::changesTopic(Uuid::fromString($userId));
        }
        $token = $this->tokens->publisherToken(array_values($topics));

        $responses = [];
        foreach ($cursors as $userId => $cursor) {
            $responses[$userId] = $this->httpClient->request('POST', $this->internalHubUrl, [
                'auth_bearer' => $token->value,
                'body' => [
                    'topic' => $topics[$userId],
                    'data' => json_encode(['cursor' => $cursor], \JSON_THROW_ON_ERROR),
                    'private' => 'on',
                    'type' => 'changes',
                ],
            ]);
        }

        foreach ($responses as $userId => $response) {
            try {
                $response->getContent();
            } catch (ExceptionInterface $exception) {
                $this->logger->warning('Publishing the change cursor to Mercure failed.', ['userId' => $userId, 'exception' => $exception]);
            }
        }
    }
}
