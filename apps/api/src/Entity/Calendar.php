<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CalendarSource;
use App\Repository\CalendarRepository;
use App\Sync\Change\TracksChanges;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A calendar of a user: at the provider of one of their mail accounts, or the local Tresso calendar that belongs to
 * no account. The user's color and visibility are their own and never go back to the provider.
 */
#[ORM\Entity(repositoryClass: CalendarRepository::class)]
#[ORM\UniqueConstraint(columns: ['mail_account_id', 'remote_id'])]
#[ORM\Index(columns: ['owner_id', 'change_seq'])]
class Calendar implements TracksChanges
{
    public const string DEFAULT_COLOR = '#3a6df0';

    /** Online meetings a provider can make for an event: Google Meet, Microsoft Teams. */
    public const array CONFERENCE_PROVIDERS = ['meet', 'teams'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** Null for the local Tresso calendar. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?MailAccount $mailAccount;

    #[ORM\Column(length: 16, enumType: CalendarSource::class)]
    private CalendarSource $source;

    /** CalDAV collection URL, Google calendar id or Graph calendar id. */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $remoteId;

    #[ORM\Column(length: 255)]
    private string $name;

    /** The provider's color. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    /** The user's own color, which wins over the provider's. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $userColor = null;

    /** IANA time zone the provider gives the calendar. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $timeZone = null;

    #[ORM\Column]
    private bool $writable;

    /** Where new events and accepted invitations of the account go. */
    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column]
    private bool $visible = true;

    /** A work calendar: its notifications keep to the user's working hours. */
    #[ORM\Column(options: ['default' => false])]
    private bool $work = false;

    /** The provider sends invitations and replies itself (RFC 6638 auto-schedule, Google, Microsoft). */
    #[ORM\Column]
    private bool $providerSchedules = false;

    /**
     * Online meetings the provider makes for events of this calendar ("meet", "teams").
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $conferenceProviders = [];

    #[ORM\Column]
    private int $position = 0;

    /** Provider sync state (sync-token, ctag, delta link); opaque to the API and not a change for clients. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $syncCursor = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(User $owner, ?MailAccount $mailAccount, CalendarSource $source, ?string $remoteId, string $name, bool $writable)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->mailAccount = $mailAccount;
        $this->source = $source;
        $this->remoteId = $remoteId;
        $this->name = $name;
        $this->writable = $writable;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function local(User $owner, string $name): self
    {
        return new self($owner, null, CalendarSource::Local, null, $name, true);
    }

    public static function remote(MailAccount $account, CalendarSource $source, string $remoteId, string $name, bool $writable): self
    {
        if (CalendarSource::Local === $source) {
            throw new \InvalidArgumentException('A provider calendar needs a provider source.');
        }

        return new self($account->getOwner(), $account, $source, $remoteId, $name, $writable);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getMailAccount(): ?MailAccount
    {
        return $this->mailAccount;
    }

    public function getSource(): CalendarSource
    {
        return $this->source;
    }

    public function isLocal(): bool
    {
        return CalendarSource::Local === $this->source;
    }

    public function getRemoteId(): ?string
    {
        return $this->remoteId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): void
    {
        $this->color = null === $color ? null : strtolower($color);
    }

    public function getUserColor(): ?string
    {
        return $this->userColor;
    }

    public function setUserColor(?string $userColor): void
    {
        $this->userColor = null === $userColor ? null : strtolower($userColor);
    }

    public function getTimeZone(): ?string
    {
        return $this->timeZone;
    }

    public function setTimeZone(?string $timeZone): void
    {
        $this->timeZone = $timeZone;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function setWritable(bool $writable): void
    {
        $this->writable = $writable;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): void
    {
        $this->visible = $visible;
    }

    public function isWork(): bool
    {
        return $this->work;
    }

    public function setWork(bool $work): void
    {
        $this->work = $work;
    }

    public function providerSchedules(): bool
    {
        return $this->providerSchedules;
    }

    public function setProviderSchedules(bool $providerSchedules): void
    {
        $this->providerSchedules = $providerSchedules;
    }

    /** @return list<string> */
    public function getConferenceProviders(): array
    {
        return $this->conferenceProviders;
    }

    /**
     * @param list<string> $conferenceProviders
     */
    public function setConferenceProviders(array $conferenceProviders): void
    {
        $this->conferenceProviders = $conferenceProviders;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getSyncCursor(): ?string
    {
        return $this->syncCursor;
    }

    public function setSyncCursor(?string $syncCursor): void
    {
        $this->syncCursor = $syncCursor;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getChangeOwner(): User
    {
        return $this->owner;
    }

    public static function clientFields(): ?array
    {
        return ['name', 'color', 'userColor', 'timeZone', 'writable', 'isDefault', 'visible', 'work', 'providerSchedules', 'conferenceProviders', 'position'];
    }

    public static function changeType(): string
    {
        return 'calendar';
    }

    public function getChangeSeq(): int
    {
        return $this->changeSeq;
    }

    public function setChangeSeq(int $changeSeq): void
    {
        $this->changeSeq = $changeSeq;
    }
}
