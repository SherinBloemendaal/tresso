<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Calendar\EventDraftInput;
use App\Calendar\Assistant\AssistantUnavailable;
use App\Calendar\Assistant\EventDraft;
use App\Calendar\Assistant\EventDraftInterpreter;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @implements ProcessorInterface<EventDraftInput, EventDraft>
 */
final readonly class EventDraftProcessor implements ProcessorInterface
{
    public function __construct(
        private EventDraftInterpreter $interpreter,
        private CurrentUser $currentUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EventDraft
    {
        try {
            return $this->interpreter->interpret($this->currentUser->get(), trim($data->text), new \DateTimeZone($data->timeZone), new \DateTimeImmutable());
        } catch (AssistantUnavailable $e) {
            throw new HttpException(501, $e->getMessage(), $e);
        }
    }
}
