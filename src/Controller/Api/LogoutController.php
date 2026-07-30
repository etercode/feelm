<?php

namespace App\Controller\Api;

use App\Repository\AccessTokenRepository;
use App\Service\AccessTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LogoutController extends AbstractController
{
    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(
        Request $request,
        AccessTokenRepository $accessTokenRepository,
        AccessTokenManager $accessTokenManager,
    ): JsonResponse {
        $authorization = (string) $request->headers->get('Authorization');

        if (str_starts_with($authorization, 'Bearer ')) {
            $tokenValue = substr($authorization, 7);
            $accessToken = $accessTokenRepository->findOneByToken($tokenValue);
            if (null !== $accessToken) {
                $accessTokenManager->revoke($accessToken);
            }
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
