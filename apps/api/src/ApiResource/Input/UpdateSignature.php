<?php

declare(strict_types=1);

namespace App\ApiResource\Input;

use App\Mail\Body\SignatureHtmlSanitizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partial update (JSON merge patch). Omitted or null fields stay as they are.
 */
final class UpdateSignature
{
    #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\Length(max: SignatureHtmlSanitizer::MAX_INPUT_BYTES, countUnit: Assert\Length::COUNT_BYTES)]
    public ?string $html = null;

    /**
     * The complete list of mail accounts that insert this signature by default. Accounts left out that used it get no
     * default signature; accounts listed replace the default they had.
     *
     * @var list<string>|null
     */
    #[Assert\Unique]
    #[Assert\All([new Assert\Uuid()])]
    public ?array $defaultFor = null;
}
