<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Notification\RegisterDevice;
use App\ApiResource\Input\Notification\UpdateDevice;
use App\ApiResource\NotificationDeviceResource;
use App\Entity\NotificationDevice;
use App\Enum\DeviceKind;
use App\Notification\Channel\WebPushEncryption;
use App\Repository\NotificationDeviceRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<RegisterDevice|UpdateDevice|NotificationDeviceResource, NotificationDeviceResource|null>
 */
final readonly class NotificationDeviceProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationDeviceRepository $devices,
        private CurrentUser $currentUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?NotificationDeviceResource
    {
        $user = $this->currentUser->get();
        $now = new \DateTimeImmutable();

        if ($data instanceof RegisterDevice) {
            $kind = $data->kind ?? throw new \LogicException('Validation guarantees a kind.');
            self::check($data, $kind);

            return NotificationDeviceResource::fromEntity($this->entityManager->wrapInTransaction(function () use ($user, $data, $kind, $now): NotificationDevice {
                $device = $this->devices->findByEndpoint($user, $data->endpoint);
                if (null === $device) {
                    $device = new NotificationDevice($user, $kind, $data->endpoint, trim($data->name));
                    $this->entityManager->persist($device);
                }
                $device->rename(trim($data->name));
                $device->setWebPushKeys($data->publicKey, $data->authSecret);
                $device->seen($now);
                $this->entityManager->flush();

                return $device;
            }));
        }

        $id = $uriVariables['id'] ?? null;
        $device = \is_string($id) ? $this->devices->findOneOwnedBy($user, $id) : null;
        if (!$device instanceof NotificationDevice) {
            throw new NotFoundHttpException('Device not found.');
        }

        if ($operation instanceof DeleteOperationInterface) {
            $this->entityManager->remove($device);
            $this->entityManager->flush();

            return null;
        }

        \assert($data instanceof UpdateDevice);
        if (null !== $data->name) {
            $device->rename(trim($data->name));
        }
        if (null !== $data->enabled) {
            $device->setEnabled($data->enabled);
        }
        $this->entityManager->flush();

        return NotificationDeviceResource::fromEntity($device);
    }

    private static function check(RegisterDevice $data, DeviceKind $kind): void
    {
        $error = match ($kind) {
            DeviceKind::WebPush => match (true) {
                !str_starts_with($data->endpoint, 'https://') || false === filter_var($data->endpoint, \FILTER_VALIDATE_URL) => ['endpoint', 'A push subscription endpoint is an https URL.'],
                null === $data->publicKey || 65 !== \strlen(self::decode($data->publicKey)) => ['publicKey', 'The subscription key must be a P-256 public key.'],
                null === $data->authSecret || 16 !== \strlen(self::decode($data->authSecret)) => ['authSecret', 'The subscription auth secret must be 16 bytes.'],
                default => null,
            },
            DeviceKind::Expo => 1 === preg_match('/^(Exponent|Expo)PushToken\[[^\]]+\]$/', $data->endpoint) ? null : ['endpoint', 'An Expo push token looks like ExponentPushToken[...].'],
            DeviceKind::Desktop => 1 === preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $data->endpoint) ? null : ['endpoint', 'A desktop installation id is 8 to 128 letters, digits and ._:-.'],
        };
        if (null !== $error) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($error[1], null, [], null, $error[0], null)]));
        }
    }

    private static function decode(string $value): string
    {
        try {
            return WebPushEncryption::base64UrlDecode($value);
        } catch (\InvalidArgumentException) {
            return '';
        }
    }
}
