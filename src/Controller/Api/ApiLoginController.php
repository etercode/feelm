<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ApiLoginController extends AbstractController
{
    /**
     * json_login check_path. On success LoginSuccessHandler returns the token.
     */
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(
            ['error' => 'authentication_error', 'message' => 'Authentication failed.'],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
