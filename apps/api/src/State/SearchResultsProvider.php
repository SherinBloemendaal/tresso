<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\SearchResults;
use App\Search\Meilisearch\MeilisearchException;
use App\Search\MessageSearch;
use App\Search\Query\InvalidSearchQuery;
use App\Search\Query\SearchQueryParser;
use App\Security\CurrentUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProviderInterface<SearchResults>
 */
final readonly class SearchResultsProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private SearchQueryParser $parser,
        private MessageSearch $search,
        private RequestStack $requests,
        private LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SearchResults
    {
        $owner = $this->currentUser->get();
        $query = $this->requests->getCurrentRequest()?->query;
        $raw = trim((string) $query?->getString('q'));
        $page = (int) ($query?->get('page') ?? 1);
        $perPage = (int) ($query?->get('perPage') ?? 20);
        $timeZone = new \DateTimeZone($query?->getString('timeZone') ?: 'UTC');

        try {
            $parsed = $this->parser->parse($raw, $timeZone);
        } catch (InvalidSearchQuery $exception) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($exception->getMessage(), null, [], null, 'q', $raw)]));
        }
        if ($parsed->isEmpty()) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('Type something to search for.', null, [], null, 'q', $raw)]));
        }

        try {
            return $this->search->search($owner, $raw, $parsed, $page, $perPage);
        } catch (MeilisearchException $exception) {
            $this->logger->error('Search failed.', ['exception' => $exception]);

            throw new ServiceUnavailableHttpException(null, 'Search is not available right now.', $exception);
        }
    }
}
