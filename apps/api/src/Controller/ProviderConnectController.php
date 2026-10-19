<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\OAuthProvider;
use App\ProviderAuth\ProviderConnector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Where Google and Microsoft send the browser back after a sign-in. Behind the browser session (access_control:
 * ROLE_USER), so a signed-out browser signs in to Tresso first and comes back here; the connector then checks that
 * this is the user who started the sign-in. The browser always ends up in the web app, with the outcome in the query.
 */
final class ProviderConnectController extends AbstractController
{
    public function __construct(
        private readonly ProviderConnector $connector,
        #[Autowire(env: 'WEB_APP_URL')]
        private readonly string $webAppUrl,
    ) {
    }

    #[Route('/connect/{provider}/callback', name: 'provider_connect_callback', methods: ['GET'])]
    public function callback(string $provider, Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $oauthProvider = OAuthProvider::tryFrom($provider) ?? throw new NotFoundHttpException('Unknown provider.');
        $result = $this->connector->complete($user, $oauthProvider, $request->query->all());

        $response = new RedirectResponse(rtrim($this->webAppUrl, '/').$result->path.'?'.http_build_query($result->query));
        // The URL held a one-time code; keep it out of other sites' Referer headers and out of caches.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
