<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CleanupSuggestionList;
use App\ApiResource\Output\CleanupSuggestionOutput;
use App\Bulk\CleanupSuggestions;
use App\Security\CurrentUser;

/**
 * @implements ProviderInterface<CleanupSuggestionList>
 */
final readonly class CleanupSuggestionProvider implements ProviderInterface
{
    public function __construct(
        private CleanupSuggestions $suggestions,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CleanupSuggestionList
    {
        return new CleanupSuggestionList(array_map(CleanupSuggestionOutput::fromSuggestion(...), $this->suggestions->forUser($this->currentUser->get(), new \DateTimeImmutable())));
    }
}
