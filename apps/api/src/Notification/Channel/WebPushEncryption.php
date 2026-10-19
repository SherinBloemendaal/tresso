<?php

declare(strict_types=1);

namespace App\Notification\Channel;

/**
 * Message encryption for Web Push (RFC 8291, aes128gcm from RFC 8188): an ephemeral P-256 key agreed with the
 * subscription's key, mixed with its auth secret, encrypts the payload in one record.
 */
final class WebPushEncryption
{
    private const int RECORD_SIZE = 4096;

    /**
     * @param string      $userPublicKey raw uncompressed P-256 point (65 bytes) from the subscription
     * @param string      $authSecret    16 bytes from the subscription
     * @param string|null $serverPrivate PEM of the ephemeral key; a fresh one when null (tests pass the RFC's)
     * @param string|null $salt          16 random bytes when null
     */
    public static function encrypt(string $payload, string $userPublicKey, string $authSecret, ?string $serverPrivate = null, ?string $salt = null): string
    {
        if (65 !== \strlen($userPublicKey) || "\x04" !== $userPublicKey[0]) {
            throw new \InvalidArgumentException('The subscription key is no uncompressed P-256 point.');
        }
        if (16 !== \strlen($authSecret)) {
            throw new \InvalidArgumentException('The subscription auth secret must be 16 bytes.');
        }
        if (\strlen($payload) > self::RECORD_SIZE - 17 - 86) {
            throw new \InvalidArgumentException('The push message is too large.');
        }

        $server = null === $serverPrivate
            ? openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC])
            : openssl_pkey_get_private($serverPrivate);
        if (false === $server) {
            throw new \RuntimeException('Could not create an ephemeral key: '.openssl_error_string());
        }
        $serverPublicKey = self::publicPoint($server);
        $salt ??= random_bytes(16);

        $sharedSecret = openssl_pkey_derive(self::publicKeyFromPoint($userPublicKey), $server);
        if (false === $sharedSecret) {
            throw new \RuntimeException('Key agreement failed: '.openssl_error_string());
        }

        // RFC 8291 3.4: the auth secret and both public keys go into the input keying material.
        $keyInfo = "WebPush: info\x00".$userPublicKey.$serverPublicKey;
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $authSecret);
        $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // One record, so the padding delimiter is 0x02 (last record).
        $tag = '';
        $ciphertext = openssl_encrypt($payload."\x02", 'aes-128-gcm', $contentKey, \OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (false === $ciphertext) {
            throw new \RuntimeException('Encryption failed: '.openssl_error_string());
        }

        $header = $salt.pack('N', self::RECORD_SIZE).\chr(65).$serverPublicKey;

        return $header.$ciphertext.$tag;
    }

    /** The raw uncompressed point of an EC key. */
    public static function publicPoint(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        $ec = \is_array($details) ? ($details['ec'] ?? null) : null;
        if (!\is_array($ec) || !\is_string($ec['x'] ?? null) || !\is_string($ec['y'] ?? null)) {
            throw new \RuntimeException('Not an EC key.');
        }

        return "\x04".str_pad($ec['x'], 32, "\x00", \STR_PAD_LEFT).str_pad($ec['y'], 32, "\x00", \STR_PAD_LEFT);
    }

    /** A public key object from a raw uncompressed P-256 point, through its SubjectPublicKeyInfo DER. */
    public static function publicKeyFromPoint(string $point): \OpenSSLAsymmetricKey
    {
        // SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING point }
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode((string) $der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (false === $key) {
            throw new \InvalidArgumentException('The subscription key is not on the P-256 curve.');
        }

        return $key;
    }

    /** A private key PEM from a raw 32-byte P-256 scalar and its public point (SEC 1 ECPrivateKey). */
    public static function privateKeyPem(string $scalar, string $point): string
    {
        // SEQUENCE { 1, OCTET STRING d, [0] prime256v1, [1] BIT STRING point }
        $der = hex2bin('30770201010420').$scalar.hex2bin('a00a06082a8648ce3d030107a144034200').$point;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    public static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Invalid base64url data.');
        }

        return $decoded;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
