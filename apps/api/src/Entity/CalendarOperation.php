<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CalendarOperationKind;
use App\Repository\CalendarOperationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A change to a provider calendar that the worker leasing the account still has to make: write a resource (create
 * or update) or delete one. Like outgoing mail it is claimed for a while and reported back; while unclaimed, later
 * changes to the same resource reuse it, because the worker always writes the resource's latest version.
 */
#[ORM\Entity(repositoryClass: CalendarOperationRepository::class)]
#[ORM\Index(columns: ['mail_account_id', 'claimed_until'])]
class CalendarOperation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MailAccount $mailAccount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Calendar $calendar;

    /** The resource to write; null for a delete, or when it was removed before its write went out. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CalendarObject $object;

    #[ORM\Column(length: 8, enumType: CalendarOperationKind::class)]
    private CalendarOperationKind $kind;

    #[ORM\Column(length: 1024)]
    private string $uid;

    /** The provider's id of the resource when it has one (needed to delete or update it). */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $remoteId;

    /** The version the change is based on; the provider refuses the write when its version differs. */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $ifMatch;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $claimedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedUntil = null;

    /** Not before this time (after a temporary failure). */
    #[ORM\Column]
    private \DateTimeImmutable $notBefore;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastError = null;

    /** A provider that schedules tells attendees (or the organizer) about the change; false when the user said not to. */
    #[ORM\Column(options: ['default' => true])]
    private bool $notify = true;

    /** The user's comment with their answer to an invitation, for providers that send the reply themselves. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(Calendar $calendar, ?CalendarObject $object, CalendarOperationKind $kind, string $uid, ?string $remoteId, ?string $ifMatch, \DateTimeImmutable $now, bool $notify, ?string $comment)
    {
        $account = $calendar->getMailAccount() ?? throw new \LogicException('Local calendars are never written to a provider.');
        $this->id = Uuid::v7();
        $this->mailAccount = $account;
        $this->calendar = $calendar;
        $this->object = $object;
        $this->kind = $kind;
        $this->uid = $uid;
        $this->remoteId = $remoteId;
        $this->ifMatch = $ifMatch;
        $this->notBefore = $now;
        $this->createdAt = $now;
        $this->notify = $notify;
        $this->comment = $comment;
    }

    public static function put(CalendarObject $object, \DateTimeImmutable $now, bool $notify = true, ?string $comment = null): self
    {
        return new self($object->getCalendar(), $object, CalendarOperationKind::Put, $object->getUid(), $object->getRemoteId(), $object->getEtag(), $now, $notify, $comment);
    }

    public static function delete(Calendar $calendar, string $uid, string $remoteId, ?string $etag, \DateTimeImmutable $now, bool $notify = true): self
    {
        return new self($calendar, null, CalendarOperationKind::Delete, $uid, $remoteId, $etag, $now, $notify, null);
    }

    /**
     * A later change folded into this pending write: attendees hear about it when any of the changes says so, and
     * the latest comment goes with the answer.
     */
    public function schedule(bool $notify, ?string $comment): void
    {
        $this->notify = $this->notify || $notify;
        $this->comment = $comment ?? $this->comment;
    }

    public function notifies(): bool
    {
        return $this->notify;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function getCalendar(): Calendar
    {
        return $this->calendar;
    }

    public function getObject(): ?CalendarObject
    {
        return $this->object;
    }

    public function getKind(): CalendarOperationKind
    {
        return $this->kind;
    }

    public function getUid(): string
    {
        return $this->uid;
    }

    public function getRemoteId(): ?string
    {
        return $this->remoteId;
    }

    public function getIfMatch(): ?string
    {
        return $this->ifMatch;
    }

    /** A pending write that is now a delete, because the resource went away before the write was made. */
    public function becomeDelete(string $remoteId, ?string $etag): void
    {
        $this->kind = CalendarOperationKind::Delete;
        $this->object = null;
        $this->remoteId = $remoteId;
        $this->ifMatch = $etag;
    }

    public function isClaimed(\DateTimeImmutable $now): bool
    {
        return null !== $this->claimedUntil && $this->claimedUntil > $now;
    }

    public function getClaimedBy(): ?string
    {
        return $this->claimedBy;
    }

    public function claim(string $workerId, \DateTimeImmutable $until): void
    {
        $this->claimedBy = $workerId;
        $this->claimedUntil = $until;
        ++$this->attempts;
        // The worker writes the version the resource has now.
        if (null !== $this->object) {
            $this->remoteId = $this->object->getRemoteId();
            $this->ifMatch = $this->object->getEtag();
        }
    }

    public function retryAt(\DateTimeImmutable $notBefore, string $error): void
    {
        $this->claimedBy = null;
        $this->claimedUntil = null;
        $this->notBefore = $notBefore;
        $this->lastError = mb_substr($error, 0, 500);
    }

    public function getNotBefore(): \DateTimeImmutable
    {
        return $this->notBefore;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
