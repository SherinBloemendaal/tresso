<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Entity\NotificationDevice;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends one encrypted message to a browser's push service (RFC 8030) with VAPID. The endpoint comes from the client,
 * so requests go through a client that refuses private networks and only to https.
 */
final readonly class WebPushSender
{
    public function __construct(
        #[Autowire(service: 'notification.push_http_client')]
        private HttpClientInterface $http,
        private VapidKeys $keys,
    ) {
    }

    public function send(NotificationDevice $device, PushMessage $message, \DateTimeImmutable $now): PushResult
    {
        $endpoint = $device->getEndpoint();
        if (!str_starts_with($endpoint, 'https://') || null === $device->getPublicKey() || null === $device->getAuthSecret()) {
            return PushResult::gone();
        }
        if (!$this->keys->isConfigured()) {
            return PushResult::failed('Web Push is not configured on this server.');
        }

        try {
            $body = WebPushEncryption::encrypt(
                json_encode($message->payload(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                WebPushEncryption::base64UrlDecode($device->getPublicKey()),
                WebPushEncryption::base64UrlDecode($device->getAuthSecret()),
            );
        } catch (\InvalidArgumentException) {
            // A subscription with broken keys can never receive anything.
            return PushResult::gone();
        }

        try {
            $response = $this->http->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => $this->keys->authorization($endpoint, $now),
                    'Content-Encoding' => 'aes128gcm',
                    'Content-Type' => 'application/octet-stream',
                    'TTL' => (string) max(0, $message->expiresAt->getTimestamp() - $now->getTimestamp()),
                    'Urgency' => $message->silent ? 'low' : 'high',
                ],
                'body' => $body,
                'max_redirects' => 0,
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            return PushResult::failed('The push service could not be reached: '.$e->getMessage());
        }

        return match (true) {
            $status >= 200 && $status < 300 => PushResult::sent(),
            404 === $status, 410 === $status => PushResult::gone(),
            default => PushResult::failed(\sprintf('The push service answered HTTP %d.', $status)),
        };
    }
}
