<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Calendar\CalendarTestHelpers;
use App\Todo\ReplanTodos;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Todos in the calendar: planning a todo before its due date with the meeting time finder, its time counting as busy,
 * and that time following the todo and the calendar.
 */
final class TodoPlanningTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use CalendarTestHelpers;
    use ClockSensitiveTrait;

    private const array SCOPES = ['mail:read', 'calendar:read', 'calendar:write', 'settings:read', 'settings:write', 'todos:read', 'todos:write'];
    /** Saturday 20 March 2027; Amsterdam is at +01:00, working hours are 09:00 to 17:00 on weekdays. */
    private const string NOW = '2027-03-20T12:00:00+00:00';
    private const string MONDAY = '2027-03-22';
    private const string TUESDAY = '2027-03-23';

    /** @var array<string, string> */
    private array $kim;

    private string $calendarId;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->createUser('lisa@example.test', 'Lisa');
        $this->kim = $this->userHeaders('kim@example.test', self::SCOPES);
        $this->calendarId = $this->localCalendarId($this->kim);
        $this->notificationSettings(['timeZone' => 'Europe/Amsterdam', 'workEnd' => '17:00']);
        $this->takeReplans();
    }

    public function testPlanningPicksTheEarliestFreeTimeBeforeTheDueDate(): void
    {
        $this->createEvent('Standup', self::MONDAY.'T09:00:00', self::MONDAY.'T10:00:00');
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);

        $planned = $this->plan($todo['id']);

        self::assertSame(['2027-03-22T09:00:00+00:00', '2027-03-22T10:00:00+00:00', 'planned'], [$planned['plannedStart'], $planned['plannedEnd'], $planned['planState']]);
    }

    public function testATodoShorterThanFiveMinutesHoldsOnlyItsOwnLength(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 2]);

        $planned = $this->plan($todo['id']);

        self::assertSame(['2027-03-22T08:00:00+00:00', '2027-03-22T08:02:00+00:00'], [$planned['plannedStart'], $planned['plannedEnd']]);
    }

    public function testPlannedTimeIsBusyForTheMeetingTimeFinder(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);

        self::assertSame('2027-03-22T09:00:00+00:00', $this->firstMeetingTime());
        $this->client->jsonRequest('POST', '/api/v1/calendar/meeting-times', ['from' => self::MONDAY.'T00:00:00+01:00', 'to' => self::TUESDAY.'T00:00:00+01:00', 'durationMinutes' => 60, 'limit' => 1], $this->kim);
        $slots = $this->decodedResponse()['slots'] ?? null;
        self::assertIsArray($slots);
        self::assertIsArray($slots[0] ?? null);
        self::assertSame(['at' => '2027-03-22T09:00:00+00:00', 'reason' => 'busy', 'label' => 'Write the report'], $slots[0]['freeFrom'] ?? null, 'With the todos scope, a slot after planned time names the todo.');
        self::assertSame('2027-03-22T08:00:00+00:00', $this->firstMeetingTime(['ignore' => [$todo['id']]]), 'A search can leave the todo out by its id.');
    }

    public function testATodoThatCannotBePlannedSaysWhy(): void
    {
        $withoutDue = $this->createTodo(['durationMinutes' => 30]);
        $withoutDuration = $this->createTodo(['dueDate' => self::TUESDAY]);
        $overdue = $this->createTodo(['dueDate' => '2027-03-19', 'durationMinutes' => 30]);
        $tooLong = $this->createTodo(['dueDate' => '2027-03-30', 'durationMinutes' => 1500]);
        $this->createEvent('Workshop', self::MONDAY.'T09:00:00', self::MONDAY.'T12:00:00');
        $noTime = $this->createTodo(['dueDate' => self::MONDAY, 'dueTime' => '11:00', 'durationMinutes' => 30]);

        self::assertSame('Give the todo a due date to plan it.', $this->planRefused($withoutDue['id']));
        self::assertSame('Say how long the todo takes to plan it.', $this->planRefused($withoutDuration['id']));
        self::assertSame('The due date has passed.', $this->planRefused($overdue['id']));
        self::assertSame('A todo that takes more than 24 hours cannot be planned in one go.', $this->planRefused($tooLong['id']));
        self::assertSame('There is no free time that long before the due date.', $this->planRefused($noTime['id']));
        self::assertNull($this->todo($noTime['id'])['plannedStart']);
    }

    public function testADoneTodoIsNotPlanned(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 30]);
        $this->patchTodo($todo['id'], ['done' => true]);

        self::assertSame('A done todo is not planned.', $this->planRefused($todo['id']));
    }

    public function testPlanningAgainLooksForTheEarliestFreeTimeAgain(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);
        // Booked over without the calendar telling: no re-check ran.
        $this->connection()->executeStatement("UPDATE todo SET planned_start = '2027-03-22 14:00', planned_end = '2027-03-22 15:00' WHERE id = :id", ['id' => $todo['id']]);

        self::assertSame('2027-03-22T08:00:00+00:00', $this->plan($todo['id'])['plannedStart']);
    }

    public function testRemovingThePlanGivesTheTimeBack(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);

        $this->client->request('DELETE', '/api/v1/todos/'.self::string($todo['id']).'/plan', server: $this->kim);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([null, null, null], $this->planOf($this->todo($todo['id'])));
        self::assertSame('2027-03-22T08:00:00+00:00', $this->firstMeetingTime());
    }

    public function testCompletingTheTodoGivesItsTimeBack(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);

        $done = $this->patchTodo($todo['id'], ['done' => true]);

        self::assertSame([null, null, null], $this->planOf($done));
        self::assertSame('2027-03-22T08:00:00+00:00', $this->firstMeetingTime());
    }

    public function testDeletingTheTodoGivesItsTimeBack(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);

        $this->client->request('DELETE', '/api/v1/todos/'.self::string($todo['id']), server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        self::assertSame('2027-03-22T08:00:00+00:00', $this->firstMeetingTime());
    }

    public function testClearingTheDueDateOrTheDurationGivesTheTimeBack(): void
    {
        $first = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $second = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($first['id']);
        $this->plan($second['id']);

        self::assertSame([null, null, null], $this->planOf($this->patchTodo($first['id'], ['dueDate' => null])));
        self::assertSame([null, null, null], $this->planOf($this->patchTodo($second['id'], ['durationMinutes' => null])));
    }

    public function testALongerDurationMovesThePlannedTime(): void
    {
        $this->createEvent('Lunch', self::MONDAY.'T10:00:00', self::MONDAY.'T11:00:00');
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);

        $moved = $this->patchTodo($todo['id'], ['durationMinutes' => 90]);

        self::assertSame(['2027-03-22T10:00:00+00:00', '2027-03-22T11:30:00+00:00', 'planned'], $this->planOf($moved));
    }

    public function testChangesThatKeepThePlannedTimeRightLeaveItWhereItIs(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);
        $this->createEvent('Review', self::MONDAY.'T13:00:00', self::MONDAY.'T14:00:00');

        $later = $this->patchTodo($todo['id'], ['dueDate' => '2027-03-26', 'title' => 'Write the long report']);

        self::assertSame(['2027-03-22T08:00:00+00:00', '2027-03-22T09:00:00+00:00', 'planned'], $this->planOf($later));
    }

    public function testAnEarlierDueDateWithoutFreeTimeFlagsTheConflict(): void
    {
        $this->createEvent('Standup', self::MONDAY.'T09:00:00', self::MONDAY.'T10:00:00');
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);

        $earlier = $this->patchTodo($todo['id'], ['dueDate' => self::MONDAY, 'dueTime' => '10:30']);

        self::assertSame(['2027-03-22T09:00:00+00:00', '2027-03-22T10:00:00+00:00', 'conflict'], $this->planOf($earlier), 'The time stays where it was, flagged.');
    }

    public function testAnEventBookedOverThePlannedTimeMovesIt(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);

        $this->createEvent('Customer call', self::MONDAY.'T09:30:00', self::MONDAY.'T10:30:00');
        self::assertCount(1, $this->runReplans(), 'One check for one change.');

        self::assertSame(['2027-03-22T09:30:00+00:00', '2027-03-22T10:30:00+00:00', 'planned'], $this->planOf($this->todo($todo['id'])));
    }

    public function testAConflictIsFlaggedWhenNoOtherTimeIsFreeAndClearedWhenTheTimeIsFreeAgain(): void
    {
        $todo = $this->createTodo(['dueDate' => self::MONDAY, 'dueTime' => '11:00', 'durationMinutes' => 60]);
        $this->plan($todo['id']);
        $this->createEvent('Workshop prep', self::MONDAY.'T10:00:00', self::MONDAY.'T11:00:00');
        $this->runReplans();

        $event = $this->createEvent('Workshop', self::MONDAY.'T09:00:00', self::MONDAY.'T10:00:00');
        $this->runReplans();
        self::assertSame(['2027-03-22T08:00:00+00:00', '2027-03-22T09:00:00+00:00', 'conflict'], $this->planOf($this->todo($todo['id'])));

        $this->client->request('DELETE', '/api/v1/calendar-events/'.$event, server: $this->kim);
        self::assertResponseIsSuccessful();
        $this->runReplans();
        self::assertSame(['2027-03-22T08:00:00+00:00', '2027-03-22T09:00:00+00:00', 'planned'], $this->planOf($this->todo($todo['id'])));
    }

    public function testTimeThatHasStartedIsLeftAlone(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);
        self::mockTime(new \DateTimeImmutable('2027-03-22T08:30:00+00:00'));

        $this->createEvent('Surprise', self::MONDAY.'T09:00:00', self::MONDAY.'T10:00:00');
        $this->runReplans();

        self::assertSame(['2027-03-22T08:00:00+00:00', '2027-03-22T09:00:00+00:00', 'planned'], $this->planOf($this->todo($todo['id'])));
    }

    public function testEarlierDueDatesGetTheEarlierTimeWhenTheCalendarChanges(): void
    {
        $later = $this->createTodo(['title' => 'Later', 'dueDate' => '2027-03-26', 'durationMinutes' => 60]);
        $sooner = $this->createTodo(['title' => 'Sooner', 'dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($later['id']);
        $this->plan($sooner['id']);

        $this->createEvent('Morning block', self::MONDAY.'T09:00:00', self::MONDAY.'T11:00:00');
        $this->runReplans();

        self::assertSame('2027-03-22T10:00:00+00:00', $this->todo($sooner['id'])['plannedStart']);
        self::assertSame('2027-03-22T11:00:00+00:00', $this->todo($later['id'])['plannedStart']);
    }

    public function testSwitchingPlanningOnPlansTheOpenTodosThatCanBe(): void
    {
        $plannable = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $withoutDuration = $this->createTodo(['dueDate' => self::TUESDAY]);
        self::assertNull($plannable['plannedStart'], 'Off until the user switches it on.');

        $this->setPlanTodos(true);
        $this->runReplans();

        self::assertSame('2027-03-22T08:00:00+00:00', $this->todo($plannable['id'])['plannedStart']);
        self::assertNull($this->todo($withoutDuration['id'])['plannedStart']);
    }

    public function testWithPlanningOnTodosArePlannedWhenTheyCanBe(): void
    {
        $this->setPlanTodos(true);
        $this->runReplans();

        $made = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 30]);
        $later = $this->createTodo(['title' => 'Book the venue']);
        $given = $this->patchTodo($later['id'], ['dueDate' => self::TUESDAY, 'durationMinutes' => 30]);

        self::assertSame('2027-03-22T08:00:00+00:00', $made['plannedStart']);
        self::assertSame('2027-03-22T08:30:00+00:00', $given['plannedStart']);
    }

    public function testWithPlanningOnARemovedPlanStaysRemovedUntilTheTodoChanges(): void
    {
        $this->setPlanTodos(true);
        $this->runReplans();
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 30]);
        $this->client->request('DELETE', '/api/v1/todos/'.self::string($todo['id']).'/plan', server: $this->kim);

        $this->createEvent('Standup', self::MONDAY.'T13:00:00', self::MONDAY.'T13:15:00');
        $this->runReplans();
        self::assertNull($this->todo($todo['id'])['plannedStart']);

        $this->patchTodo($todo['id'], ['done' => true]);
        self::assertSame('2027-03-22T08:00:00+00:00', $this->patchTodo($todo['id'], ['done' => false])['plannedStart'], 'Reopened, it is planned again.');
    }

    public function testWithPlanningOnARemovedPlanStaysRemovedWhenAnEditKeepsTheDueDateAndDuration(): void
    {
        $this->setPlanTodos(true);
        $this->runReplans();
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'dueTime' => '17:00', 'durationMinutes' => 30]);
        $this->client->request('DELETE', '/api/v1/todos/'.self::string($todo['id']).'/plan', server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        // A client that sends the whole form back: the same due date, time and duration, with a new title.
        $this->patchTodo($todo['id'], ['title' => 'Renamed', 'dueDate' => self::TUESDAY, 'dueTime' => '17:00', 'durationMinutes' => 30]);
        $this->createEvent('Standup', self::MONDAY.'T13:00:00', self::MONDAY.'T13:15:00');
        $this->runReplans();

        self::assertNull($this->todo($todo['id'])['plannedStart'], 'Nothing it is planned by changed.');
        self::assertSame('2027-03-22T08:00:00+00:00', $this->patchTodo($todo['id'], ['durationMinutes' => 45])['plannedStart'], 'A new duration is a change.');
    }

    public function testTheDeltaCarriesThePlannedTime(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->client->request('GET', '/api/v1/sync/changes?since=0&limit=2000', server: $this->kim);
        self::assertResponseIsSuccessful();
        $cursor = $this->decodedResponse()['cursor'] ?? null;
        self::assertIsInt($cursor);

        $this->plan($todo['id']);

        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor.'&limit=2000', server: $this->kim);
        self::assertResponseIsSuccessful();
        $todos = $this->decodedResponse()['todos'] ?? null;
        self::assertIsArray($todos);
        self::assertCount(1, $todos);
        self::assertIsArray($todos[0]);
        self::assertSame(['2027-03-22T08:00:00+00:00', '2027-03-22T09:00:00+00:00', 'planned'], $this->planOf($todos[0]));
    }

    public function testOnlyAChangeOfTheSchedulingPreferencesChecksThePlannedTimeAgain(): void
    {
        $this->setPlanTodos(true);
        self::assertCount(1, $this->takeReplans());

        $this->setPlanTodos(true);
        self::assertSame([], $this->takeReplans(), 'Already on.');
    }

    public function testSwitchingPlanningOffLeavesPlannedTimeWhereItIs(): void
    {
        $this->setPlanTodos(true);
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);

        $this->setPlanTodos(false);
        $this->runReplans();

        self::assertSame('2027-03-22T08:00:00+00:00', $this->todo($todo['id'])['plannedStart']);
    }

    public function testANewZoneMovesTimeThatNoLongerEndsBeforeTheDeadline(): void
    {
        $this->notificationSettings(['timeZone' => 'America/New_York']);
        $this->runReplans();
        $this->createEvent('Client day', self::MONDAY.'T09:00:00', self::MONDAY.'T16:00:00', 'America/New_York');
        $todo = $this->createTodo(['dueDate' => self::MONDAY, 'dueTime' => '17:00', 'durationMinutes' => 60]);
        // 16:00 to 17:00 in New York, on summer time since 14 March.
        self::assertSame('2027-03-22T20:00:00+00:00', $this->plan($todo['id'])['plannedStart']);

        $this->notificationSettings(['timeZone' => 'Europe/Amsterdam']);
        self::assertCount(1, $this->runReplans());

        // Due 17:00 in Amsterdam now (16:00 UTC): the first free working hour there, before the client day.
        self::assertSame(['2027-03-22T08:00:00+00:00', '2027-03-22T09:00:00+00:00', 'planned'], $this->planOf($this->todo($todo['id'])));
    }

    public function testNewWorkingHoursMoveTimeOutsideThem(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);

        $this->notificationSettings(['workStart' => '10:00']);
        self::assertCount(1, $this->runReplans());

        self::assertSame(['2027-03-22T09:00:00+00:00', '2027-03-22T10:00:00+00:00', 'planned'], $this->planOf($this->todo($todo['id'])));
    }

    public function testABufferMovesTimeRightNextToAnEvent(): void
    {
        $this->createEvent('Review', self::MONDAY.'T10:00:00', self::MONDAY.'T11:00:00');
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);
        $this->runReplans();

        $this->client->request('PATCH', '/api/v1/calendar/scheduling-preferences', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['bufferMinutes' => 15], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->runReplans());

        // 15 minutes clear of the review, which ends at 11:00.
        self::assertSame('2027-03-22T10:15:00+00:00', $this->todo($todo['id'])['plannedStart']);
    }

    public function testShowingAHiddenCalendarMovesTimeItsEventsTake(): void
    {
        $this->setCalendarVisible(false);
        self::assertCount(1, $this->runReplans());
        $this->createEvent('Standup', self::MONDAY.'T09:00:00', self::MONDAY.'T10:00:00');
        $this->runReplans();
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        self::assertSame('2027-03-22T08:00:00+00:00', $this->plan($todo['id'])['plannedStart'], 'A hidden calendar keeps no time busy.');

        $this->setCalendarVisible(true);
        self::assertCount(1, $this->runReplans());

        self::assertSame('2027-03-22T09:00:00+00:00', $this->todo($todo['id'])['plannedStart']);
    }

    public function testPlannedTimeKeepsItsClockTimeAcrossTheSwitchToSummerTime(): void
    {
        // Saturday 27 March; Amsterdam moves to +02:00 on Sunday the 28th.
        self::mockTime(new \DateTimeImmutable('2027-03-27T12:00:00+00:00'));
        $todo = $this->createTodo(['dueDate' => '2027-03-30', 'durationMinutes' => 60]);
        self::assertSame('2027-03-29T07:00:00+00:00', $this->plan($todo['id'])['plannedStart'], '09:00 on summer time.');

        $this->createEvent('Lunch', '2027-03-29T12:00:00', '2027-03-29T13:00:00');
        $this->runReplans();

        self::assertSame(['2027-03-29T07:00:00+00:00', '2027-03-29T08:00:00+00:00', 'planned'], $this->planOf($this->todo($todo['id'])));
    }

    public function testWithPlanningOnATodoWithoutTimeIsPlannedOnceTheCalendarFreesUp(): void
    {
        $this->setPlanTodos(true);
        $this->runReplans();
        $event = $this->createEvent('Offsite', self::MONDAY.'T09:00:00', self::MONDAY.'T17:00:00');
        $this->runReplans();
        $todo = $this->createTodo(['dueDate' => self::MONDAY, 'durationMinutes' => 60]);
        self::assertNull($todo['plannedStart'], 'No free time yet.');

        $this->client->request('DELETE', '/api/v1/calendar-events/'.$event, server: $this->kim);
        self::assertResponseIsSuccessful();
        $this->runReplans();

        self::assertSame('2027-03-22T08:00:00+00:00', $this->todo($todo['id'])['plannedStart']);
    }

    public function testTodoTitlesStayHiddenFromAccessWithoutTheTodosScope(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $this->plan($todo['id']);
        $calendarOnly = $this->userHeaders('kim@example.test', ['calendar:read']);

        $this->client->jsonRequest('POST', '/api/v1/calendar/meeting-times', ['from' => self::MONDAY.'T00:00:00+01:00', 'to' => self::TUESDAY.'T00:00:00+01:00', 'durationMinutes' => 60, 'limit' => 1], $calendarOnly);
        self::assertResponseIsSuccessful();
        $slots = $this->decodedResponse()['slots'] ?? null;
        self::assertIsArray($slots);
        self::assertIsArray($slots[0] ?? null);
        self::assertSame(['at' => '2027-03-22T09:00:00+00:00', 'reason' => 'busy', 'label' => 'Planned todo'], $slots[0]['freeFrom'] ?? null);
        self::assertStringNotContainsString('Write the report', (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherUsersTodoCannotBePlanned(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $lisa = $this->userHeaders('lisa@example.test', self::SCOPES);

        $this->client->request('POST', '/api/v1/todos/'.self::string($todo['id']).'/plan', server: $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/api/v1/todos/'.self::string($todo['id']).'/plan', server: $lisa);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPlanningNeedsTheTodosWriteScope(): void
    {
        $todo = $this->createTodo(['dueDate' => self::TUESDAY, 'durationMinutes' => 60]);
        $readOnly = $this->userHeaders('kim@example.test', ['todos:read']);

        $this->client->request('POST', '/api/v1/todos/'.self::string($todo['id']).'/plan', server: $readOnly);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<mixed> $todo
     *
     * @return list<mixed>
     */
    private function planOf(array $todo): array
    {
        return [$todo['plannedStart'], $todo['plannedEnd'], $todo['planState']];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function createTodo(array $input): array
    {
        $this->client->jsonRequest('POST', '/api/v1/todos', [...['title' => 'Write the report'], ...$input], $this->kim);
        self::assertResponseStatusCodeSame(201);

        /** @var array<string, mixed> */
        return $this->decodedResponse();
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function patchTodo(mixed $id, array $changes): array
    {
        $this->client->request('PATCH', '/api/v1/todos/'.self::string($id), server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode($changes, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> */
        return $this->decodedResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function todo(mixed $id): array
    {
        /** @var array<string, mixed> */
        return $this->getJson('/api/v1/todos/'.self::string($id), $this->kim);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(mixed $id): array
    {
        $this->client->request('POST', '/api/v1/todos/'.self::string($id).'/plan', server: $this->kim);
        self::assertResponseStatusCodeSame(200);

        /** @var array<string, mixed> */
        return $this->decodedResponse();
    }

    private function planRefused(mixed $id): string
    {
        $this->client->request('POST', '/api/v1/todos/'.self::string($id).'/plan', server: $this->kim);
        self::assertResponseStatusCodeSame(422);

        return self::string($this->decodedResponse()['detail'] ?? null);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function notificationSettings(array $changes): void
    {
        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode($changes, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    private function setCalendarVisible(bool $visible): void
    {
        $this->client->request('PATCH', '/api/v1/calendars/'.$this->calendarId, server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['visible' => $visible], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    private function setPlanTodos(bool $on): void
    {
        $this->client->request('PATCH', '/api/v1/calendar/scheduling-preferences', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['planTodos' => $on], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame($on, $this->decodedResponse()['planTodos'] ?? null);
    }

    /**
     * The start of the first free hour on Monday the finder proposes.
     *
     * @param array<string, mixed> $query
     */
    private function firstMeetingTime(array $query = []): string
    {
        $this->client->jsonRequest('POST', '/api/v1/calendar/meeting-times', [...['from' => self::MONDAY.'T00:00:00+01:00', 'to' => self::TUESDAY.'T00:00:00+01:00', 'durationMinutes' => 60, 'limit' => 1], ...$query], $this->kim);
        self::assertResponseIsSuccessful();
        $slots = $this->decodedResponse()['slots'] ?? null;
        self::assertIsArray($slots);
        self::assertIsArray($slots[0] ?? null);

        return self::string($slots[0]['start'] ?? null);
    }

    /** An event in the Tresso calendar, in Amsterdam time unless another zone is named; returns its id. */
    private function createEvent(string $summary, string $start, string $end, string $zone = 'Europe/Amsterdam'): string
    {
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->calendarId, [
            'summary' => $summary,
            'start' => ['dateTime' => $start, 'timeZone' => $zone],
            'end' => ['dateTime' => $end, 'timeZone' => $zone],
        ]), $this->kim);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    /**
     * Handles the queued re-checks of planned todos, as the worker would.
     *
     * @return list<ReplanTodos> the re-checks that ran
     */
    private function runReplans(): array
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $ran = [];
        foreach ($this->takeReplans() as $message) {
            $bus->dispatch($message, [new ReceivedStamp('async')]);
            $ran[] = $message;
        }

        return $ran;
    }

    /**
     * @phpstan-impure
     *
     * @return list<ReplanTodos>
     */
    private function takeReplans(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = [];
        foreach ($transport->get() as $envelope) {
            if ($envelope->getMessage() instanceof ReplanTodos) {
                $messages[] = $envelope->getMessage();
            }
        }
        $transport->reset();

        return $messages;
    }
}
