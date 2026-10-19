<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Enum\BuiltinCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a user together with everything a new account needs: settings and the built-in smart inbox categories.
 */
final readonly class UserProvisioner
{
    private const array BUILTIN_CATEGORIES = [
        BuiltinCategory::Todo->value => [
            'name' => 'Todo',
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
            'name' => 'Newsletters and offers',
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
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function provision(string $email, string $displayName, #[\SensitiveParameter] string $plainPassword): User
    {
        $user = new User($email, $displayName);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->persist($user);
        $this->entityManager->persist(new UserSettings($user));

        $position = 0;
        foreach (BuiltinCategory::cases() as $builtin) {
            $definition = self::BUILTIN_CATEGORIES[$builtin->value];
            $category = new Category($user, $definition['name'], $definition['instructions'], $builtin->defaultVerdict(), $builtin);
            $category->setAutoCreateTodo($definition['autoCreateTodo']);
            $category->setBatchDelivery($definition['batchDelivery']);
            $category->setNotify($definition['notify']);
            $category->setPosition($position++);
            $this->entityManager->persist($category);
        }

        $this->entityManager->flush();

        return $user;
    }
}
