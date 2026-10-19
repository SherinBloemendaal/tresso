<?php

declare(strict_types=1);

namespace App\Tests\Unit\Classification;

use App\Classification\ReceivingServers;
use App\Entity\MailAccount;
use App\Entity\User;
use App\Enum\MailProvider;
use App\Mail\ServerSettings;
use PHPUnit\Framework\TestCase;

final class ReceivingServersTest extends TestCase
{
    public function testAGenericImapServerMayAddNoHeaderOfItsOwnSoItsTopmostHeaderIsNotTrusted(): void
    {
        $account = new MailAccount(new User('kim@example.test', 'Kim'), 'kim@company.example', 'Work', MailProvider::Imap);
        $account->configureServers(new ServerSettings('imap.company.example', 993), new ServerSettings('smtp.company.example', 465), 'kim');

        self::assertFalse(ReceivingServers::trustsTopmostHeader($account));
    }

    public function testProvidersThatAlwaysAddTheirOwnHeaderAreTrustedAtTheTop(): void
    {
        $user = new User('kim@example.test', 'Kim');

        self::assertTrue(ReceivingServers::trustsTopmostHeader(new MailAccount($user, 'kim@gmail.example', 'Gmail', MailProvider::Gmail)));
        self::assertTrue(ReceivingServers::trustsTopmostHeader(new MailAccount($user, 'kim@outlook.example', 'Outlook', MailProvider::Microsoft)));
    }
}
