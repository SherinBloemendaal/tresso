<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enum\ConnectionSecurity;
use App\Validator\SafeMailServer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Where an IMAP or SMTP server lives. Never holds secrets.
 */
#[SafeMailServer]
final readonly class ServerSettings
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 253)]
        #[Assert\Hostname(requireTld: false)]
        public string $host = '',
        #[Assert\Range(min: 1, max: 65535)]
        public int $port = 0,
        public ConnectionSecurity $security = ConnectionSecurity::Tls,
    ) {
    }

    /**
     * @return array{host: string, port: int, security: string}
     */
    public function toArray(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'security' => $this->security->value];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $host = $data['host'] ?? null;
        $port = $data['port'] ?? null;
        $security = $data['security'] ?? null;
        if (!\is_string($host) || !\is_int($port) || !\is_string($security)) {
            throw new \UnexpectedValueException('Stored server settings are incomplete.');
        }

        return new self($host, $port, ConnectionSecurity::from($security));
    }
}
