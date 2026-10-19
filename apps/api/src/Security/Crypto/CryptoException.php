<?php

declare(strict_types=1);

namespace App\Security\Crypto;

/**
 * Raised when data cannot be encrypted or decrypted: unknown key, tampered ciphertext, wrong context or bad format.
 */
final class CryptoException extends \RuntimeException
{
}
