<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use Symfony\Component\Validator\Constraints as Assert;

final class AttendeeInput
{
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 320)]
    public string $address = '';

    #[Assert\Length(max: 200)]
    #[Assert\Regex('/^[^\r\n]*$/', message: 'A name cannot contain line breaks.')]
    public ?string $name = null;

    #[Assert\Choice(choices: ['required', 'optional', 'chair', 'non-participant'])]
    public string $role = 'required';

    /**
     * @return array{address: string, name: ?string, role: string}
     */
    public function toArray(): array
    {
        $name = null === $this->name ? null : trim($this->name);

        return ['address' => mb_strtolower(trim($this->address)), 'name' => '' === $name ? null : $name, 'role' => $this->role];
    }
}
