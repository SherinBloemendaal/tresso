<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Todo;

use App\Entity\Todo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partial update (JSON merge patch). Fields left out stay as they are; null clears the due date, the due time or the
 * duration. That is why the properties have no defaults: one the request did not set stays uninitialized
 * (see {@see has()}).
 */
final class UpdateTodo
{
    #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
    #[Assert\Length(max: Todo::TITLE_MAX_LENGTH)]
    public string $title;

    #[Assert\Length(max: Todo::NOTES_MAX_LENGTH)]
    public string $notes;

    /** YYYY-MM-DD, in the user's own time zone; null clears it, and the due time with it. */
    #[Assert\Date]
    public ?string $dueDate;

    /** HH:MM; only with a due date. */
    #[Assert\Time(withSeconds: false)]
    public ?string $dueTime;

    #[Assert\Range(min: 1, max: Todo::DURATION_MAX_MINUTES)]
    public ?int $durationMinutes;

    public bool $done;

    /** The place among the open todos; lower comes first. */
    #[Assert\Range(min: 0, max: 1000000)]
    public int $position;

    /** Whether the request set this field (to null too). */
    public function has(string $field): bool
    {
        return new \ReflectionProperty($this, $field)->isInitialized($this);
    }
}
