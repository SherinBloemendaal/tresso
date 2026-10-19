<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

final class AddressInput
{
    /** Not validated as an email address: real-world headers contain addresses no validator accepts. */
    #[Assert\NotBlank]
    #[Assert\Length(max: 320)]
    public string $address = '';

    #[Assert\Length(max: 255)]
    public ?string $name = null;
}
