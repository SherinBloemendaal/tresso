<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\CalendarResource;
use App\ApiResource\Input\Calendar\CreateCalendar;
use App\ApiResource\Input\Calendar\UpdateCalendar;
use App\Entity\Calendar;
use App\Repository\CalendarRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<CreateCalendar|UpdateCalendar|CalendarResource, CalendarResource|null>
 */
final readonly class CalendarProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalendarRepository $calendars,
        private CurrentUser $currentUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?CalendarResource
    {
        $owner = $this->currentUser->get();

        if ($data instanceof CreateCalendar) {
            return CalendarResource::fromEntity($this->entityManager->wrapInTransaction(function () use ($owner, $data): Calendar {
                $calendar = Calendar::local($owner, trim($data->name));
                $calendar->setColor($data->color);
                $calendar->setPosition(\count($this->calendars->findBy(['owner' => $owner, 'mailAccount' => null])));
                $this->entityManager->persist($calendar);
                $this->entityManager->flush();

                return $calendar;
            }));
        }

        $id = $uriVariables['id'] ?? null;
        $calendar = \is_string($id) ? $this->calendars->findOneOwnedBy($owner, $id) : null;
        if (!$calendar instanceof Calendar) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        if ($operation instanceof DeleteOperationInterface) {
            if (!$calendar->isLocal()) {
                throw new UnprocessableEntityHttpException('Calendars of a mail account are removed at the provider.');
            }
            if (1 === \count($this->calendars->findBy(['owner' => $owner, 'mailAccount' => null]))) {
                throw new UnprocessableEntityHttpException('The last Tresso calendar cannot be removed.');
            }
            $this->entityManager->wrapInTransaction(function () use ($calendar, $owner): void {
                $wasDefault = $calendar->isDefault();
                $this->entityManager->remove($calendar);
                $this->entityManager->flush();
                if ($wasDefault) {
                    $next = $this->calendars->findLocal($owner);
                    $next?->setDefault(true);
                    $this->entityManager->flush();
                }
            });

            return null;
        }

        \assert($data instanceof UpdateCalendar);

        return CalendarResource::fromEntity($this->entityManager->wrapInTransaction(function () use ($calendar, $data, $owner): Calendar {
            if (null !== $data->name) {
                if (!$calendar->isLocal()) {
                    throw new UnprocessableEntityHttpException('The name of a calendar of a mail account is set at the provider.');
                }
                $calendar->rename(trim($data->name));
            }
            if (null !== $data->color) {
                $calendar->isLocal() ? $calendar->setColor($data->color) : $calendar->setUserColor($data->color);
            }
            if (null !== $data->visible) {
                $calendar->setVisible($data->visible);
            }
            if (true === $data->isDefault) {
                if (!$calendar->isWritable()) {
                    throw new UnprocessableEntityHttpException('A read-only calendar cannot be the default.');
                }
                foreach ($this->calendars->findBy(['owner' => $owner, 'mailAccount' => $calendar->getMailAccount()]) as $sibling) {
                    $sibling->setDefault($sibling === $calendar);
                }
            }
            $this->entityManager->flush();

            return $calendar;
        }));
    }
}
