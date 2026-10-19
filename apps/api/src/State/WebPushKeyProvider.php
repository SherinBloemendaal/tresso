<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\WebPushKey;
use App\Notification\Channel\VapidKeys;

/**
 * @implements ProviderInterface<WebPushKey>
 */
final readonly class WebPushKeyProvider implements ProviderInterface
{
    public function __construct(private VapidKeys $keys)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WebPushKey
    {
        return new WebPushKey($this->keys->publicKey());
    }
}
