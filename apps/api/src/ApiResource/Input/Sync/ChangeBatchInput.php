<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Flag changes and removed messages (expunged or moved away) in one folder. Safe to resend.
 */
final class ChangeBatchInput extends WorkerRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 512)]
    public string $folder = '';

    #[Assert\Length(max: 2048)]
    public ?string $cursor = null;

    /** @var list<FlagChangeInput> */
    #[Assert\Count(max: 5000)]
    #[Assert\Valid]
    public array $flags = [];

    /** @var list<PresenceRangeInput> IMAP only: authoritative UID lists for ranges of the folder */
    #[Assert\Count(max: 50)]
    #[Assert\Valid]
    public array $presence = [];

    /** @var list<string> remote uids no longer in the folder */
    #[Assert\Count(max: 5000)]
    #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 255)])]
    public array $vanished = [];
}
