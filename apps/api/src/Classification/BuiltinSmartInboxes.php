<?php

declare(strict_types=1);

namespace App\Classification;

use App\Entity\Category;
use App\Entity\User;
use App\Enum\BuiltinCategory;
use App\Repository\CategoryRepository;
use App\Sync\Change\ChangeSequencer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The five smart inboxes every user has. They are created with the user; {@see ensure()} adds any that are missing,
 * so classification never runs without them.
 */
final readonly class BuiltinSmartInboxes
{
    /** Sidebar order. */
    private const array ORDER = [BuiltinCategory::Todo, BuiltinCategory::Informational, BuiltinCategory::Notification, BuiltinCategory::Advertise, BuiltinCategory::Spam];

    private const array DEFINITIONS = [
        BuiltinCategory::Todo->value => [
            'name' => 'To do',
            'instructions' => 'The recipient is personally expected to do something: answer a question, approve, review, deliver, pay, '
                .'schedule or decide. The request is addressed to the recipient (usually in To), not to someone else in the thread.',
            'autoCreateTodo' => true,
            'batchDelivery' => false,
            'notify' => true,
        ],
        BuiltinCategory::Informational->value => [
            'name' => 'Informational',
            'instructions' => 'Written by a person for the recipient\'s awareness only, with no action expected from the recipient. '
                .'Typical: the recipient is in CC while someone else must act, status updates, "just to let you know" messages, '
                .'absence notices such as a colleague calling in sick.',
            'autoCreateTodo' => false,
            'batchDelivery' => false,
            'notify' => false,
        ],
        BuiltinCategory::Notification->value => [
            'name' => 'Notifications',
            'instructions' => 'Automated messages from systems and services: receipts, shipping updates, security alerts, '
                .'calendar notifications, CI results, social network notifications, password resets.',
            'autoCreateTodo' => false,
            'batchDelivery' => true,
            'notify' => false,
        ],
        BuiltinCategory::Advertise->value => [
            'name' => 'Newsletters',
            'instructions' => 'Newsletters, marketing, promotions, product announcements and other bulk mail that is not time sensitive. '
                .'Often has a List-Unsubscribe header.',
            'autoCreateTodo' => false,
            'batchDelivery' => true,
            'notify' => false,
        ],
        BuiltinCategory::Spam->value => [
            'name' => 'Spam',
            'instructions' => 'Unsolicited, deceptive or malicious mail: phishing, scams, fake invoices, impersonation, '
                .'messages failing SPF/DKIM/DMARC from senders the recipient never interacted with.',
            'autoCreateTodo' => false,
            'batchDelivery' => false,
            'notify' => false,
        ],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CategoryRepository $categories,
        private Connection $connection,
        private ChangeSequencer $sequencer,
    ) {
    }

    /**
     * Smart inboxes created before clients synced them have no change number, so clients would never get them; this
     * gives them one. Must run inside a transaction. Cheap when there is nothing to do.
     */
    public function syncOlder(User $user): void
    {
        $params = ['user' => $user->getId()->toRfc4122()];
        if (false === $this->connection->fetchOne('SELECT 1 FROM category WHERE owner_id = :user AND change_seq = 0 LIMIT 1', $params)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE category SET change_seq = :seq WHERE owner_id = :user AND change_seq = 0',
            [...$params, 'seq' => $this->sequencer->next($user->getId())],
        );
    }

    /**
     * Persists the built-in smart inboxes the user does not have yet (all of them for a new user). The caller flushes.
     *
     * @return list<Category> the ones created
     */
    public function ensure(User $user): array
    {
        $existing = [];
        if (!$this->entityManager->getUnitOfWork()->isScheduledForInsert($user)) {
            foreach ($this->categories->findOwnedBy($user) as $category) {
                if (null !== $category->getBuiltin()) {
                    $existing[$category->getBuiltin()->value] = true;
                }
            }
        }

        $created = [];
        foreach (self::ORDER as $position => $builtin) {
            if (isset($existing[$builtin->value])) {
                continue;
            }
            $definition = self::DEFINITIONS[$builtin->value];
            $category = new Category($user, $definition['name'], $definition['instructions'], $builtin->defaultVerdict(), $builtin);
            $category->setAutoCreateTodo($definition['autoCreateTodo']);
            $category->setBatchDelivery($definition['batchDelivery']);
            $category->setNotify($definition['notify']);
            $category->setPosition($position);
            $this->entityManager->persist($category);
            $created[] = $category;
        }

        return $created;
    }
}
