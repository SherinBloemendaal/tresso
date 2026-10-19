<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

final class FlagChangeInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $remoteUid = '';

    #[Assert\PositiveOrZero]
    public ?int $modSeq = null;

    #[Assert\Valid]
    public FlagsInput $flags;

    public function __construct()
    {
        $this->flags = new FlagsInput();
    }
}
