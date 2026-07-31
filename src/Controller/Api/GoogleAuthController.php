<?php

namespace App\Controller\Api;

use App\Presenter\UserPresenter;
use App\Service\AccessTokenManager;
use App\Service\Auth\GoogleIdTokenVerifier;
use App\Service\Auth\GoogleSignIn;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sign in with Google.
 *
 * The browser does the Google half and hands us the ID token; everything after
 * verification is ordinary. The response is deliberately the same shape as
 * /api/login, so the front end stores tokens the one way it always has and
 * nothing downstream knows how somebody signed in.
 */
class GoogleAuthController extends AbstractController
{
    #[Route('/api/auth/google', name: 'api_auth_google', methods: ['POST'], format: 'json')]
    public function google(
        Request $request,
        GoogleIdTokenVerifier $verifier,
        GoogleSignIn $signIn,
        AccessTokenManager $accessTokenManager,
    ): JsonResponse {
        if (!$verifier->isConfigured()) {
            return $this->json(['error' => 'google_not_configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $payload = json_decode($request->getContent(), true);
        $credential = \is_array($payload) ? ($payload['credential'] ?? null) : null;

        if (!\is_string($credential) || '' === $credential) {
            return $this->json(['error' => 'missing_credential'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $claims = $verifier->verify($credential);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        $user = $signIn->resolve($claims);
        $token = $accessTokenManager->create($user, $request);

        return $this->json([
            'token_type' => 'Bearer',
            'access_token' => $token->getToken(),
            'expires_at' => $token->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'refresh_token' => $token->getRefreshToken(),
            'refresh_token_expires_at' => $token->getRefreshTokenExpiresAt()->format(\DateTimeInterface::ATOM),
            'user' => UserPresenter::self($user),
        ]);
    }
}
