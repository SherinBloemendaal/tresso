<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * How far a user's mail is in the search index: every change up to this change number is indexed. Kept apart from
 * User so indexing never waits on the user row that ChangeSequencer locks while mail is written.
 *
 * Only {@see \App\Search\SearchIndexer} writes it, in SQL.
 */
#[ORM\Entity]
class SearchCursor
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $indexedSeq = 0;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getIndexedSeq(): int
    {
        return $this->indexedSeq;
    }
}
