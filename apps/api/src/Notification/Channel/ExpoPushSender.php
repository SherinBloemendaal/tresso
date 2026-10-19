<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Entity\NotificationDevice;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends to phones through Expo's push service, which hands the message to APNs or FCM.
 */
final readonly class ExpoPushSender
{
    public const string SEND_URL = 'https://exp.host/--/api/v2/push/send';

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(default::EXPO_ACCESS_TOKEN)%')]
        private ?string $accessToken,
    ) {
    }

    public function send(NotificationDevice $device, PushMessage $message, \DateTimeImmutable $now): PushResult
    {
        $token = $device->getEndpoint();
        if (1 !== preg_match('/^(Exponent|Expo)PushToken\[[^\]]+\]$/', $token)) {
            return PushResult::gone();
        }

        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if (null !== $this->accessToken && '' !== $this->accessToken) {
            $headers['Authorization'] = 'Bearer '.$this->accessToken;
        }

        try {
            $response = $this->http->request('POST', self::SEND_URL, [
                'headers' => $headers,
                'body' => json_encode(self::body($token, $message), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $answer = 200 === $status ? $response->toArray(false) : [];
        } catch (ExceptionInterface $e) {
            return PushResult::failed('Expo could not be reached: '.$e->getMessage());
        }

        if (200 !== $status) {
            return PushResult::failed(\sprintf('Expo answered HTTP %d.', $status));
        }
        $ticket = $answer['data'] ?? null;
        $ticket = \is_array($ticket) && array_is_list($ticket) ? ($ticket[0] ?? null) : $ticket;
        if (!\is_array($ticket)) {
            return PushResult::failed('Expo gave no ticket.');
        }
        if ('ok' === ($ticket['status'] ?? null)) {
            return PushResult::sent();
        }
        $details = $ticket['details'] ?? null;
        if (\is_array($details) && 'DeviceNotRegistered' === ($details['error'] ?? null)) {
            return PushResult::gone();
        }
        $text = $ticket['message'] ?? 'Expo refused the message.';

        return PushResult::failed(\is_string($text) ? $text : 'Expo refused the message.');
    }

    /**
     * A message without a title is data only: the app handles it in the background and shows nothing (iOS
     * content-available, Android high priority so it is delivered while the phone sleeps).
     *
     * @return array<string, mixed>
     */
    private static function body(string $token, PushMessage $message): array
    {
        if ('' === $message->title) {
            return ['to' => $token, 'data' => $message->payload(), '_contentAvailable' => true, 'priority' => 'high', 'expiration' => $message->expiresAt->getTimestamp()];
        }

        return [
            'to' => $token,
            'title' => $message->title,
            'body' => $message->body,
            'data' => $message->payload(),
            'sound' => $message->silent ? null : 'default',
            'priority' => $message->silent ? 'normal' : 'high',
            'expiration' => $message->expiresAt->getTimestamp(),
            'channelId' => 'reminders',
        ];
    }
}
