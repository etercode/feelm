<?php

namespace App\Security;

use App\Entity\User;
use App\Service\AccessTokenManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly AccessTokenManager $accessTokenManager,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var User $user */
        $user = $token->getUser();

        $accessToken = $this->accessTokenManager->create($user, $request);

        return new JsonResponse([
            'token_type' => 'Bearer',
            'access_token' => $accessToken->getToken(),
            'expires_at' => $accessToken->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'refresh_token' => $accessToken->getRefreshToken(),
            'refresh_token_expires_at' => $accessToken->getRefreshTokenExpiresAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_OK);
    }
}
