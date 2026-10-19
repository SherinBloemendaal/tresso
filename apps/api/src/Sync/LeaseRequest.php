<?php

declare(strict_types=1);

namespace App\Sync;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LeaseRequest
{
    public function __construct(
        /** Stable id of the calling worker process; leases are owned per worker. */
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        #[Assert\Regex('/^[A-Za-z0-9._:-]+$/')]
        public string $workerId,
        /** Maximum number of accounts this worker wants to hold, including the ones it already holds. */
        #[Assert\Range(min: 1, max: 1000)]
        public int $capacity = 100,
    ) {
    }
}
