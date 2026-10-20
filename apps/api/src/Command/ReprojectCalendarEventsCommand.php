<?php

declare(strict_types=1);

namespace App\Command;

use App\Calendar\EventProjector;
use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\InvalidCalendarData;
use App\Repository\CalendarObjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Projects the stored calendar objects onto their event rows again, so rows stored before a field existed get it
 * (an override's thisAndFuture, read from RANGE=THISANDFUTURE). The iCalendar text is the truth and is not changed,
 * and nothing is written to a provider; a row whose fields change is synced to the clients as any change is.
 *
 * It walks the objects in id order, a batch at a time, each in its own transaction under the object's lock, and
 * prints where it is, so an interrupted run continues with --after.
 */
#[AsCommand(name: 'app:calendar:reproject', description: 'Project the stored calendar objects onto their event rows again')]
final readonly class ReprojectCalendarEventsCommand
{
    private const int BATCH = 200;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private CalendarObjectRepository $objects,
        private EventProjector $projector,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Continue after this calendar object id (as printed by an interrupted run)')]
        ?string $after = null,
    ): int {
        if (null !== $after && !Uuid::isValid($after)) {
            $io->error('--after takes a calendar object id.');

            return Command::INVALID;
        }

        $projected = 0;
        $skipped = 0;
        $cursor = $after;
        while (true) {
            /** @var list<string> $ids */
            $ids = $this->connection->fetchFirstColumn(
                'SELECT id FROM calendar_object WHERE (CAST(:after AS uuid) IS NULL OR id > CAST(:after AS uuid)) ORDER BY id LIMIT '.self::BATCH,
                ['after' => $cursor],
            );
            if ([] === $ids) {
                break;
            }
            foreach ($ids as $id) {
                $object = $this->objects->find(Uuid::fromString($id));
                if (null === $object) {
                    continue;
                }
                try {
                    $this->entityManager->wrapInTransaction(function () use ($object): void {
                        $locked = $this->objects->lock($object);
                        $this->projector->project($locked, CalendarDocument::parse($locked->getIcal()));
                    });
                    ++$projected;
                } catch (InvalidCalendarData $error) {
                    // Stored before a stricter reader: left as it is, and named so it can be looked at.
                    $io->warning(\sprintf('%s: %s', $id, $error->getMessage()));
                    ++$skipped;
                }
            }
            $cursor = $ids[\count($ids) - 1];
            $this->entityManager->clear();
            $io->writeln(\sprintf('Up to %s', $cursor));
        }

        $io->success(\sprintf('Projected %d calendar objects again%s.', $projected, 0 === $skipped ? '' : \sprintf(', left %d that cannot be read', $skipped)));

        return Command::SUCCESS;
    }
}
