<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\NotificationDelivery;
use App\Entity\NotificationDevice;
use App\Entity\User;
use App\Enum\DeviceKind;
use App\Enum\NotificationChannel;
use App\Notification\Channel\EmailNotifier;
use App\Notification\Channel\ExpoPushSender;
use App\Notification\Channel\PushMessage;
use App\Notification\Channel\PushResult;
use App\Notification\Channel\WebPushSender;
use App\Repository\NotificationDeviceRepository;
use App\Repository\NotificationPreferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sends one notification to every target of a user on the chosen channels: each enabled device whose channel is
 * chosen (Expo for the phone, web push and the desktop app for the desktop) and the notification email address.
 *
 * First every target is claimed with a delivery row for the delivery key, in its own transaction; a target claimed
 * before is skipped, so a retried message never notifies anyone twice. Then the sends, outside any transaction, and
 * their outcome. A device whose push service forgot it is removed. The desktop app shows notifications from the
 * delta, so its delivery is complete once claimed.
 */
final readonly class NotificationDispatcher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationDeviceRepository $devices,
        private NotificationPreferencesRepository $preferences,
        private WebPushSender $webPush,
        private ExpoPushSender $expo,
        private EmailNotifier $email,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many targets were claimed now (0 when everything was sent before)
     */
    public function send(User $user, PushMessage $message, string $deliveryKey, NotificationChannelChoice $choice, \DateTimeImmutable $now): int
    {
        if ($choice->isEmpty()) {
            return 0;
        }
        /** @var list<array{string, ?NotificationDevice, ?string}> $claimed */
        $claimed = $this->entityManager->wrapInTransaction(fn (): array => $this->claim($user, $deliveryKey, $choice));

        foreach ($claimed as [$target, $device, $address]) {
            $result = match (true) {
                null !== $address => $this->email->send($address, $message),
                DeviceKind::WebPush === $device?->getKind() => $this->webPush->send($device, $message, $now),
                DeviceKind::Expo === $device?->getKind() => $this->expo->send($device, $message, $now),
                default => PushResult::sent(),
            };
            $this->record($deliveryKey, $target, $device, $result);
        }

        return \count($claimed);
    }

    /**
     * @return list<array{string, ?NotificationDevice, ?string}> the targets claimed now, with the device or address
     */
    private function claim(User $user, string $deliveryKey, NotificationChannelChoice $choice): array
    {
        $targets = [];
        foreach ($this->devices->findEnabled($user) as $device) {
            if ($choice->includes(NotificationChannel::ofDevice($device->getKind()))) {
                $targets[] = [$device->getId()->toRfc4122(), $device, null];
            }
        }
        if ($choice->includes(NotificationChannel::Email)) {
            $targets[] = ['email', null, $this->preferences->forUser($user)->getEmailAddress() ?? $user->getEmail()];
        }

        $connection = $this->entityManager->getConnection();
        $claimed = [];
        foreach ($targets as [$target, $device, $address]) {
            // The unique key decides, also against a send of the same key running at the same time.
            $inserted = $connection->executeStatement(
                'INSERT INTO notification_delivery (id, user_id, delivery_key, target, status, created_at) VALUES (:id, :user, :key, :target, :status, :now)
                 ON CONFLICT (delivery_key, target) DO NOTHING',
                ['id' => Uuid::v7()->toRfc4122(), 'user' => $user->getId()->toRfc4122(), 'key' => $deliveryKey, 'target' => $target, 'status' => NotificationDelivery::SENDING, 'now' => new \DateTimeImmutable()->format('Y-m-d H:i:s')],
            );
            if (1 === $inserted) {
                $claimed[] = [$target, $device, $address];
            }
        }

        return $claimed;
    }

    private function record(string $deliveryKey, string $target, ?NotificationDevice $device, PushResult $result): void
    {
        $this->entityManager->wrapInTransaction(function () use ($deliveryKey, $target, $device, $result): void {
            $connection = $this->entityManager->getConnection();
            $connection->update('notification_delivery', ['status' => $result->status, 'error' => null === $result->error ? null : mb_substr($result->error, 0, 500)], ['delivery_key' => $deliveryKey, 'target' => $target]);
            if (NotificationDelivery::GONE === $result->status && null !== $device) {
                // The push service forgot the device (Expo DeviceNotRegistered, web push 404 or 410); so do we.
                $connection->delete('notification_device', ['id' => $device->getId()->toRfc4122()]);
            }
        });
        if (NotificationDelivery::FAILED === $result->status) {
            $this->logger->warning('A notification could not be delivered', ['key' => $deliveryKey, 'target' => $target, 'error' => $result->error]);
        }
    }
}
