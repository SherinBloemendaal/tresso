<?php

declare(strict_types=1);

namespace App\ApiResource\Input\SmartInbox;

use App\Classification\SenderPattern;
use App\Entity\Category;
use App\Enum\Verdict;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partial update (JSON merge patch). Omitted or null fields stay as they are; the lists are replaced as a whole.
 */
final class UpdateSmartInbox
{
    #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
    #[Assert\Length(max: Category::NAME_MAX_LENGTH)]
    public ?string $name = null;

    #[Assert\Length(max: Category::INSTRUCTIONS_MAX_LENGTH)]
    public ?string $instructions = null;

    /** @var list<string>|null */
    #[Assert\Count(max: Category::SENDERS_MAX_COUNT)]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex(SenderPattern::REGEX, message: 'Use an address or @domain.', normalizer: 'trim')])]
    public ?array $includeSenders = null;

    /** @var list<string>|null */
    #[Assert\Count(max: Category::SENDERS_MAX_COUNT)]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex(SenderPattern::REGEX, message: 'Use an address or @domain.', normalizer: 'trim')])]
    public ?array $excludeSenders = null;

    #[Assert\Choice(callback: [Verdict::class, 'values'])]
    public ?string $verdict = null;
}
