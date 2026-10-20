<?php

declare(strict_types=1);

namespace App\Tests\Unit\Classification;

use App\Classification\ClassificationContext;
use App\Classification\ClassificationReason;
use App\Classification\MailToClassify;
use App\Classification\RuleBasedClassifier;
use App\Entity\Category;
use App\Entity\SenderRule;
use App\Entity\User;
use App\Enum\BuiltinCategory;
use App\Enum\FolderRole;
use App\Enum\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleBasedClassifierTest extends TestCase
{
    private const string ME = 'kim@company.example';

    private User $user;

    /** @var array<string, Category> */
    private array $builtin = [];

    protected function setUp(): void
    {
        $this->user = new User(self::ME, 'Kim');
        foreach (BuiltinCategory::cases() as $position => $builtin) {
            $category = new Category($this->user, ucfirst($builtin->value), '', $builtin->defaultVerdict(), $builtin);
            $category->setPosition($position);
            $this->builtin[$builtin->value] = $category;
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, BuiltinCategory, string}>
     */
    public static function signals(): iterable
    {
        yield 'a person writing to the user is something to do' => [[], BuiltinCategory::Todo, ClassificationReason::DIRECT];
        yield 'a person copying the user is informational' => [['to' => ['lisa@company.example'], 'cc' => [self::ME]], BuiltinCategory::Informational, ClassificationReason::COPIED];
        yield 'the junk folder' => [['folderRoles' => [FolderRole::Spam]], BuiltinCategory::Spam, ClassificationReason::SERVER_SPAM];
        yield 'the junk keyword' => [['junk' => true], BuiltinCategory::Spam, ClassificationReason::SERVER_SPAM];
        yield 'the spam filter of the server' => [['headers' => ['x-spam-flag' => 'YES']], BuiltinCategory::Spam, ClassificationReason::SERVER_SPAM];
        yield 'a spam status' => [['headers' => ['x-spam-status' => 'Yes, score=7.1 required=5.0']], BuiltinCategory::Spam, ClassificationReason::SERVER_SPAM];
        yield 'a negative spam status is no spam' => [['headers' => ['x-spam-status' => 'No, score=0.4']], BuiltinCategory::Todo, ClassificationReason::DIRECT];
        yield 'dmarc failing a reject policy' => [['authenticationResultsTrusted' => true, 'headers' => ['authentication-results' => 'mx.example; spf=fail; dkim=none; dmarc=fail (p=REJECT sp=REJECT dis=NONE) header.from=bank.example']], BuiltinCategory::Spam, ClassificationReason::AUTHENTICATION_FAILED];
        yield 'dmarc failing in a header the receiving server may not have written proves nothing' => [['headers' => ['authentication-results' => 'mx.example; dmarc=fail (p=REJECT) header.from=partner.example']], BuiltinCategory::Todo, ClassificationReason::DIRECT];
        yield 'dmarc failing without a policy is no spam' => [['authenticationResultsTrusted' => true, 'headers' => ['authentication-results' => 'mx.example; dmarc=fail (p=NONE) header.from=list.example']], BuiltinCategory::Todo, ClassificationReason::DIRECT];
        yield 'auto submitted' => [['headers' => ['auto-submitted' => 'auto-generated']], BuiltinCategory::Notification, ClassificationReason::AUTOMATED];
        yield 'auto submitted no is a person' => [['headers' => ['auto-submitted' => 'no']], BuiltinCategory::Todo, ClassificationReason::DIRECT];
        yield 'an out of office reply' => [['headers' => ['precedence' => 'auto_reply']], BuiltinCategory::Notification, ClassificationReason::AUTOMATED];
        yield 'a newsletter address' => [['from' => 'newsletter@shop.example'], BuiltinCategory::Advertise, ClassificationReason::MARKETING_SENDER];
        yield 'a marketing subdomain' => [['from' => 'hello@news.shop.example'], BuiltinCategory::Advertise, ClassificationReason::MARKETING_SENDER];
        yield 'bulk precedence' => [['headers' => ['precedence' => 'bulk']], BuiltinCategory::Advertise, ClassificationReason::BULK];
        yield 'an unsubscribe link with a feedback id' => [['from' => 'noreply@shop.example', 'headers' => ['list-unsubscribe' => '<https://shop.example/u>', 'feedback-id' => '1:2:shop']], BuiltinCategory::Advertise, ClassificationReason::BULK];
        yield 'a no reply address' => [['from' => 'no-reply@bank.example'], BuiltinCategory::Notification, ClassificationReason::NOTIFICATION_SENDER];
        yield 'a notifications address with a tag' => [['from' => 'notifications+abc@forge.example'], BuiltinCategory::Notification, ClassificationReason::NOTIFICATION_SENDER];
        yield 'a notification subdomain' => [['from' => 'team@alerts.monitor.example'], BuiltinCategory::Notification, ClassificationReason::NOTIFICATION_SENDER];
        yield 'a notification address with an unsubscribe link stays a notification' => [['from' => 'notifications@forge.example', 'headers' => ['list-unsubscribe' => '<mailto:u@forge.example>']], BuiltinCategory::Notification, ClassificationReason::NOTIFICATION_SENDER];
        yield 'only an unsubscribe link' => [['from' => 'anna@shop.example', 'headers' => ['list-unsubscribe' => '<https://shop.example/u>']], BuiltinCategory::Advertise, ClassificationReason::UNSUBSCRIBE_LINK];
        yield 'a mailing list, with the unsubscribe link real lists add' => [['to' => ['team@lists.example'], 'headers' => ['list-id' => '<team.lists.example>', 'list-unsubscribe' => '<mailto:team-leave@lists.example>']], BuiltinCategory::Informational, ClassificationReason::MAILING_LIST];
        yield 'a reply from a sales team is a person' => [['from' => 'sales@supplier.example', 'isReply' => true], BuiltinCategory::Todo, ClassificationReason::DIRECT];
        yield 'a reply from a marketing address is a person' => [['from' => 'newsletter@shop.example', 'isReply' => true], BuiltinCategory::Todo, ClassificationReason::DIRECT];
        yield 'news at a company is a person' => [['from' => 'news@company.example'], BuiltinCategory::Todo, ClassificationReason::DIRECT];
        yield 'mail to an alias or in Bcc is for the user directly' => [['to' => ['team@company.example'], 'cc' => []], BuiltinCategory::Todo, ClassificationReason::DIRECT];
        yield 'a domain that is only news is a person' => [['from' => 'editor@news.example'], BuiltinCategory::Todo, ClassificationReason::DIRECT];
    }

    /**
     * @param array<string, mixed> $mail
     */
    #[DataProvider('signals')]
    public function testSignals(array $mail, BuiltinCategory $expected, string $reason): void
    {
        $classification = new RuleBasedClassifier()->classify($this->mail($mail), $this->context());

        self::assertSame($this->builtin[$expected->value], $classification->smartInbox);
        self::assertSame($expected->defaultVerdict(), $classification->verdict);
        self::assertSame($reason, $classification->reason);
    }

    public function testIncludeListOfACustomSmartInboxComesBeforeTheBuiltInOnes(): void
    {
        $invoices = new Category($this->user, 'Invoices', '', Verdict::Do);
        $invoices->setIncludeSenders(['@Supplier.example']);
        $this->builtin['advertise']->setIncludeSenders(['@supplier.example']);

        $classification = new RuleBasedClassifier()->classify($this->mail(['from' => 'billing@mail.supplier.example', 'headers' => ['precedence' => 'bulk']]), $this->context([$invoices]));

        self::assertSame($invoices, $classification->smartInbox);
        self::assertSame(Verdict::Do, $classification->verdict);
        self::assertSame(ClassificationReason::INCLUDE_LIST, $classification->reason);
    }

    public function testExcludeListWinsOverTheIncludeList(): void
    {
        $invoices = new Category($this->user, 'Invoices', '', Verdict::Do);
        $invoices->setIncludeSenders(['@supplier.example']);
        $invoices->setExcludeSenders(['newsletter@supplier.example']);

        $classification = new RuleBasedClassifier()->classify($this->mail(['from' => 'newsletter@supplier.example']), $this->context([$invoices]));

        self::assertSame($this->builtin['advertise'], $classification->smartInbox);
        self::assertSame(ClassificationReason::MARKETING_SENDER, $classification->reason);
    }

    public function testExcludedBuiltInFallsThroughToTheNextSignal(): void
    {
        $this->builtin['notification']->setExcludeSenders(['@forge.example']);

        $classification = new RuleBasedClassifier()->classify($this->mail(['from' => 'noreply@forge.example', 'headers' => ['list-unsubscribe' => '<https://forge.example/u>']]), $this->context());

        self::assertSame($this->builtin['advertise'], $classification->smartInbox);
        self::assertSame(ClassificationReason::UNSUBSCRIBE_LINK, $classification->reason);
    }

    public function testDirectMailFromAnExcludedSenderIsInformational(): void
    {
        $this->builtin['todo']->setExcludeSenders(['@company.example']);

        $classification = new RuleBasedClassifier()->classify($this->mail(['from' => 'boss@company.example']), $this->context());

        self::assertSame($this->builtin['informational'], $classification->smartInbox);
        self::assertSame(ClassificationReason::DIRECT, $classification->reason);
    }

    public function testSenderRuleComesFirstAndCanSetTheVerdict(): void
    {
        $rule = new SenderRule($this->user, 'alerts@forge.example', $this->builtin['todo'], Verdict::Delegate);

        $classification = new RuleBasedClassifier()->classify($this->mail(['from' => 'Alerts@Forge.example', 'headers' => ['auto-submitted' => 'auto-generated']]), $this->context([], [$rule]));

        self::assertSame($this->builtin['todo'], $classification->smartInbox);
        self::assertSame(Verdict::Delegate, $classification->verdict);
        self::assertSame(ClassificationReason::SENDER_RULE, $classification->reason);
    }

    public function testDomainRuleCoversSubdomainsAndTheAddressRuleIsMoreSpecific(): void
    {
        $domain = new SenderRule($this->user, '@shop.example', $this->builtin['spam'], null);
        $address = new SenderRule($this->user, 'orders@shop.example', $this->builtin['notification'], null);
        $context = $this->context([], [$domain, $address]);

        $fromDomain = new RuleBasedClassifier()->classify($this->mail(['from' => 'deals@mail.shop.example']), $context);
        $fromAddress = new RuleBasedClassifier()->classify($this->mail(['from' => 'orders@shop.example']), $context);

        self::assertSame($this->builtin['spam'], $fromDomain->smartInbox);
        self::assertSame(Verdict::None, $fromDomain->verdict, 'Without a verdict of its own, the rule takes the smart inbox verdict.');
        self::assertSame($this->builtin['notification'], $fromAddress->smartInbox);
    }

    public function testSenderRuleIntoASmartInboxThatNowExcludesTheSenderIsIgnored(): void
    {
        $rule = new SenderRule($this->user, 'anna@company.example', $this->builtin['informational'], null);
        $this->builtin['informational']->setExcludeSenders(['@company.example']);

        $classification = new RuleBasedClassifier()->classify($this->mail(['from' => 'anna@company.example']), $this->context([], [$rule]));

        self::assertSame($this->builtin['todo'], $classification->smartInbox);
    }

    public function testForgedSenderFailingAuthenticationIsSpamDespiteASenderRule(): void
    {
        $rule = new SenderRule($this->user, 'ceo@company.example', $this->builtin['todo'], Verdict::Do);
        $mail = $this->mail(['from' => 'ceo@company.example', 'authenticationResultsTrusted' => true, 'headers' => ['authentication-results' => 'mx.example; dmarc=fail (p=QUARANTINE) header.from=company.example']]);

        $classification = new RuleBasedClassifier()->classify($mail, $this->context([], [$rule]));

        self::assertSame($this->builtin['spam'], $classification->smartInbox);
        self::assertSame(ClassificationReason::AUTHENTICATION_FAILED, $classification->reason);
    }

    public function testJunkFromAnUnauthenticatedIncludedSenderStaysSpam(): void
    {
        $invoices = new Category($this->user, 'Invoices', '', Verdict::Do);
        $invoices->setIncludeSenders(['@supplier.example']);

        $classification = new RuleBasedClassifier()->classify($this->mail(['from' => 'billing@supplier.example', 'folderRoles' => [FolderRole::Spam]]), $this->context([$invoices]));

        self::assertSame($this->builtin['spam'], $classification->smartInbox);
        self::assertSame(ClassificationReason::SERVER_SPAM, $classification->reason);
    }

    public function testSenderRuleWinsOverTheServerForAnAuthenticatedSender(): void
    {
        $rule = new SenderRule($this->user, 'billing@supplier.example', $this->builtin['todo'], null);
        $mail = $this->mail(['from' => 'billing@supplier.example', 'junk' => true, 'authenticationResultsTrusted' => true, 'headers' => ['authentication-results' => 'mx.google.com; spf=pass; dkim=pass; dmarc=pass header.from=supplier.example']]);

        $classification = new RuleBasedClassifier()->classify($mail, $this->context([], [$rule]));

        self::assertSame($this->builtin['todo'], $classification->smartInbox);
        self::assertSame(ClassificationReason::SENDER_RULE, $classification->reason);
    }

    public function testFailedAuthenticationIsSpamEvenWhenSpamExcludesTheSender(): void
    {
        $this->builtin['spam']->setExcludeSenders(['@company.example']);
        $mail = $this->mail(['from' => 'ceo@company.example', 'authenticationResultsTrusted' => true, 'headers' => ['authentication-results' => 'mx.example; dmarc=fail (p=REJECT) header.from=company.example']]);

        $classification = new RuleBasedClassifier()->classify($mail, $this->context());

        self::assertSame($this->builtin['spam'], $classification->smartInbox);
        self::assertSame(ClassificationReason::AUTHENTICATION_FAILED, $classification->reason);
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function authenticationResults(): iterable
    {
        yield 'Gmail or Microsoft 365, whose topmost header is their own' => ['mx.google.com; dmarc=pass header.from=supplier.example', true, true];
        yield 'a generic IMAP server, naming the recipient\'s own domain' => ['mx.company.example; dmarc=pass header.from=supplier.example', false, false];
        yield 'a generic IMAP server, naming the sender\'s domain' => ['mx.supplier.example; dmarc=pass header.from=supplier.example', false, false];
    }

    #[DataProvider('authenticationResults')]
    public function testOnlyTheProvidersOwnAuthenticationLetsARuleBeatTheServersSpamVerdict(string $results, bool $trusted, bool $ruleWins): void
    {
        $rule = new SenderRule($this->user, 'billing@supplier.example', $this->builtin['todo'], null);
        $mail = $this->mail(['from' => 'billing@supplier.example', 'junk' => true, 'authenticationResultsTrusted' => $trusted, 'headers' => ['authentication-results' => $results]]);

        $classification = new RuleBasedClassifier()->classify($mail, $this->context([], [$rule]));

        self::assertSame($ruleWins ? $this->builtin['todo'] : $this->builtin['spam'], $classification->smartInbox);
    }

    public function testAuthenticatedJunkWithoutARuleIsStillSpam(): void
    {
        $mail = $this->mail(['junk' => true, 'headers' => ['authentication-results' => 'mx.example; dmarc=pass header.from=partner.example']]);

        $classification = new RuleBasedClassifier()->classify($mail, $this->context());

        self::assertSame($this->builtin['spam'], $classification->smartInbox);
        self::assertSame(ClassificationReason::SERVER_SPAM, $classification->reason);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function mail(array $overrides): MailToClassify
    {
        /** @var array{from: string, to: list<string>, cc: list<string>, headers: array<string, string>, junk: bool, folderRoles: list<FolderRole>, isReply: bool, authenticationResultsTrusted: bool} $mail */
        $mail = [...['from' => 'anna@partner.example', 'to' => [self::ME], 'cc' => [], 'headers' => [], 'junk' => false, 'folderRoles' => [FolderRole::Inbox], 'isReply' => false, 'authenticationResultsTrusted' => false], ...$overrides];

        return new MailToClassify('m1', $mail['from'], null, $mail['to'], $mail['cc'], 'Subject', 'Snippet', $mail['headers'], $mail['junk'], $mail['folderRoles'], $mail['isReply'], $mail['authenticationResultsTrusted']);
    }

    /**
     * @param list<Category>   $custom
     * @param list<SenderRule> $rules
     */
    private function context(array $custom = [], array $rules = []): ClassificationContext
    {
        return new ClassificationContext([self::ME], [...array_values($this->builtin), ...$custom], $rules);
    }
}
