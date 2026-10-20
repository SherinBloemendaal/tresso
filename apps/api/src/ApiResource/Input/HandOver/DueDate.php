<?php

declare(strict_types=1);

namespace App\ApiResource\Input\HandOver;

use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** A due date lies ahead, at most a year. */
final class DueDate
{
    public static function validate(?\DateTimeImmutable $dueAt, ExecutionContextInterface $context): void
    {
        if (null === $dueAt) {
            return;
        }
        $now = new \DateTimeImmutable();
        if ($dueAt <= $now) {
            $context->buildViolation('The due date must be in the future.')->atPath('dueAt')->addViolation();
        }
        if ($dueAt > $now->modify('+1 year')) {
            $context->buildViolation('The due date can be at most a year ahead.')->atPath('dueAt')->addViolation();
        }
    }
}
