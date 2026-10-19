<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The complete folder list of an account. Folders that are not in the list were deleted on the server.
 */
final class FolderListInput extends WorkerRequest
{
    /** @var list<FolderInput> */
    #[Assert\Count(max: 2000)]
    #[Assert\Valid]
    public array $folders = [];
}
