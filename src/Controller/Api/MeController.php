<?php

namespace App\Controller\Api;

use App\Dto\ChangePasswordRequest;
use App\Dto\ChooseHandleRequest;
use App\Dto\UpdatePreferencesRequest;
use App\Dto\UpdateProfileRequest;
use App\Entity\User;
use App\Presenter\UserPresenter;
use App\Repository\AccessTokenRepository;
use App\Repository\UserRepository;
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
        return $this->json(UserPresenter::self($user));
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

        return $this->json(UserPresenter::self($user));
    }

    /**
     * Language and timezone, saved on their own.
     *
     * The browser has already switched by the time this is called — the choice
     * is applied locally and written to a cookie first, so the site never waits
     * on a round trip to change language. This is what makes the choice follow
     * the account to the next device.
     */
    #[Route('/api/me/preferences', name: 'api_me_preferences', methods: ['PATCH'], format: 'json')]
    public function preferences(
        #[MapRequestPayload] UpdatePreferencesRequest $payload,
        #[CurrentUser] User $user,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user
            ->setLocale($payload->locale)
            ->setTimezone($payload->timezone);

        $entityManager->flush();

        return $this->json(UserPresenter::self($user));
    }

    /**
     * The handle a Google-created account was given, changed once.
     *
     * Only while `handlePending` is set. After that the answer is no, for the
     * same reason sign-up never offered it: the handle is in every link anybody
     * has to the profile.
     */
    #[Route('/api/me/username', name: 'api_me_username', methods: ['POST'], format: 'json')]
    public function username(
        #[MapRequestPayload] ChooseHandleRequest $payload,
        #[CurrentUser] User $user,
        UserRepository $users,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        if (!$user->isHandlePending()) {
            return $this->json(['error' => 'handle_already_set'], Response::HTTP_CONFLICT);
        }

        $wanted = trim($payload->username);

        if ($wanted !== $user->getUsername() && $users->existsActiveByUsername($wanted)) {
            return $this->json(['error' => 'username_already_used'], Response::HTTP_CONFLICT);
        }

        $user->setUsername($wanted)->setHandlePending(false);
        $entityManager->flush();

        return $this->json(UserPresenter::self($user));
    }

    #[Route('/api/me/password', name: 'api_me_password', methods: ['POST'], format: 'json')]
    public function password(
        #[MapRequestPayload] ChangePasswordRequest $payload,
        #[CurrentUser] User $user,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        /*
         * An account that signed up through Google has no password to confirm.
         * Asking for one it has never had would leave it unable to set one at
         * all — the bearer token is the proof here, which is the same proof
         * every other write on this controller accepts.
         */
        if (!$user->hasPassword()) {
            $user->setPassword($passwordHasher->hashPassword($user, $payload->newPassword));
            $entityManager->flush();

            return $this->json(null, Response::HTTP_NO_CONTENT);
        }

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

    /**
     * Close the account.
     *
     * Google Play requires this of any app that lets people sign up: a route
     * to deletion from inside the app, and a public page saying how. Without
     * it a build does not reach any track.
     *
     * Soft delete, the same mechanism an administrator's delete uses — the row
     * stays, `deleted_at` is set, UserChecker rejects the account on the next
     * request, and the profile and everything on it leaves the site. That it
     * can be restored is the reason this endpoint does not ask for a password:
     * a stolen token could trigger it, and the answer to that is a support
     * request rather than a confirmation dialog that a stolen token would sail
     * through anyway.
     *
     * The tokens go immediately rather than being left to expire, so the app
     * that just called this is signed out by the time it draws the next screen.
     */
    #[Route('/api/me', name: 'api_me_delete', methods: ['DELETE'], format: 'json')]
    public function delete(
        #[CurrentUser] User $user,
        AccessTokenRepository $accessTokens,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        if ($user->isDeleted()) {
            return $this->json(['error' => 'already_deleted'], Response::HTTP_CONFLICT);
        }

        $user->softDelete();
        $accessTokens->revokeAllFor($user);
        $entityManager->flush();

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

        return $this->json(UserPresenter::self($user));
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

        return $this->json(UserPresenter::self($user));
    }

    /** Cleared inputs arrive as empty strings; the columns want null. */
    private function cleaned(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
