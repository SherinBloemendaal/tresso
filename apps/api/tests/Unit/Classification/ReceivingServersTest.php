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
    public function testAnImapAccountTrustsItsOwnDomainAndItsServersDomain(): void
    {
        $account = new MailAccount(new User('kim@example.test', 'Kim'), 'kim@company.example', 'Work', MailProvider::Imap);
        $account->configureServers(new ServerSettings('imap.host.example.co.uk', 993), new ServerSettings('smtp.host.example.co.uk', 465), 'kim');

        self::assertSame(['company.example', 'host.example.co.uk'], ReceivingServers::domainsFor($account));
    }

    public function testProvidersThatAlwaysAddTheirOwnHeaderAreTrustedAtTheTop(): void
    {
        $account = new MailAccount(new User('kim@example.test', 'Kim'), 'kim@gmail.example', 'Gmail', MailProvider::Gmail);

        self::assertNull(ReceivingServers::domainsFor($account));
        self::assertTrue(ReceivingServers::wrote('spf=pass; dmarc=pass', null));
    }
}
