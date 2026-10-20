<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Notification\Channel\PushMessage;
use App\Notification\NotificationChannelChoice;
use App\Notification\NotificationDispatcher;
use App\Repository\UserRepository;
use App\Tests\Functional\AuthenticatesUsers;
use App\Tests\Functional\Calendar\CalendarTestHelpers;
use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\MockHttpRouter;

/**
 * The dispatcher is what any feature sends a notification with, calendar or not.
 */
final class NotificationDispatcherTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use CalendarTestHelpers;

    private const string PUSH_ENDPOINT = 'https://push.example.test/subscriptions/1';
    private const string P256DH = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const string AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const string EXPO_SEND = 'https://exp.host/--/api/v2/push/send';

    /** @var array<string, string> */
    private array $kim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->kim = $this->userHeaders('kim@example.test', ['settings:read', 'settings:write']);
        $this->http()->respond(self::PUSH_ENDPOINT, '', 201);
        $this->http()->respond(self::EXPO_SEND, json_encode(['data' => [['status' => 'ok', 'id' => 'ticket-1']]], \JSON_THROW_ON_ERROR));
        foreach ([
            ['kind' => 'web_push', 'endpoint' => self::PUSH_ENDPOINT, 'publicKey' => self::P256DH, 'authSecret' => self::AUTH, 'name' => 'Firefox'],
            ['kind' => 'expo', 'endpoint' => 'ExponentPushToken[abc123]', 'name' => 'Phone'],
        ] as $device) {
            $this->client->jsonRequest('POST', '/api/v1/notification-devices', $device, $this->kim);
            self::assertResponseIsSuccessful();
        }
    }

    public function testSendsAnyKindOnceWithItsPathAndData(): void
    {
        $choice = new NotificationChannelChoice(NotificationChannel::Phone, NotificationChannel::Desktop);

        $first = $this->dispatcher()->send($this->user(), $this->message(), 'mail:m1', $choice, new \DateTimeImmutable());
        $again = $this->dispatcher()->send($this->user(), $this->message(), 'mail:m1', $choice, new \DateTimeImmutable());

        self::assertSame(2, $first);
        self::assertSame(0, $again, 'The same delivery key never notifies a device twice.');
        $expo = array_values(array_filter($this->http()->calls(), static fn (array $call): bool => self::EXPO_SEND === $call['url']));
        self::assertCount(1, $expo);
        $body = json_decode($expo[0]['body'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['threadId' => 't1', 'userId' => $this->user()->getId()->toRfc4122(), 'id' => 'm1', 'kind' => 'mail', 'title' => 'Anna de Vries', 'body' => 'Budget review', 'path' => '/thread/t1', 'silent' => false], $body['data'], 'Every push names its user, whichever feature sends it.');
        self::assertSame('mail', $body['channelId'], 'Shown in its own Android channel.');
        self::assertCount(1, array_filter($this->http()->calls(), static fn (array $call): bool => self::PUSH_ENDPOINT === $call['url']));
    }

    public function testASendAlreadyUnderWayIsNotDoubled(): void
    {
        $choice = new NotificationChannelChoice(NotificationChannel::Phone);
        $this->dispatcher()->send($this->user(), $this->message(), 'mail:m1', $choice, new \DateTimeImmutable());
        // Another worker fired the same notification and is sending right now.
        $this->connection()->executeStatement("UPDATE notification_delivery SET status = 'sending', updated_at = NOW()");

        $again = $this->dispatcher()->send($this->user(), $this->message(), 'mail:m1', $choice, new \DateTimeImmutable());

        self::assertSame(0, $again);
        self::assertCount(1, array_filter($this->http()->calls(), static fn (array $call): bool => self::EXPO_SEND === $call['url']));
    }

    public function testSendsOnlyOnTheChosenChannels(): void
    {
        $this->dispatcher()->send($this->user(), $this->message(), 'mail:m1', new NotificationChannelChoice(NotificationChannel::Desktop), new \DateTimeImmutable());

        self::assertCount(0, array_filter($this->http()->calls(), static fn (array $call): bool => self::EXPO_SEND === $call['url']));
        self::assertCount(1, array_filter($this->http()->calls(), static fn (array $call): bool => self::PUSH_ENDPOINT === $call['url']));
        self::assertSame(0, $this->dispatcher()->send($this->user(), $this->message(), 'mail:m2', new NotificationChannelChoice(), new \DateTimeImmutable()), 'No channel, no send.');
    }

    public function testForgetsAPhoneExpoNoLongerKnows(): void
    {
        $this->http()->respond(self::EXPO_SEND, json_encode(['data' => [['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]]], \JSON_THROW_ON_ERROR));

        $this->dispatcher()->send($this->user(), $this->message(), 'mail:m1', new NotificationChannelChoice(NotificationChannel::Phone), new \DateTimeImmutable());

        self::assertSame(['web_push'], array_column($this->getJson('/api/v1/notification-devices', $this->kim), 'kind'));
    }

    private function message(): PushMessage
    {
        return new PushMessage('m1', 'mail', 'Anna de Vries', 'Budget review', '/thread/t1', false, new \DateTimeImmutable('+1 hour'), ['threadId' => 't1'], PushMessage::MAIL);
    }

    private function dispatcher(): NotificationDispatcher
    {
        return static::getContainer()->get(NotificationDispatcher::class);
    }

    private function user(): User
    {
        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'kim@example.test']) ?? self::fail('No user.');
    }

    private function http(): MockHttpRouter
    {
        return static::getContainer()->get(MockHttpRouter::class);
    }
}
