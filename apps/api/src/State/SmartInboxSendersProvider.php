<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Output\SenderStatistic;
use App\ApiResource\SmartInboxSenders;
use App\Bulk\SenderStatistics;
use App\Repository\CategoryRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<SmartInboxSenders>
 */
final readonly class SmartInboxSendersProvider implements ProviderInterface
{
    public function __construct(
        private CategoryRepository $smartInboxes,
        private SenderStatistics $statistics,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SmartInboxSenders
    {
        $owner = $this->currentUser->get();
        $id = $uriVariables['id'] ?? null;
        $smartInbox = \is_string($id) ? $this->smartInboxes->findOneOwnedBy($owner, $id) : null;
        if (null === $smartInbox) {
            throw new NotFoundHttpException('Smart inbox not found.');
        }

        $statistics = $this->statistics->forSmartInbox($owner, $smartInbox);

        return new SmartInboxSenders(
            $smartInbox->getId()->toRfc4122(),
            $statistics['conversations'],
            $statistics['messages'],
            $statistics['unread'],
            array_map(static fn (array $sender): SenderStatistic => new SenderStatistic(...$sender), $statistics['senders']),
        );
    }
}
