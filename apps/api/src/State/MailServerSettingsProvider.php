<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MailServerSettings;
use App\Mail\Autoconfig\ServerSettingsDetector;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<MailServerSettings>
 */
final readonly class MailServerSettingsProvider implements ProviderInterface
{
    public function __construct(
        private ServerSettingsDetector $detector,
        private RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MailServerSettings
    {
        $emailAddress = mb_strtolower(trim((string) $this->requests->getCurrentRequest()?->query->get('emailAddress')));
        $detected = $this->detector->detect($emailAddress);
        if (null === $detected) {
            return new MailServerSettings($emailAddress, false, null, null, null, null, []);
        }

        return new MailServerSettings($emailAddress, true, $detected->source, $detected->username, $detected->imap, $detected->smtp, $detected->authentication);
    }
}
