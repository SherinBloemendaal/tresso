<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Enum\ConnectionSecurity;
use App\Mail\HostResolver;
use App\Mail\ServerSettings;
use App\Validator\SafeMailServer;
use App\Validator\SafeMailServerValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<SafeMailServerValidator>
 */
final class SafeMailServerValidatorTest extends ConstraintValidatorTestCase
{
    /** @var array<string, list<string>> host => addresses */
    private array $dns = [];
    private bool $allowUnsafe = false;

    protected function createValidator(): ConstraintValidatorInterface
    {
        $dns = &$this->dns;
        $resolver = new class($dns) extends HostResolver {
            /** @param array<string, list<string>> $dns */
            public function __construct(private array &$dns)
            {
            }

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        };

        return new SafeMailServerValidator($resolver, $this->allowUnsafe);
    }

    public function testPublicTlsServerIsValid(): void
    {
        $this->dns['imap.company.example'] = ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];

        $this->validate(new ServerSettings('imap.company.example', 993, ConnectionSecurity::Tls), new SafeMailServer());

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateAddresses(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'private 10/8' => ['10.1.2.3'];
        yield 'private 172.16/12' => ['172.20.0.5'];
        yield 'private 192.168/16' => ['192.168.1.10'];
        yield 'link local (cloud metadata)' => ['169.254.169.254'];
        yield 'carrier-grade NAT' => ['100.64.1.1'];
        yield 'unspecified' => ['0.0.0.0'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 unique local' => ['fd00::1'];
        yield 'ipv6 link local' => ['fe80::1'];
        yield 'ipv4-mapped ipv6' => ['::ffff:10.0.0.1'];
    }

    #[DataProvider('privateAddresses')]
    public function testPrivateAndReservedAddressesAreRejected(string $address): void
    {
        $this->dns['internal.company.example'] = ['93.184.216.34', $address];

        $this->validate(new ServerSettings('internal.company.example', 993, ConnectionSecurity::Tls), new SafeMailServer());

        $this->buildViolation('This mail server resolves to a private or reserved address.')->atPath('property.path.host')->assertRaised();
    }

    public function testIpLiteralIsCheckedToo(): void
    {
        $this->dns['10.0.0.8'] = ['10.0.0.8'];

        $this->validate(new ServerSettings('10.0.0.8', 993, ConnectionSecurity::Tls), new SafeMailServer());

        $this->buildViolation('This mail server resolves to a private or reserved address.')->atPath('property.path.host')->assertRaised();
    }

    public function testUnresolvableHostIsRejected(): void
    {
        $this->validate(new ServerSettings('nowhere.example', 993, ConnectionSecurity::Tls), new SafeMailServer());

        $this->buildViolation('This mail server could not be found.')->atPath('property.path.host')->assertRaised();
    }

    public function testUnencryptedConnectionIsRejected(): void
    {
        $this->dns['imap.company.example'] = ['93.184.216.34'];

        $this->validate(new ServerSettings('imap.company.example', 143, ConnectionSecurity::None), new SafeMailServer());

        $this->buildViolation('Unencrypted connections are not allowed. Use TLS or STARTTLS.')->atPath('property.path.security')->assertRaised();
    }

    public function testUnsafeServersAllowedForLocalTesting(): void
    {
        $this->allowUnsafe = true;
        $this->validator = $this->createValidator();
        $this->dns['greenmail'] = ['172.18.0.4'];

        $this->validate(new ServerSettings('greenmail', 3143, ConnectionSecurity::None), new SafeMailServer());

        $this->assertNoViolation();
    }
}
