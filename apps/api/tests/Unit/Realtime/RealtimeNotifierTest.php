<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Realtime\RealtimeNotifier;
use App\Realtime\MercurePublisher;
use App\Realtime\MercureTokens;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class RealtimeNotifierTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $posts = [];

    private RecordingLogger $logger;

    private int $hubStatus = 200;

    private RealtimeNotifier $notifier;

    protected function setUp(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://api/.well-known/mercure', $url);
            parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $body);
            $fields = [];
            foreach ($body as $name => $value) {
                $fields[(string) $name] = \is_string($value) ? $value : '';
            }
            $this->posts[] = $fields;

            return new MockResponse('', ['http_code' => $this->hubStatus]);
        });
        $this->logger = new RecordingLogger();
        $tokens = new MercureTokens('http://localhost:8080/.well-known/mercure', str_repeat('p', 32), str_repeat('s', 32));
        $this->notifier = new RealtimeNotifier(new MercurePublisher($http, $tokens, $this->logger, 'http://api/.well-known/mercure'));
    }

    public function testPublishesTheHighestCommittedCursorPerUser(): void
    {
        $kim = Uuid::v7();
        $lisa = Uuid::v7();

        $this->notifier->changed($kim, 4);
        $this->notifier->changed($kim, 6);
        $this->notifier->changed($lisa, 2);
        $this->notifier->transactionCommitted();
        $this->notifier->changed($kim, 5);
        $this->notifier->transactionCommitted();
        $this->notifier->publish();

        self::assertSame([
            ['topic' => MercureTokens::changesTopic($kim), 'data' => '{"cursor":6}', 'private' => 'on', 'type' => 'changes'],
            ['topic' => MercureTokens::changesTopic($lisa), 'data' => '{"cursor":2}', 'private' => 'on', 'type' => 'changes'],
        ], $this->posts);
    }

    public function testNothingIsPublishedBeforeCommit(): void
    {
        $this->notifier->changed(Uuid::v7(), 1);
        $this->notifier->publish();

        self::assertSame([], $this->posts);
    }

    public function testRolledBackChangesAreNeverPublished(): void
    {
        $this->notifier->changed(Uuid::v7(), 1);
        $this->notifier->transactionRolledBack();
        $this->notifier->transactionCommitted();
        $this->notifier->publish();

        self::assertSame([], $this->posts);
    }

    public function testPublishesEachCursorOnce(): void
    {
        $this->notifier->changed(Uuid::v7(), 1);
        $this->notifier->transactionCommitted();
        $this->notifier->publish();
        $this->notifier->publish();

        self::assertCount(1, $this->posts);
    }

    public function testResetForgetsEverything(): void
    {
        $this->notifier->changed(Uuid::v7(), 1);
        $this->notifier->transactionCommitted();
        $this->notifier->changed(Uuid::v7(), 2);
        $this->notifier->reset();
        $this->notifier->transactionCommitted();
        $this->notifier->publish();

        self::assertSame([], $this->posts);
    }

    public function testHubFailureIsLoggedNotRaised(): void
    {
        $this->hubStatus = 503;

        $this->notifier->changed(Uuid::v7(), 1);
        $this->notifier->transactionCommitted();
        $this->notifier->publish();

        self::assertCount(1, $this->posts);
        self::assertSame(['Publishing to Mercure failed.'], $this->logger->messages);
    }
}
