<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Notification\ReminderRuleInput;
use App\ApiResource\Input\Notification\RuleReminderInput;
use App\ApiResource\ReminderRuleResource;
use App\Entity\ReminderRule;
use App\Entity\User;
use App\Notification\PlanUserNotifications;
use App\Realtime\AfterCommitDispatcher;
use App\Repository\CalendarRepository;
use App\Repository\ReminderRuleRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Every change replans the user's notifications.
 *
 * @implements ProcessorInterface<ReminderRuleInput|ReminderRuleResource, ReminderRuleResource|null>
 */
final readonly class ReminderRuleProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReminderRuleRepository $rules,
        private CalendarRepository $calendars,
        private CurrentUser $currentUser,
        private AfterCommitDispatcher $afterCommit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ReminderRuleResource
    {
        $user = $this->currentUser->get();
        $id = $uriVariables['id'] ?? null;
        $rule = null;
        if (\is_string($id)) {
            $rule = $this->rules->findOneOwnedBy($user, $id) ?? throw new NotFoundHttpException('Reminder rule not found.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($user, $rule, $data, $operation): ?ReminderRuleResource {
            $this->afterCommit->dispatch(new PlanUserNotifications($user->getId()->toRfc4122()));
            if ($operation instanceof DeleteOperationInterface && null !== $rule) {
                $this->entityManager->remove($rule);
                $this->entityManager->flush();

                return null;
            }
            \assert($data instanceof ReminderRuleInput);
            if (null === $rule) {
                $rule = new ReminderRule($user, trim($data->name));
                $this->entityManager->persist($rule);
            }
            $this->apply($rule, $data, $user);
            $this->entityManager->flush();

            return ReminderRuleResource::fromEntity($rule);
        });
    }

    private function apply(ReminderRule $rule, ReminderRuleInput $data, User $user): void
    {
        $calendar = null;
        if (null !== $data->calendarId) {
            $calendar = $this->calendars->findOneOwnedBy($user, $data->calendarId)
                ?? throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('No calendar with that id.', null, [], null, 'calendarId', $data->calendarId)]));
        }
        $rule->setName(trim($data->name));
        $rule->match($calendar, $data->allDay, $data->role, $data->withAttendees, $data->withConference, $data->withLocation);
        $rule->setReminders(array_map(static fn (RuleReminderInput $reminder): array => $reminder->toArray(), $data->reminders));
        $rule->setLeaveNow($data->leaveNow);
        $rule->setBriefMinutes($data->briefMinutes);
        $rule->setFollowUp($data->followUp);
        $rule->setPosition($data->position);
    }
}
