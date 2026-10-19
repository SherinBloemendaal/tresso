<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Notification\ReminderRuleInput;
use App\Entity\ReminderRule;
use App\Enum\RuleRole;
use App\Security\Scope;
use App\State\ReminderRuleProcessor;
use App\State\ReminderRuleProvider;

/**
 * Which reminders events without their own get: by calendar and kind of event; the most specific match wins. Also
 * switches leave-now, the meeting brief and the follow-up for the events it matches.
 *
 * @phpstan-import-type RuleReminder from ReminderRule
 */
#[ApiResource(
    shortName: 'ReminderRule',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(uriTemplate: '/reminder-rules', paginationEnabled: false, security: "is_granted('".Scope::ROLE_SETTINGS_READ."')", provider: ReminderRuleProvider::class),
        new Get(uriTemplate: '/reminder-rules/{id}', security: "is_granted('".Scope::ROLE_SETTINGS_READ."')", provider: ReminderRuleProvider::class),
        new Post(uriTemplate: '/reminder-rules', security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')", input: ReminderRuleInput::class, read: false, processor: ReminderRuleProcessor::class),
        new Put(uriTemplate: '/reminder-rules/{id}', security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')", input: ReminderRuleInput::class, provider: ReminderRuleProvider::class, processor: ReminderRuleProcessor::class, allowCreate: false),
        new Delete(uriTemplate: '/reminder-rules/{id}', security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')", provider: ReminderRuleProvider::class, processor: ReminderRuleProcessor::class),
    ],
)]
final readonly class ReminderRuleResource
{
    /**
     * @param list<RuleReminder> $reminders
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $name,
        public ?string $calendarId,
        public ?bool $allDay,
        public RuleRole $role,
        public ?bool $withAttendees,
        public ?bool $withConference,
        public ?bool $withLocation,
        public array $reminders,
        public bool $leaveNow,
        public ?int $briefMinutes,
        public bool $followUp,
        public int $position,
    ) {
    }

    public static function fromEntity(ReminderRule $rule): self
    {
        return new self(
            $rule->getId()->toRfc4122(),
            $rule->getName(),
            $rule->getCalendar()?->getId()->toRfc4122(),
            $rule->getAllDay(),
            $rule->getRole(),
            $rule->getWithAttendees(),
            $rule->getWithConference(),
            $rule->getWithLocation(),
            $rule->getReminders(),
            $rule->isLeaveNow(),
            $rule->getBriefMinutes(),
            $rule->isFollowUp(),
            $rule->getPosition(),
        );
    }
}
