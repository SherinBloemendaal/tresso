<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Todo;

use App\Entity\Todo;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A todo made from an email. Everything is optional: the title defaults to the email's subject.
 */
final class MakeTodo
{
    #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
    #[Assert\Length(max: Todo::TITLE_MAX_LENGTH)]
    public ?string $title = null;

    #[Assert\Length(max: Todo::NOTES_MAX_LENGTH)]
    public string $notes = '';

    /** YYYY-MM-DD, in the user's own time zone. */
    #[Assert\Date]
    public ?string $dueDate = null;

    /** HH:MM; only with a due date. */
    #[Assert\Time(withSeconds: false)]
    public ?string $dueTime = null;

    #[Assert\Range(min: 1, max: Todo::DURATION_MAX_MINUTES)]
    public ?int $durationMinutes = null;

    /** Make none when another email of the conversation has an open todo, and answer with that one (triage's do). */
    public bool $perConversation = false;

    #[Assert\Callback]
    public function validateDue(ExecutionContextInterface $context): void
    {
        if (null === $this->dueDate && null !== $this->dueTime) {
            $context->buildViolation('A due time needs a due date.')->atPath('dueTime')->addViolation();
        }
    }
}
