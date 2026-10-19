<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use Symfony\Component\Validator\Constraints as Assert;

final class ReminderInput
{
    /** 0 is at the start; at most four weeks before. */
    #[Assert\Range(min: 0, max: 40320)]
    public int $minutesBefore = 10;

    /** @var list<string> */
    #[Assert\Count(min: 1, max: 3)]
    #[Assert\All([new Assert\Choice(choices: ['phone', 'desktop', 'email'])])]
    public array $channels = ['phone', 'desktop'];

    /**
     * @return array{minutesBefore: int, channels: list<string>}
     */
    public function toArray(): array
    {
        return ['minutesBefore' => $this->minutesBefore, 'channels' => array_values(array_unique($this->channels))];
    }
}
