<?php

declare(strict_types=1);

namespace App\Entity;

use App\Sync\Change\SyncedOnlyWhenSeen;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The notification about one new message that needs the user (judged `do` or `delegate`, unread, in an inbox). At most
 * one per message, ever: the row is what makes the trigger idempotent. It waits for its fire time (now, or the end of a
 * focus block or meeting), is then delivered or dropped (the message no longer needs the user, or no channel is
 * chosen), and reaches clients through the delta once delivered, which is how the desktop app shows it.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['delivered_at', 'fire_at'])]
#[ORM\Index(columns: ['user_id', 'change_seq'])]
class MailNotification implements SyncedOnlyWhenSeen
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Null once the message is gone; the row stays, so the message never notifies again. */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(unique: true, onDelete: 'SET NULL')]
    private ?Message $message;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Thread $thread;

    #[ORM\Column(length: 255)]
    private string $title;

    /** The text from before encryption; new rows keep it sealed in $sealedBody, see {@see \App\Mail\MessageFields}. */
    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    /**
     * The subject and the start of the text, sealed with the user's field key. Cleared with the title once clients no
     * longer get the notification ({@see \App\Notification\ScrubMailNotifications}).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sealedBody = null;

    #[ORM\Column]
    private \DateTimeImmutable $fireAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    /** @var list<string> the channels it was delivered on */
    #[ORM\Column(type: Types::JSON)]
    private array $channels = [];

    /** Delivered without sound or banner (quiet hours, another busy event). */
    #[ORM\Column]
    private bool $silent = false;

    /** Not delivered, and never will be: the message no longer needed the user, or no channel was chosen. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $droppedAt = null;

    /** Stands for several messages that a batch delivered at once (see {@see summarize()}). */
    #[ORM\Column(options: ['default' => false])]
    private bool $summary = false;

    /** The summary this notification was folded into: it is not sent itself, but counts towards that one. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MailNotification $summarizedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Without a body: {@see \App\Mail\MessageFields::sealNotificationBody()} seals it once the row has its id.
     */
    public function __construct(User $user, Message $message, string $title, \DateTimeImmutable $fireAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->message = $message;
        $this->thread = $message->getThread();
        $this->title = mb_substr($title, 0, 255);
        $this->fireAt = self::utc($fireAt);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function getThread(): ?Thread
    {
        return $this->thread;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** The body from before encryption; empty once it is sealed. */
    public function getLegacyBody(): string
    {
        return $this->body;
    }

    public function getSealedBody(): ?string
    {
        return $this->sealedBody;
    }

    /** @param ?string $sealed null for no body at all */
    public function sealBody(?string $sealed): void
    {
        $this->sealedBody = $sealed;
        $this->body = '';
    }

    public function getFireAt(): \DateTimeImmutable
    {
        return $this->fireAt;
    }

    /**
     * The user turned previews off: what the notification says becomes only which account the mail came to, or for a
     * summary only how many, so no sender, subject or text leaves the server (nor stays in this row).
     */
    public function hideContent(string $title): void
    {
        $this->body = '';
        $this->sealedBody = null;
        if (!$this->summary) {
            $this->title = mb_substr($title, 0, 255);
        }
    }

    /** Whether it stands for several messages (see {@see summarize()}); its title says only how many. */
    public function isSummary(): bool
    {
        return $this->summary;
    }

    /** Counts towards a summary instead of being sent itself (see {@see summarize()}). */
    public function foldInto(MailNotification $summary, \DateTimeImmutable $at): void
    {
        $this->summarizedBy = $summary;
        $this->drop($at);
    }

    /**
     * Stands for several messages at once (a batch that delivered many): it says how many, and opens the batches
     * rather than a single conversation. Its body (who sent them) is sealed separately.
     */
    public function summarize(string $title): void
    {
        $this->summary = true;
        $this->title = mb_substr($title, 0, 255);
        $this->thread = null;
    }

    /**
     * A summary that only one message still needs the user for: it says what that one says and opens its
     * conversation. It stays a summary, so a later delivery (after a focus block) counts again. Its body is sealed
     * separately.
     */
    public function narrowTo(Thread $thread, string $title): void
    {
        $this->title = mb_substr($title, 0, 255);
        $this->thread = $thread;
    }

    /** A focus block or a meeting: it waits for their end. */
    public function postpone(\DateTimeImmutable $until): void
    {
        $this->fireAt = self::utc($until);
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function isDelivered(): bool
    {
        return null !== $this->deliveredAt;
    }

    /**
     * @param list<string> $channels
     */
    public function markDelivered(\DateTimeImmutable $at, array $channels, bool $silent): void
    {
        $this->deliveredAt = self::utc($at);
        $this->channels = $channels;
        $this->silent = $silent;
    }

    /** @return list<string> */
    public function getChannels(): array
    {
        return $this->channels;
    }

    public function isSilent(): bool
    {
        return $this->silent;
    }

    public function getDroppedAt(): ?\DateTimeImmutable
    {
        return $this->droppedAt;
    }

    public function drop(\DateTimeImmutable $at): void
    {
        $this->droppedAt = self::utc($at);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Clients see it once delivered; waiting and dropped ones are no business of theirs. */
    public function isSeenByClients(): bool
    {
        return null !== $this->deliveredAt;
    }

    public function getChangeOwner(): User
    {
        return $this->user;
    }

    public static function clientFields(): ?array
    {
        return ['deliveredAt', 'channels', 'silent', 'summary'];
    }

    public static function changeType(): string
    {
        return 'mailNotification';
    }

    public function getChangeSeq(): int
    {
        return $this->changeSeq;
    }

    public function setChangeSeq(int $changeSeq): void
    {
        $this->changeSeq = $changeSeq;
    }

    /** Date-time columns keep the wall clock only, so every moment is stored in UTC. */
    private static function utc(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }
}
