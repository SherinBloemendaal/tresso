<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A plaintext object from before encryption that re-encryption moved away from, kept so the plaintext can be deleted
 * later without listing the whole bucket ({@see \App\Storage\Reencryption::purgeLegacyObjects()}). Such objects may be
 * shared between users, so they are deleted only once no row anywhere still refers to content from before.
 */
#[ORM\Entity]
class LegacyObject
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private string $objectKey;

    public function __construct(string $objectKey)
    {
        $this->objectKey = $objectKey;
    }

    public function getObjectKey(): string
    {
        return $this->objectKey;
    }
}
