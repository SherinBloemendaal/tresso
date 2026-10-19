<?php

declare(strict_types=1);

namespace App\Validator;

use App\Enum\ConnectionSecurity;
use App\Mail\HostResolver;
use App\Mail\ServerSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class SafeMailServerValidator extends ConstraintValidator
{
    public function __construct(
        private readonly HostResolver $resolver,
        #[Autowire(env: 'bool:MAIL_ALLOW_UNSAFE_SERVERS')]
        private readonly bool $allowUnsafe,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SafeMailServer) {
            throw new UnexpectedTypeException($constraint, SafeMailServer::class);
        }
        if (null === $value) {
            return;
        }
        if (!$value instanceof ServerSettings) {
            throw new UnexpectedValueException($value, ServerSettings::class);
        }
        if ($this->allowUnsafe || '' === $value->host) {
            return;
        }

        if (ConnectionSecurity::None === $value->security) {
            $this->context->buildViolation($constraint->insecureMessage)->atPath('security')->addViolation();
        }

        $addresses = $this->resolver->resolve($value->host);
        if ([] === $addresses) {
            $this->context->buildViolation($constraint->unresolvableMessage)->atPath('host')->addViolation();

            return;
        }

        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                $this->context->buildViolation($constraint->privateAddressMessage)->atPath('host')->addViolation();

                return;
            }
        }
    }

    public static function isPublic(string $address): bool
    {
        // Covers 10/8, 172.16/12, 192.168/16, fc00::/7 (private) and 0/8, 127/8, 169.254/16, ::1, fe80::/10 and
        // other reserved ranges.
        if (false === filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Ranges the PHP filter does not treat as reserved: carrier-grade NAT and IPv4-mapped IPv6.
        if (self::inCidr($address, '100.64.0.0', 10)) {
            return false;
        }

        return !str_starts_with(strtolower($address), '::ffff:');
    }

    private static function inCidr(string $address, string $network, int $bits): bool
    {
        $ip = ip2long($address);
        if (false === $ip) {
            return false;
        }
        $mask = -1 << (32 - $bits);

        return ($ip & $mask) === (ip2long($network) & $mask);
    }
}
