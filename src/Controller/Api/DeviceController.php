<?php

namespace App\Controller\Api;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Repository\DeviceTokenRepository;
use App\Service\Notify\PushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Where a phone says "here is how to reach me", and which pushes it wants.
 *
 * Deliberately not folded into MeController: that one is the account, and this
 * is the hardware. A device outlives a preferences save and a preferences save
 * has nothing to do with the device it was made from.
 */
class DeviceController extends AbstractController
{
    /**
     * Register, or re-register, this install.
     *
     * Called on every cold start, not only after sign-in. FCM rotates tokens on
     * its own schedule — a restore from backup, a long gap between launches —
     * and the app has no way to know a rotation happened except by asking and
     * sending whatever it gets. So this has to be idempotent and cheap, and the
     * common case is "same token, same user, bump last_seen_at".
     */
    #[Route('/api/me/devices', name: 'api_me_devices_register', methods: ['POST'])]
    public function register(
        Request $request,
        #[CurrentUser] User $user,
        DeviceTokenRepository $deviceTokens,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        $token = trim((string) ($payload['token'] ?? ''));
        if ('' === $token) {
            return $this->json(['error' => 'missing_token'], Response::HTTP_BAD_REQUEST);
        }

        /*
         * The token is the identity, so an existing row moves to whoever is
         * signed in now. Two accounts on one phone is a real case — a shared
         * tablet, somebody testing — and the alternative is the previous
         * account continuing to receive this one's notifications from a row
         * nothing will ever clean up.
         */
        $device = $deviceTokens->findOneByToken($token) ?? (new DeviceToken())->setToken($token);

        $device
            ->setUser($user)
            ->setPlatform($payload['platform'] ?? null)
            ->setLocale($payload['locale'] ?? null)
            ->touch();

        $entityManager->persist($device);
        $entityManager->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Forget this install.
     *
     * A POST rather than a DELETE with the token in the path: registration
     * tokens run past 160 characters and contain colons, which is a URL nobody
     * wants to debug through three layers of proxy.
     *
     * Signing out must call this. Otherwise the phone keeps receiving pushes
     * for an account that is no longer on it, which is the single most alarming
     * bug this feature can have.
     */
    #[Route('/api/me/devices/unregister', name: 'api_me_devices_unregister', methods: ['POST'])]
    public function unregister(
        Request $request,
        #[CurrentUser] User $user,
        DeviceTokenRepository $deviceTokens,
    ): JsonResponse {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        $token = trim((string) ($payload['token'] ?? ''));
        if ('' === $token) {
            return $this->json(['error' => 'missing_token'], Response::HTTP_BAD_REQUEST);
        }

        // Only your own. A token is guessable in the sense that anything a
        // client sends is guessable, and unregistering somebody else's phone
        // would be a quiet way to silence them.
        $device = $deviceTokens->findOneByToken($token);
        if (null !== $device && $device->getUser()?->getId() === $user->getId()) {
            $deviceTokens->deleteByToken($token);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Which kinds exist, and which this account wants.
     *
     * The kinds travel with the answer so the settings screen renders whatever
     * the server currently supports, instead of a list frozen into whichever
     * app version somebody last installed.
     */
    #[Route('/api/me/push', name: 'api_me_push_prefs', methods: ['GET'])]
    public function preferences(#[CurrentUser] User $user): JsonResponse
    {
        $prefs = [];
        foreach (array_keys(PushNotifier::KINDS) as $kind) {
            $prefs[$kind] = $user->wantsPush($kind);
        }

        return $this->json([
            'kinds' => array_keys(PushNotifier::KINDS),
            'prefs' => $prefs,
        ]);
    }

    #[Route('/api/me/push', name: 'api_me_push_prefs_update', methods: ['PATCH'])]
    public function updatePreferences(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        // Only known kinds, so a typo cannot write a key that nothing reads and
        // that quietly persists forever.
        $clean = [];
        foreach (PushNotifier::KINDS as $kind => $_description) {
            if (\array_key_exists($kind, $payload)) {
                $clean[$kind] = (bool) $payload[$kind];
            }
        }

        $user->setPushPrefs($clean);
        $entityManager->flush();

        $prefs = [];
        foreach (array_keys(PushNotifier::KINDS) as $kind) {
            $prefs[$kind] = $user->wantsPush($kind);
        }

        return $this->json(['prefs' => $prefs]);
    }
}
