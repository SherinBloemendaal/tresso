<?php

declare(strict_types=1);

namespace App\Classification;

use App\Enum\BuiltinCategory;
use App\Enum\FolderRole;

/**
 * Deterministic classification, in this order:
 *
 * 1. A sender rule: a correction the user made earlier for this sender or domain.
 * 2. Include lists: the user's own smart inboxes first, then the built-in ones.
 * 3. Signals in the message, each pointing at a built-in smart inbox: spam verdicts of the server and failed
 *    authentication (spam), automated senders (notification), marketing senders and bulk headers (advertise),
 *    addresses that take no replies (notification), unsubscribe links (advertise) and mailing lists (informational).
 * 4. Mail a person wrote: to the user directly is something to do (todo), a copy is informational.
 *
 * A smart inbox whose exclude list covers the sender is skipped at every step, so the exclude list always wins. The
 * verdict is the smart inbox's, unless a sender rule says otherwise.
 */
final readonly class RuleBasedClassifier implements Classifier
{
    private const string NOTIFICATION_LOCAL_PART = '/^(no[-_.]?reply|do[-_.]?not[-_.]?reply|notifications?|notify|alerts?|mailer-daemon|postmaster|bounces?|automated)([+._-].*)?$/';
    private const string MARKETING_LOCAL_PART = '/^(newsletters?|news|marketing|promos?|promotions?|offers|deals|sales|campaigns?|mailing|nieuwsbrief)([+._-].*)?$/';
    private const array NOTIFICATION_SUBDOMAINS = ['notifications', 'notification', 'notify', 'alerts', 'alert'];
    private const array MARKETING_SUBDOMAINS = ['news', 'newsletter', 'newsletters', 'marketing', 'promo', 'promotions', 'mkt', 'em', 'email', 'e'];

    public function classify(MailToClassify $mail, ClassificationContext $context): Classification
    {
        $sender = mb_strtolower($mail->fromAddress);

        $rule = $context->senderRule($sender);
        if (null !== $rule && !$rule->getCategory()->excludes($sender)) {
            $smartInbox = $rule->getCategory();

            return new Classification($smartInbox, $rule->getVerdict() ?? $smartInbox->getVerdict(), ClassificationReason::SENDER_RULE);
        }

        foreach ($context->includeOrder() as $smartInbox) {
            if ($smartInbox->includes($sender) && !$smartInbox->excludes($sender)) {
                return new Classification($smartInbox, $smartInbox->getVerdict(), ClassificationReason::INCLUDE_LIST);
            }
        }

        foreach ($this->candidates($mail, $context) as [$builtin, $reason]) {
            $smartInbox = $context->builtin($builtin);
            if (null !== $smartInbox && !$smartInbox->excludes($sender)) {
                return new Classification($smartInbox, $smartInbox->getVerdict(), $reason);
            }
        }

        // Every built-in smart inbox that fits excludes this sender: the first one that does not.
        foreach ($context->includeOrder() as $smartInbox) {
            if (!$smartInbox->excludes($sender)) {
                return new Classification($smartInbox, $smartInbox->getVerdict(), self::personReason($mail, $context));
            }
        }

        $smartInbox = $context->smartInboxes[0] ?? throw new \LogicException('The user has no smart inboxes.');

        return new Classification($smartInbox, $smartInbox->getVerdict(), self::personReason($mail, $context));
    }

    /**
     * Built-in smart inboxes that fit the message, best first, each with the reason.
     *
     * @return list<array{BuiltinCategory, string}>
     */
    private function candidates(MailToClassify $mail, ClassificationContext $context): array
    {
        $candidates = [];
        if (self::isServerSpam($mail)) {
            $candidates[] = [BuiltinCategory::Spam, ClassificationReason::SERVER_SPAM];
        }
        if (self::failedAuthentication($mail)) {
            $candidates[] = [BuiltinCategory::Spam, ClassificationReason::AUTHENTICATION_FAILED];
        }
        if (self::isAutomated($mail)) {
            $candidates[] = [BuiltinCategory::Notification, ClassificationReason::AUTOMATED];
        }
        if (self::isMarketingSender($mail->fromAddress)) {
            $candidates[] = [BuiltinCategory::Advertise, ClassificationReason::MARKETING_SENDER];
        }
        if (self::isBulk($mail)) {
            $candidates[] = [BuiltinCategory::Advertise, ClassificationReason::BULK];
        }
        if (self::isNotificationSender($mail->fromAddress)) {
            $candidates[] = [BuiltinCategory::Notification, ClassificationReason::NOTIFICATION_SENDER];
        }
        if (null !== $mail->header('list-unsubscribe')) {
            $candidates[] = [BuiltinCategory::Advertise, ClassificationReason::UNSUBSCRIBE_LINK];
        }
        if (null !== $mail->header('list-id') || 'list' === self::precedence($mail)) {
            $candidates[] = [BuiltinCategory::Informational, ClassificationReason::MAILING_LIST];
        }

        $reason = self::personReason($mail, $context);
        if (ClassificationReason::DIRECT === $reason) {
            $candidates[] = [BuiltinCategory::Todo, $reason];
        }
        $candidates[] = [BuiltinCategory::Informational, $reason];

        return $candidates;
    }

    private static function personReason(MailToClassify $mail, ClassificationContext $context): string
    {
        foreach ($mail->to as $address) {
            if ($context->isOwnAddress($address)) {
                return ClassificationReason::DIRECT;
            }
        }

        return ClassificationReason::COPIED;
    }

    private static function isServerSpam(MailToClassify $mail): bool
    {
        if ($mail->junk || \in_array(FolderRole::Spam, $mail->folderRoles, true)) {
            return true;
        }

        return 1 === preg_match('/^yes\b/i', $mail->header('x-spam-flag') ?? '')
            || 1 === preg_match('/^yes\b/i', $mail->header('x-spam-status') ?? '');
    }

    /**
     * DMARC failed and the sender's domain asks receivers to reject or quarantine such mail. Failing DMARC alone is
     * not enough: forwarded mail and mailing lists fail it too.
     */
    private static function failedAuthentication(MailToClassify $mail): bool
    {
        $results = strtolower($mail->header('authentication-results') ?? '');

        return 1 === preg_match('/\bdmarc=fail\b/', $results)
            && 1 === preg_match('/\b(p|policy\.dmarc|policy\.published-domain-policy)=(reject|quarantine)\b/', $results);
    }

    private static function isAutomated(MailToClassify $mail): bool
    {
        $autoSubmitted = strtolower($mail->header('auto-submitted') ?? 'no');

        return ('no' !== $autoSubmitted && '' !== $autoSubmitted) || 'auto_reply' === self::precedence($mail);
    }

    private static function isBulk(MailToClassify $mail): bool
    {
        return \in_array(self::precedence($mail), ['bulk', 'junk'], true)
            || (null !== $mail->header('list-unsubscribe') && null !== $mail->header('feedback-id'));
    }

    private static function isMarketingSender(string $address): bool
    {
        return 1 === preg_match(self::MARKETING_LOCAL_PART, SenderPattern::localPartOf($address))
            || self::hasSubdomain($address, self::MARKETING_SUBDOMAINS);
    }

    private static function isNotificationSender(string $address): bool
    {
        return 1 === preg_match(self::NOTIFICATION_LOCAL_PART, SenderPattern::localPartOf($address))
            || self::hasSubdomain($address, self::NOTIFICATION_SUBDOMAINS);
    }

    /**
     * Whether the sender's domain starts with one of these labels and has at least two more (`news.shop.example`
     * yes, `news.example` no: that is the whole domain).
     *
     * @param list<string> $labels
     */
    private static function hasSubdomain(string $address, array $labels): bool
    {
        $parts = explode('.', SenderPattern::domainOf($address));

        return \count($parts) >= 3 && \in_array($parts[0], $labels, true);
    }

    private static function precedence(MailToClassify $mail): string
    {
        return strtolower($mail->header('precedence') ?? '');
    }
}
