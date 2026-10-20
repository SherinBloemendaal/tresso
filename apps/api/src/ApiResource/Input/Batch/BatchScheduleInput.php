<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Batch;

use App\Batch\BatchTimes;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class BatchScheduleInput
{
    /** @var list<string> */
    #[Assert\Count(min: 1, max: BatchTimes::MAX_TIMES, minMessage: 'Choose at least one time.', maxMessage: 'Choose at most {{ limit }} times.')]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex(BatchTimes::TIME_PATTERN, message: 'Use a time like 09:00.')])]
    public array $times = [];

    #[Assert\NotBlank]
    public string $timeZone = '';

    #[Assert\Callback]
    public function validateTimes(ExecutionContextInterface $context): void
    {
        if (\count($this->times) !== \count(array_unique($this->times))) {
            $context->buildViolation('Each time can be chosen once.')->atPath('times')->addViolation();
        }
        if ('' !== $this->timeZone && !BatchTimes::isTimeZone($this->timeZone)) {
            $context->buildViolation('Unknown time zone.')->atPath('timeZone')->addViolation();
        }
    }
}
