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

        $updates = [];
        foreach ($cursors as $userId => $cursor) {
            $updates[$userId] = ['topic' => $topics[$userId], 'type' => 'changes', 'data' => ['cursor' => $cursor]];
        }
        $this->post($token, $updates);
    }

    /**
     * Tells the sync workers that these accounts have mail to send; the worker leasing an account picks it up.
     *
     * @param list<string> $mailAccountIds
     */
    public function publishOutbox(array $mailAccountIds): void
    {
        $token = $this->tokens->publisherToken([MercureTokens::OUTBOX_TOPIC]);
        $updates = [];
        foreach ($mailAccountIds as $mailAccountId) {
            $updates[$mailAccountId] = ['topic' => MercureTokens::OUTBOX_TOPIC, 'type' => 'outbox', 'data' => ['mailAccountId' => $mailAccountId]];
        }
        $this->post($token, $updates);
    }

    /**
     * @param array<string, array{topic: string, type: string, data: array<string, int|string>}> $updates
     */
    private function post(Token $token, array $updates): void
    {
        $responses = [];
        foreach ($updates as $key => $update) {
            $responses[$key] = $this->httpClient->request('POST', $this->internalHubUrl, [
                'auth_bearer' => $token->value,
                'body' => [
                    'topic' => $update['topic'],
                    'data' => json_encode($update['data'], \JSON_THROW_ON_ERROR),
                    'private' => 'on',
                    'type' => $update['type'],
                ],
            ]);
        }

        foreach ($responses as $key => $response) {
            try {
                $response->getContent();
            } catch (ExceptionInterface $exception) {
                $this->logger->warning('Publishing to Mercure failed.', ['update' => $key, 'exception' => $exception]);
            }
        }
    }
}
