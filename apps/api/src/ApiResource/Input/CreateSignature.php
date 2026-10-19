<?php

declare(strict_types=1);

namespace App\ApiResource\Input;

use App\Mail\Body\SignatureHtmlSanitizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A new signature. The HTML is sanitized before it is stored.
 */
final class CreateSignature
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public string $name = '';

    #[Assert\Length(max: SignatureHtmlSanitizer::MAX_INPUT_BYTES, countUnit: Assert\Length::COUNT_BYTES)]
    public string $html = '';

    /**
     * Mail accounts that insert this signature by default, replacing the default they had.
     *
     * @var list<string>
     */
    #[Assert\Unique]
    #[Assert\All([new Assert\Uuid()])]
    public array $defaultFor = [];
}
