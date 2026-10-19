<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * "Between fromUid and toUid, only these UIDs exist in the folder." Every other stored UID in the range was deleted
 * or moved away on the server. Lets a worker detect removals without QRESYNC, in chunks of any folder size.
 */
final class PresenceRangeInput
{
    #[Assert\Positive]
    public int $fromUid = 1;

    #[Assert\Positive]
    public int $toUid = 1;

    /** @var list<int> */
    #[Assert\Count(max: 10000)]
    #[Assert\All([new Assert\Positive()])]
    public array $uids = [];

    #[Assert\Callback]
    public function validateRange(ExecutionContextInterface $context): void
    {
        if ($this->toUid < $this->fromUid) {
            $context->buildViolation('toUid must not be lower than fromUid.')->atPath('toUid')->addViolation();
        }
        foreach ($this->uids as $uid) {
            if ($uid < $this->fromUid || $uid > $this->toUid) {
                $context->buildViolation('Every uid must lie within the range.')->atPath('uids')->addViolation();

                return;
            }
        }
    }
}
