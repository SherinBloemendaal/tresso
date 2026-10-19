<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SyncRealtimeToken;
use App\Realtime\MercureTokens;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @implements ProviderInterface<SyncRealtimeToken>
 */
final readonly class SyncRealtimeTokenProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MercureTokens $tokens,
        #[Autowire(env: 'MERCURE_INTERNAL_URL')]
        private string $internalHubUrl,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SyncRealtimeToken
    {
        $authentication = $this->security->getToken();
        if (!$authentication instanceof OAuth2Token) {
            throw new AccessDeniedException('This endpoint requires an OAuth2 access token.');
        }
        $token = $this->tokens->workerToken($authentication->getOAuthClientId());

        return new SyncRealtimeToken($this->internalHubUrl, MercureTokens::OUTBOX_TOPIC, $token->value, $token->expiresAt);
    }
}
