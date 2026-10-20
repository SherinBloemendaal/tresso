<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A message a bulk action moved, from the one folder it was in: undoing puts it back from where it went into that
 * folder, as an undo of a single action does. Messages that came from several folders are not kept, since a move
 * takes every copy and cannot put them back exactly.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['bulk_action_id', 'id'])]
class BulkActionMove
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BulkAction $bulkAction;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    /** Where the action took it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Folder $movedTo;

    /** Where it was. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Folder $movedFrom;

    public function __construct(BulkAction $bulkAction, Message $message, Folder $movedTo, Folder $movedFrom)
    {
        $this->id = Uuid::v7();
        $this->bulkAction = $bulkAction;
        $this->message = $message;
        $this->movedTo = $movedTo;
        $this->movedFrom = $movedFrom;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getMovedTo(): Folder
    {
        return $this->movedTo;
    }

    public function getMovedFrom(): Folder
    {
        return $this->movedFrom;
    }
}
