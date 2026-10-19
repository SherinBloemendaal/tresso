<?php

declare(strict_types=1);

namespace App\Classification;

use App\Enum\BuiltinCategory;
use App\Enum\FolderRole;

/**
 * Deterministic classification, in this order:
 *
 * 1. Spam that no From-based rule may overrule, because the From address can be forged: failed authentication, and
 *    the server's spam verdict on mail whose sender is not authenticated (no DMARC pass).
 * 2. A sender rule: a correction the user made earlier for this sender or domain.
 * 3. Include lists: the user's own smart inboxes first, then the built-in ones.
 * 4. Signals in the message, each pointing at a built-in smart inbox: the server's spam verdict (spam), automated
 *    senders (notification), marketing senders unless it is a reply, and bulk headers (advertise), addresses that take
 *    no replies (notification), mailing lists (informational) and unsubscribe links (advertise).
 * 5. Mail a person wrote: to the user directly (To, Bcc or an unknown alias) is something to do (todo), a copy (Cc
 *    only) is informational.
 *
 * A smart inbox whose exclude list covers the sender is skipped at every step, so the exclude list always wins. The
 * verdict is the smart inbox's, unless a sender rule says otherwise.
 */
final readonly class RuleBasedClassifier implements Classifier
{
    private const string NOTIFICATION_LOCAL_PART = '/^(no[-_.]?reply|do[-_.]?not[-_.]?reply|notifications?|notify|alerts?|mailer-daemon|postmaster|bounces?|automated)([+._-].*)?$/';
    private const string MARKETING_LOCAL_PART = '/^(newsletters?|marketing|promos?|promotions?|offers|deals|campaigns?|mailing|nieuwsbrief)([+._-].*)?$/';
    private const array NOTIFICATION_SUBDOMAINS = ['notifications', 'notification', 'notify', 'alerts', 'alert'];
    private const array MARKETING_SUBDOMAINS = ['news', 'newsletter', 'newsletters', 'marketing', 'promo', 'promotions', 'mkt', 'em', 'email', 'e'];

    public function classify(MailToClassify $mail, ClassificationContext $context): Classification
    {
        $sender = mb_strtolower($mail->fromAddress);

        // The From address is easy to forge. Mail that failed authentication, or that the server judged spam without
        // its sender being authenticated, is spam whatever rules exist for that address.
        $spam = $context->builtin(BuiltinCategory::Spam);
        $spamReason = self::spamReason($mail);
        // The exclude list is From-based too, so it cannot rescue mail that failed authentication.
        if (null !== $spam && ClassificationReason::AUTHENTICATION_FAILED === $spamReason) {
            return new Classification($spam, $spam->getVerdict(), $spamReason);
        }
        if (null !== $spam && null !== $spamReason && !$spam->excludes($sender) && !self::authenticated($mail)) {
            return new Classification($spam, $spam->getVerdict(), $spamReason);
        }

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

        foreach ($this->candidates($mail, $context, $spamReason) as [$builtin, $reason]) {
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
    private function candidates(MailToClassify $mail, ClassificationContext $context, ?string $spamReason): array
    {
        $candidates = [];
        if (null !== $spamReason) {
            $candidates[] = [BuiltinCategory::Spam, $spamReason];
        }
        if (self::isAutomated($mail)) {
            $candidates[] = [BuiltinCategory::Notification, ClassificationReason::AUTOMATED];
        }
        if (!$mail->isReply && self::isMarketingSender($mail->fromAddress)) {
            $candidates[] = [BuiltinCategory::Advertise, ClassificationReason::MARKETING_SENDER];
        }
        if (self::isBulk($mail)) {
            $candidates[] = [BuiltinCategory::Advertise, ClassificationReason::BULK];
        }
        if (self::isNotificationSender($mail->fromAddress)) {
            $candidates[] = [BuiltinCategory::Notification, ClassificationReason::NOTIFICATION_SENDER];
        }
        // Discussion lists (Mailman, Google Groups, Sympa) carry an unsubscribe link too; their List-Id says what they are.
        if (null !== $mail->header('list-id') || 'list' === self::precedence($mail)) {
            $candidates[] = [BuiltinCategory::Informational, ClassificationReason::MAILING_LIST];
        }
        if (null !== $mail->header('list-unsubscribe')) {
            $candidates[] = [BuiltinCategory::Advertise, ClassificationReason::UNSUBSCRIBE_LINK];
        }

        $reason = self::personReason($mail, $context);
        if (ClassificationReason::DIRECT === $reason) {
            $candidates[] = [BuiltinCategory::Todo, $reason];
        }
        $candidates[] = [BuiltinCategory::Informational, $reason];

        return $candidates;
    }

    /**
     * Mail from a person is for the user directly unless they are only in Cc. Not being in To or Cc at all means Bcc or
     * an address of the user that Tresso does not know (an alias): addressed to them all the same.
     */
    private static function personReason(MailToClassify $mail, ClassificationContext $context): string
    {
        foreach ($mail->to as $address) {
            if ($context->isOwnAddress($address)) {
                return ClassificationReason::DIRECT;
            }
        }
        foreach ($mail->cc as $address) {
            if ($context->isOwnAddress($address)) {
                return ClassificationReason::COPIED;
            }
        }

        return ClassificationReason::DIRECT;
    }

    private static function spamReason(MailToClassify $mail): ?string
    {
        if (self::failedAuthentication($mail)) {
            return ClassificationReason::AUTHENTICATION_FAILED;
        }

        return self::isServerSpam($mail) ? ClassificationReason::SERVER_SPAM : null;
    }

    /**
     * DMARC passed according to the receiving server: the From domain is the one that sent it, so rules about that
     * sender can be trusted. A header the receiving server did not write proves nothing (see {@see ReceivingServers}).
     */
    private static function authenticated(MailToClassify $mail): bool
    {
        $results = $mail->header('authentication-results') ?? '';

        return $mail->authenticationResultsTrusted && 1 === preg_match('/\bdmarc=pass\b/', strtolower($results));
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
    public static function failedAuthentication(MailToClassify $mail): bool
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
