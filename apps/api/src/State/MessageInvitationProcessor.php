<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MessageInvitation;
use App\Calendar\Invitation\InvitationApplier;
use App\Calendar\Invitation\InvitationOutcome;
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
 * Applies calendar mail the calendar did not take by itself, on the user's word, with the checks that calendar mail
 * in the inbox gets.
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
        if (!\in_array($invitation->getMethod(), [ItipMethod::Request, ItipMethod::Cancel, ItipMethod::Reply], true)) {
            throw new UnprocessableEntityHttpException('Only invitations, updates, cancellations and replies change the calendar.');
        }

        $outcome = $this->entityManager->wrapInTransaction(function () use ($invitation): ?InvitationOutcome {
            $locked = $this->entityManager->find(CalendarInvitation::class, $invitation->getId(), LockMode::PESSIMISTIC_WRITE);

            // Applied before (by the worker, or an earlier click): nothing more to do.
            return null !== $locked && null === $locked->getAppliedAt() ? $this->applier->applyNow($locked) : null;
        });
        if (null !== $outcome && InvitationOutcome::APPLIED !== $outcome->status) {
            // Ignored mail is used up (applying it again cannot change the answer); mail without a calendar to go
            // to stays, for once there is one.
            throw new UnprocessableEntityHttpException($outcome->reason ?? 'This calendar mail cannot be applied.');
        }

        return $this->provider->provide($operation, $uriVariables, $context);
    }
}
