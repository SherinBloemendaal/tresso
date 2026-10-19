<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\Thread;
use App\Mail\SubjectNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the conversation a new message belongs to.
 *
 * 1. Headers, both directions: threads of messages it refers to (In-Reply-To, References), and threads of messages
 *    that refer to it (a reply that arrived first). When that matches several threads, they are one conversation
 *    and are merged.
 * 2. Without any references: a recent thread with the same normalized subject that shares a participant. This
 *    covers clients that do not send References, without joining unrelated mails that happen to share a subject.
 * 3. Otherwise a new thread.
 */
final readonly class Threader
{
    private const string SUBJECT_WINDOW = 'P14D';
    private const int SUBJECT_CANDIDATES = 5;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<string> $referencedIds normalized Message-IDs this message refers to
     * @param list<string> $participants  lowercased from, to and cc addresses
     */
    public function threadFor(MailAccount $account, ?string $messageId, array $referencedIds, string $subject, array $participants, \DateTimeImmutable $receivedAt): Thread
    {
        $threads = $this->threadsByHeaders($account, $messageId, $referencedIds);

        if ([] !== $threads) {
            $target = array_shift($threads);
            foreach ($threads as $other) {
                $this->merge($target, $other);
            }

            return $target;
        }

        if ([] === $referencedIds) {
            $bySubject = $this->threadBySubject($account, $subject, $participants, $receivedAt);
            if (null !== $bySubject) {
                return $bySubject;
            }
        }

        $thread = new Thread($account, $subject, $receivedAt);
        $this->entityManager->persist($thread);

        return $thread;
    }

    /**
     * @param list<string> $referencedIds
     *
     * @return list<Thread> oldest first
     */
    private function threadsByHeaders(MailAccount $account, ?string $messageId, array $referencedIds): array
    {
        $byId = [];

        if ([] !== $referencedIds) {
            /** @var list<Thread> $parents */
            $parents = $this->entityManager->createQuery(
                'SELECT t FROM App\Entity\Thread t WHERE t.id IN (
                    SELECT IDENTITY(m.thread) FROM App\Entity\Message m
                    WHERE m.mailAccount = :account AND m.messageIdHeader IN (:ids)
                )',
            )->setParameter('account', $account->getId(), 'uuid')->setParameter('ids', $referencedIds)->getResult();
            foreach ($parents as $thread) {
                $byId[$thread->getId()->toRfc4122()] = $thread;
            }
        }

        if (null !== $messageId) {
            /** @var list<Thread> $children */
            $children = $this->entityManager->createQuery(
                'SELECT t FROM App\Entity\Thread t WHERE t.id IN (
                    SELECT IDENTITY(m.thread) FROM App\Entity\MessageReference r JOIN r.message m
                    WHERE r.mailAccount = :account AND r.referencedMessageId = :id
                )',
            )->setParameter('account', $account->getId(), 'uuid')->setParameter('id', $messageId)->getResult();
            foreach ($children as $thread) {
                $byId[$thread->getId()->toRfc4122()] = $thread;
            }
        }

        // UUIDv7 ids sort by creation time, so the oldest thread survives a merge.
        ksort($byId);

        return array_values($byId);
    }

    /**
     * @param list<string> $participants
     */
    private function threadBySubject(MailAccount $account, string $subject, array $participants, \DateTimeImmutable $receivedAt): ?Thread
    {
        $normalized = SubjectNormalizer::normalize($subject);
        if ('' === $normalized || [] === $participants) {
            return null;
        }

        $window = new \DateInterval(self::SUBJECT_WINDOW);
        /** @var list<Thread> $candidates */
        $candidates = $this->entityManager->createQuery(
            'SELECT t FROM App\Entity\Thread t
             WHERE t.mailAccount = :account AND t.normalizedSubject = :subject AND t.lastMessageAt BETWEEN :from AND :until
             ORDER BY t.lastMessageAt DESC',
        )
            ->setParameter('account', $account->getId(), 'uuid')
            ->setParameter('subject', $normalized)
            ->setParameter('from', $receivedAt->sub($window))
            ->setParameter('until', $receivedAt->add($window))
            ->setMaxResults(self::SUBJECT_CANDIDATES)
            ->getResult();

        foreach ($candidates as $thread) {
            $shared = (int) $this->entityManager->createQuery(
                'SELECT COUNT(m.id) FROM App\Entity\Message m WHERE m.thread = :thread AND m.fromAddress IN (:participants)',
            )->setParameter('thread', $thread->getId(), 'uuid')->setParameter('participants', $participants)->getSingleScalarResult();

            if ($shared > 0) {
                return $thread;
            }
        }

        return null;
    }

    private function merge(Thread $target, Thread $other): void
    {
        /** @var list<Message> $messages */
        $messages = $this->entityManager->getRepository(Message::class)->findBy(['thread' => $other]);
        foreach ($messages as $message) {
            $message->moveToThread($target);
            $target->recordMessage($message->getReceivedAt());
        }
        $this->entityManager->remove($other);
    }
}
