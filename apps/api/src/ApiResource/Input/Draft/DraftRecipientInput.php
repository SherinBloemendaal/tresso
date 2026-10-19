<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Draft;

use Symfony\Component\Validator\Constraints as Assert;

/** A recipient as written so far: a draft may hold an address that is not finished yet; sending checks it. */
final class DraftRecipientInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 320)]
    #[Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'Addresses cannot contain line breaks.')]
    public string $address = '';

    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'Names cannot contain line breaks.')]
    public ?string $name = null;

    /** @return array{address: string, name: ?string} */
    public function toArray(): array
    {
        $name = null === $this->name ? null : trim($this->name);

        return ['address' => trim($this->address), 'name' => '' === $name ? null : $name];
    }
}
