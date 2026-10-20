<?php

declare(strict_types=1);

namespace App\Unsubscribe;

use App\Classification\SenderAuthentication;
use App\Enum\UnsubscribeMethod;

/**
 * How a message can be unsubscribed from with one click, read from its List-Unsubscribe header (RFC 2369) and its
 * List-Unsubscribe-Post header (RFC 8058).
 *
 * Only for authenticated senders: the receiving server must vouch that DMARC passed for the From domain (see
 * {@see SenderAuthentication}). Anyone can write these headers, so without that a forged message could make the
 * worker POST wherever it likes, have the user's account mail anyone, or mark the real sender as unsubscribed.
 *
 * One-click wins: an HTTPS address on the standard port whose sender also says `List-Unsubscribe=One-Click`, which the
 * sync worker POSTs to without following anything. Otherwise a `mailto:` address in the sender's own domain (the From
 * domain, a parent or a subdomain of it), which gets one fixed mail: "unsubscribe", whatever subject or body the
 * header asks for, to one recipient. An HTTP(S) address without the one-click promise is never used: opening it is a
 * GET of an arbitrary page that may ask for more, track the reader or do something else entirely.
 */
final readonly class UnsubscribeLink
{
    /** Subject and text of a mailto unsubscribe; the header's own subject and body are not used. */
    public const string MAIL_TEXT = 'unsubscribe';

    /** Longest address we act on; a longer one is not a list's unsubscribe address. */
    private const int MAX_URI_LENGTH = 2048;

    private function __construct(
        public UnsubscribeMethod $method,
        /** The HTTPS address to POST to, or the mailto: URI. */
        public string $uri,
        /** For mailto: the one address the mail goes to. */
        public ?string $recipient = null,
    ) {
    }

    /**
     * @param array<string, string> $headers lower-cased header names, as message_signals keeps them
     * @param bool $resultsTrusted whether the receiving server surely wrote the Authentication-Results header
     */
    public static function fromMessage(array $headers, string $fromAddress, bool $resultsTrusted): ?self
    {
        if (!SenderAuthentication::dmarcPassed($headers['authentication-results'] ?? null, $resultsTrusted)) {
            return null;
        }
        $list = $headers['list-unsubscribe'] ?? null;
        if (null === $list || '' === trim($list)) {
            return null;
        }
        $uris = self::uris($list);
        if (self::promisesOneClick($headers['list-unsubscribe-post'] ?? null)) {
            foreach ($uris as $uri) {
                if (self::isOneClickUri($uri)) {
                    return new self(UnsubscribeMethod::OneClick, $uri);
                }
            }
        }
        $fromDomain = self::domainOf($fromAddress);
        foreach ($uris as $uri) {
            $recipient = self::mailtoRecipient($uri);
            if (null !== $recipient && null !== $fromDomain && self::sameOrganization(self::domainOf($recipient) ?? '', $fromDomain)) {
                return new self(UnsubscribeMethod::Mailto, $uri, $recipient);
            }
        }

        return null;
    }

    /**
     * The angle-bracketed URIs of the header, in order. Text outside brackets (comments, a bare address) is ignored, as
     * RFC 2369 asks.
     *
     * @return list<string>
     */
    private static function uris(string $header): array
    {
        preg_match_all('/<([^<>]*)>/', $header, $matches);
        $uris = [];
        foreach ($matches[1] as $uri) {
            // Folding whitespace inside the brackets is not part of the address.
            $uri = preg_replace('/\s+/', '', $uri) ?? '';
            if ('' !== $uri && \strlen($uri) <= self::MAX_URI_LENGTH) {
                $uris[] = $uri;
            }
        }

        return $uris;
    }

    private static function promisesOneClick(?string $post): bool
    {
        return null !== $post && 0 === strcasecmp(trim($post), 'List-Unsubscribe=One-Click');
    }

    /**
     * HTTPS with a host name, on the standard port, and no user info: RFC 8058 needs HTTPS, and a URI with credentials
     * or another port is not a list's.
     */
    private static function isOneClickUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (false === $parts || 'https' !== strtolower($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && 443 !== $parts['port'])) {
            return false;
        }

        return 1 !== preg_match('/[\x00-\x20\x7f]/', $uri);
    }

    /** The one address of a mailto: URI; null when it names none, or more than one (`to` fields included). */
    private static function mailtoRecipient(string $uri): ?string
    {
        if (0 !== strncasecmp($uri, 'mailto:', 7)) {
            return null;
        }
        $rest = substr($uri, 7);
        $query = '';
        if (false !== ($at = strpos($rest, '?'))) {
            $query = substr($rest, $at + 1);
            $rest = substr($rest, 0, $at);
        }
        $addresses = array_values(array_filter(array_map(trim(...), explode(',', rawurldecode($rest))), static fn (string $a): bool => '' !== $a));
        foreach ('' === $query ? [] : explode('&', $query) as $pair) {
            [$name] = explode('=', $pair, 2);
            if (\in_array(strtolower(rawurldecode($name)), ['to', 'cc', 'bcc'], true)) {
                // More recipients than the one in the path: not a plain unsubscribe address.
                return null;
            }
        }
        if (1 !== \count($addresses) || false === filter_var($addresses[0], \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $addresses[0];
    }

    private static function domainOf(string $address): ?string
    {
        $at = strrpos($address, '@');

        return false === $at ? null : rtrim(strtolower(substr($address, $at + 1)), '.');
    }

    /**
     * Whether two domains belong to one organization as far as a mail address goes: the same, or one a subdomain of
     * the other. A parent above the organizational domain (a public suffix such as `co.uk`) receives no mail, so it
     * gains a forger nothing.
     */
    private static function sameOrganization(string $domain, string $fromDomain): bool
    {
        if ('' === $domain) {
            return false;
        }

        return $domain === $fromDomain || str_ends_with($domain, '.'.$fromDomain) || str_ends_with($fromDomain, '.'.$domain);
    }
}
