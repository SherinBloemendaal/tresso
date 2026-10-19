<?php

declare(strict_types=1);

namespace App\Mail\Autoconfig;

use App\Enum\ConnectionSecurity;
use App\Mail\ServerSettings;

/**
 * Reads the autoconfig format Thunderbird defined and many providers publish (clientConfig version 1.1): the best
 * IMAP and SMTP server for an address, the username to use, and how users sign in.
 */
final readonly class AutoconfigParser
{
    private const array SOCKET_TYPES = ['SSL' => ConnectionSecurity::Tls, 'STARTTLS' => ConnectionSecurity::StartTls];
    private const array PASSWORD_METHODS = ['password-cleartext', 'password-encrypted', 'plain', 'secure'];

    public function parse(string $xml, string $emailAddress): ?DetectedServers
    {
        try {
            $document = \Dom\XMLDocument::createFromString($xml, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        } catch (\Throwable) {
            return null;
        }

        $provider = $document->querySelector('clientConfig > emailProvider');
        if (null === $provider) {
            return null;
        }
        $imap = $this->best($provider->querySelectorAll('incomingServer[type="imap"]'), $emailAddress);
        $smtp = $this->best($provider->querySelectorAll('outgoingServer[type="smtp"]'), $emailAddress);
        if (null === $imap || null === $smtp) {
            return null;
        }

        return new DetectedServers($imap['settings'], $smtp['settings'], $imap['username'], array_values(array_unique([...$imap['authentication'], ...$smtp['authentication']])));
    }

    /**
     * The first encrypted server, preferring implicit TLS over STARTTLS. Unencrypted servers are never offered.
     *
     * @param iterable<\Dom\Element> $servers
     *
     * @return array{settings: ServerSettings, username: string, authentication: list<string>}|null
     */
    private function best(iterable $servers, string $emailAddress): ?array
    {
        $candidates = [];
        foreach ($servers as $server) {
            $host = $this->text($server, 'hostname');
            $port = (int) $this->text($server, 'port');
            $security = self::SOCKET_TYPES[strtoupper($this->text($server, 'socketType'))] ?? null;
            if (null === $security || '' === $host || $port < 1 || $port > 65535 || 1 !== preg_match('/^[a-z0-9.-]{1,253}$/i', $host)) {
                continue;
            }

            $authentication = [];
            foreach ($server->querySelectorAll('authentication') as $method) {
                $value = strtolower(trim((string) $method->textContent));
                if (\in_array($value, self::PASSWORD_METHODS, true)) {
                    $authentication[] = 'password';
                } elseif ('oauth2' === $value) {
                    $authentication[] = 'oauth2';
                }
            }

            $candidates[] = [
                'settings' => new ServerSettings(strtolower($this->substitute($host, $emailAddress)), $port, $security),
                'username' => $this->substitute($this->text($server, 'username'), $emailAddress),
                'authentication' => array_values(array_unique($authentication)),
            ];
        }
        usort($candidates, static fn (array $a, array $b): int => ($a['settings']->security === ConnectionSecurity::Tls ? 0 : 1) <=> ($b['settings']->security === ConnectionSecurity::Tls ? 0 : 1));

        return $candidates[0] ?? null;
    }

    private function text(\Dom\Element $parent, string $child): string
    {
        foreach ($parent->children as $element) {
            if ($element->localName === $child) {
                return trim((string) $element->textContent);
            }
        }

        return '';
    }

    private function substitute(string $template, string $emailAddress): string
    {
        [$local, $domain] = explode('@', $emailAddress, 2) + [1 => ''];

        return strtr($template, ['%EMAILADDRESS%' => $emailAddress, '%EMAILLOCALPART%' => $local, '%EMAILDOMAIN%' => $domain]);
    }
}
