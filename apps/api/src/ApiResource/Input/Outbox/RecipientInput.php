<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Outbox;

use Symfony\Component\Validator\Constraints as Assert;

final class RecipientInput
{
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 320)]
    public string $address = '';

    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'Names cannot contain line breaks.')]
    public ?string $name = null;

    /** @return array{address: string, name: ?string} */
    public function toArray(): array
    {
        $name = null === $this->name ? null : trim($this->name);

        return ['address' => mb_strtolower(trim($this->address)), 'name' => '' === $name ? null : $name];
    }
}
