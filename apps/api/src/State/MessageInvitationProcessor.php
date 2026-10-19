<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MessageInvitation;
use App\Calendar\Invitation\InvitationApplier;
use App\Entity\CalendarInvitation;
use App\Entity\Message;
use App\Enum\ItipMethod;
use App\Repository\CalendarInvitationRepository;
use App\Repository\MessageRepository;
use App\Security\CurrentUser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Adds an invitation the calendar did not take by itself, on the user's word, with the checks that calendar mail
 * in the inbox gets: it must come from the organizer and invite the user.
 *
 * @implements ProcessorInterface<null, MessageInvitation>
 */
final readonly class MessageInvitationProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageRepository $messages,
        private CalendarInvitationRepository $invitations,
        private InvitationApplier $applier,
        private MessageInvitationProvider $provider,
        private CurrentUser $currentUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MessageInvitation
    {
        $id = $uriVariables['id'] ?? null;
        $message = \is_string($id) ? $this->messages->findOneOwnedBy($this->currentUser->get(), $id) : null;
        $invitation = $message instanceof Message ? ($this->invitations->findForMessage($message)[0] ?? null) : null;
        if (!$invitation instanceof CalendarInvitation) {
            throw new NotFoundHttpException('This message carries no calendar event.');
        }
        if (ItipMethod::Request !== $invitation->getMethod()) {
            throw new UnprocessableEntityHttpException('Only an invitation can be added to the calendar.');
        }

        $this->entityManager->wrapInTransaction(function () use ($invitation): void {
            $locked = $this->entityManager->find(CalendarInvitation::class, $invitation->getId(), LockMode::PESSIMISTIC_WRITE);
            if (null !== $locked && null === $locked->getAppliedAt()) {
                $this->applier->applyNow($locked);
            }
        });

        $result = $this->provider->provide($operation, $uriVariables, $context);
        if (null === $result->eventId) {
            throw new UnprocessableEntityHttpException('This invitation cannot be added: it does not come from its organizer, or it is not for you.');
        }

        return $result;
    }
}
