<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use Symfony\Component\Validator\Constraints as Assert;

final class EventDraftInput
{
    /** What the user typed, such as "lunch with Sam Thursday 12:30 at Café Loos". */
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 1000)]
    public string $text = '';

    /** The user's IANA zone, for times in the text. */
    #[Assert\NotBlank]
    #[Assert\Timezone]
    public string $timeZone = '';
}
