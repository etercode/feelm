<?php

namespace App\Controller\Api;

use App\Repository\AccessTokenRepository;
use App\Service\AccessTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TokenRefreshController extends AbstractController
{
    #[Route('/api/token/refresh', name: 'api_token_refresh', methods: ['POST'])]
    public function refresh(
        Request $request,
        AccessTokenRepository $accessTokenRepository,
        AccessTokenManager $accessTokenManager,
    ): JsonResponse {
        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return $this->json(['error' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }

        $refreshToken = $data['refresh_token'] ?? null;
        if (!\is_string($refreshToken) || '' === $refreshToken) {
            return $this->json(['error' => 'missing_field', 'field' => 'refresh_token'], Response::HTTP_BAD_REQUEST);
        }

        $accessToken = $accessTokenRepository->findOneByRefreshToken($refreshToken);
        if (null === $accessToken || $accessToken->isRefreshTokenExpired()) {
            return $this->json(['error' => 'invalid_refresh_token'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $accessToken->getUser();
        if (null === $user || $user->isDeleted()) {
            return $this->json(['error' => 'invalid_refresh_token'], Response::HTTP_UNAUTHORIZED);
        }

        $accessToken = $accessTokenManager->refresh($accessToken);

        return $this->json([
            'token_type' => 'Bearer',
            'access_token' => $accessToken->getToken(),
            'expires_at' => $accessToken->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'refresh_token' => $accessToken->getRefreshToken(),
            'refresh_token_expires_at' => $accessToken->getRefreshTokenExpiresAt()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
