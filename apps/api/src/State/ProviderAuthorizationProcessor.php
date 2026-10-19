<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\StartProviderAuthorization;
use App\ApiResource\ProviderAuthorization;
use App\Entity\MailAccount;
use App\Enum\OAuthProvider;
use App\ProviderAuth\ProviderConnector;
use App\ProviderAuth\ProviderSettings;
use App\Repository\MailAccountRepository;
use App\Security\CurrentUser;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<StartProviderAuthorization, ProviderAuthorization>
 */
final readonly class ProviderAuthorizationProcessor implements ProcessorInterface
{
    public function __construct(
        private ProviderConnector $connector,
        private ProviderSettings $settings,
        private MailAccountRepository $mailAccounts,
        private CurrentUser $currentUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProviderAuthorization
    {
        $owner = $this->currentUser->get();
        $provider = $data->provider ?? throw new \LogicException('Validation guarantees a provider.');

        if (!$this->settings->isConfigured($provider)) {
            throw self::violation($data, 'provider', \sprintf('Signing in with %s is not set up on this server.', $provider->label()), $provider->value);
        }

        $mailAccount = null;
        if (null !== $data->mailAccountId) {
            $mailAccount = $this->mailAccounts->findOneOwnedBy($owner, $data->mailAccountId);
            if (!$mailAccount instanceof MailAccount) {
                throw self::violation($data, 'mailAccountId', 'Mail account not found.', $data->mailAccountId);
            }
            if (OAuthProvider::forMailProvider($mailAccount->getProvider()) !== $provider) {
                throw self::violation($data, 'mailAccountId', \sprintf('This account does not sign in with %s.', $provider->label()), $data->mailAccountId);
            }
        }

        $started = $this->connector->start($owner, $provider, $data->features, $mailAccount);

        return new ProviderAuthorization($started['url'], $provider->value, $started['expiresAt']);
    }

    private static function violation(StartProviderAuthorization $input, string $path, string $message, string $value): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], $input, $path, $value)]));
    }
}
