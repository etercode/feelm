<?php

namespace App\Controller\Api;

use App\Dto\ChangePasswordRequest;
use App\Dto\UpdateProfileRequest;
use App\Entity\User;
use App\Presenter\UserPresenter;
use App\Service\User\AvatarStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MeController extends AbstractController
{
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(UserPresenter::one($user));
    }

    #[Route('/api/me', name: 'api_me_update', methods: ['PATCH'], format: 'json')]
    public function update(
        #[MapRequestPayload] UpdateProfileRequest $payload,
        #[CurrentUser] User $user,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user
            ->setName(trim($payload->name))
            ->setTagline($this->cleaned($payload->tagline))
            ->setBio($this->cleaned($payload->bio))
            ->setLocation($this->cleaned($payload->location));

        $entityManager->flush();

        return $this->json(UserPresenter::one($user));
    }

    #[Route('/api/me/password', name: 'api_me_password', methods: ['POST'], format: 'json')]
    public function password(
        #[MapRequestPayload] ChangePasswordRequest $payload,
        #[CurrentUser] User $user,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        if (!$passwordHasher->isPasswordValid($user, $payload->currentPassword)) {
            return $this->json(['error' => 'wrong_password'], Response::HTTP_FORBIDDEN);
        }

        if ($payload->currentPassword === $payload->newPassword) {
            return $this->json(['error' => 'password_unchanged'], Response::HTTP_BAD_REQUEST);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $payload->newPassword));
        $entityManager->flush();

        // Tokens already issued stay valid. Signing every device out on a
        // password change is defensible, but it is a bigger decision than this
        // endpoint should be making on its own.
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/me/avatar', name: 'api_me_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(
        Request $request,
        #[CurrentUser] User $user,
        AvatarStorage $avatars,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $file = $request->files->get('avatar');
        if (null === $file) {
            return $this->json(['error' => 'no_file'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $user->setAvatar($avatars->store($user, $file));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entityManager->flush();

        return $this->json(UserPresenter::one($user));
    }

    #[Route('/api/me/avatar', name: 'api_me_avatar_delete', methods: ['DELETE'])]
    public function deleteAvatar(
        #[CurrentUser] User $user,
        AvatarStorage $avatars,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $avatars->discard($user->getAvatar());
        $user->setAvatar(null);
        $entityManager->flush();

        return $this->json(UserPresenter::one($user));
    }

    /** Cleared inputs arrive as empty strings; the columns want null. */
    private function cleaned(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
