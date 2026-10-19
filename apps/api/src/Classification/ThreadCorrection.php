<?php

declare(strict_types=1);

namespace App\Classification;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\SmartInbox\ThreadClassificationInput;
use App\ApiResource\Output\RememberedSender;
use App\ApiResource\ThreadClassificationResource;
use App\Entity\SenderRule;
use App\Entity\Thread;
use App\Entity\User;
use App\Enum\Verdict;
use App\Repository\CategoryRepository;
use App\Repository\SenderRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Puts a conversation where the user says it belongs and remembers that for its sender.
 *
 * It takes the user's smart inbox lock (it may edit an exclude list) and classification lock first, and only then
 * reads the conversation and the smart inbox, refreshed from the database: a classification run or another device
 * that committed meanwhile is seen, so nothing is written over a stale copy.
 */
final readonly class ThreadCorrection
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CategoryRepository $smartInboxes,
        private SenderRuleRepository $rules,
        private MailClassification $classification,
        private ClassificationScheduler $scheduler,
        private UserLocks $locks,
    ) {
    }

    public function correct(User $owner, string $threadId, ThreadClassificationInput $input): ThreadClassificationResource
    {
        // One transaction, so change numbers for client sync commit in order and the locks hold until the commit.
        return $this->entityManager->wrapInTransaction(function () use ($owner, $threadId, $input): ThreadClassificationResource {
            $this->locks->smartInboxes($owner->getId());
            $this->locks->classification($owner->getId());

            $thread = $this->ownedThread($owner, $threadId);
            $this->entityManager->refresh($thread);
            $smartInbox = $this->smartInboxes->findOneOwnedBy($owner, $input->smartInboxId)
                ?? throw self::invalid($input, 'This smart inbox does not exist.');
            $this->entityManager->refresh($smartInbox);
            $explicitVerdict = null === $input->verdict ? null : Verdict::from($input->verdict);
            $verdict = $explicitVerdict ?? $smartInbox->getVerdict();

            $message = $this->classification->newestIncoming($thread);
            if (null !== $message) {
                $this->entityManager->refresh($message);
            }
            $blocking = null === $message ? null : $smartInbox->exclusionOtherThan($message->getFromAddress());
            if (null !== $blocking) {
                // A rule for the sender would never apply: the classifier skips smart inboxes that exclude the sender.
                throw self::invalid($input, \sprintf('Mail from %s is on the Never include list of %s. Take it off that list first.', $blocking, $smartInbox->getName()));
            }

            $this->classification->placeByUser($thread, $message, $smartInbox, $verdict);

            $rule = null;
            $failsAuthentication = false;
            if (null !== $message) {
                $failsAuthentication = $this->classification->failsAuthentication($message);
                $sender = $message->getFromAddress();
                $rule = $this->rules->findOneBy(['owner' => $owner, 'sender' => $sender]);
                if (null === $rule) {
                    $rule = new SenderRule($owner, $sender, $smartInbox, $explicitVerdict);
                    $this->entityManager->persist($rule);
                } else {
                    $rule->change($smartInbox, $explicitVerdict);
                }
                $smartInbox->stopExcluding($sender);
            }
            $this->entityManager->flush();
            if (null !== $rule) {
                $this->scheduler->senderChanged($owner->getId(), $rule->getSender());
            }

            return new ThreadClassificationResource(
                $thread->getId()->toRfc4122(),
                $smartInbox->getId()->toRfc4122(),
                $verdict->value,
                ClassificationReason::USER,
                null === $rule ? null : RememberedSender::fromEntity($rule, $failsAuthentication),
            );
        });
    }

    private function ownedThread(User $owner, string $id): Thread
    {
        $thread = Uuid::isValid($id) ? $this->entityManager->find(Thread::class, Uuid::fromString($id)) : null;
        if (null === $thread || !$thread->getMailAccount()->getOwner()->getId()->equals($owner->getId())) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        return $thread;
    }

    private static function invalid(ThreadClassificationInput $input, string $message): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, null, [], $input, 'smartInboxId', $input->smartInboxId),
        ]));
    }
}
