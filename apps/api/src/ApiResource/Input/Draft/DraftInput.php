<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Draft;

use App\Enum\DraftKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The whole draft. For an update, `expectedVersion` is the version it was based on; when the draft changed on another
 * device since, the update is refused (409) so it can be merged instead of lost.
 */
final class DraftInput
{
    /** Chosen by the client, so a draft has its id before it ever reached the server. Only read on create. */
    #[Assert\Uuid]
    public ?string $draftId = null;

    #[Assert\Positive]
    public ?int $expectedVersion = null;

    #[Assert\Uuid]
    public ?string $mailAccountId = null;

    public DraftKind $kind = DraftKind::New;

    /** @var list<DraftRecipientInput> */
    #[Assert\Valid]
    #[Assert\Count(max: 100)]
    public array $to = [];

    /** @var list<DraftRecipientInput> */
    #[Assert\Valid]
    #[Assert\Count(max: 100)]
    public array $cc = [];

    /** @var list<DraftRecipientInput> */
    #[Assert\Valid]
    #[Assert\Count(max: 100)]
    public array $bcc = [];

    #[Assert\Length(max: 998)]
    #[Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'The subject cannot contain line breaks.')]
    public string $subject = '';

    #[Assert\Length(max: 2_000_000)]
    public string $html = '';

    #[Assert\Uuid]
    public ?string $signatureId = null;

    #[Assert\Length(max: 2_000_000)]
    public string $quoteHtml = '';

    #[Assert\Uuid]
    public ?string $inReplyToMessageId = null;
}
