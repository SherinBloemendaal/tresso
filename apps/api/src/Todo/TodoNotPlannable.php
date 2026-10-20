<?php

declare(strict_types=1);

namespace App\Todo;

/**
 * A todo cannot get time in the calendar: it misses what planning needs, or no free time is left before its deadline.
 * The message is for the user.
 */
final class TodoNotPlannable extends \RuntimeException
{
    public static function done(): self
    {
        return new self('A done todo is not planned.');
    }

    public static function withoutDeadline(): self
    {
        return new self('Give the todo a due date to plan it.');
    }

    public static function withoutDuration(): self
    {
        return new self('Say how long the todo takes to plan it.');
    }

    public static function tooLong(): self
    {
        return new self('A todo that takes more than 24 hours cannot be planned in one go.');
    }

    public static function deadlinePassed(): self
    {
        return new self('The due date has passed.');
    }

    public static function noFreeTime(): self
    {
        return new self('There is no free time that long before the due date.');
    }

    public static function noWorkingHours(): self
    {
        return new self('There are no working hours left before the due date.');
    }

    public static function calendarTooFull(string $why): self
    {
        return new self($why);
    }
}
