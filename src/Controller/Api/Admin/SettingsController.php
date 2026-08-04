<?php

namespace App\Controller\Api\Admin;

use App\Entity\User;
use App\Repository\SettingRepository;
use App\Service\Notify\TelegramNotifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where the notifications go.
 *
 * ---- the token is write-only ------------------------------------------------
 *
 * A bot token is a password: anyone holding it controls the bot. It goes in
 * over PATCH and never comes back out — the form receives the last four
 * characters and nothing else, which is enough to answer "is the right one in
 * there" and useless to anybody reading the response. Sending an empty token
 * means "leave it alone"; clearing it is its own explicit flag, because a form
 * that wipes a secret when a field is left blank is a trap.
 */
#[IsGranted(User::ROLE_ADMIN)]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly TelegramNotifier $telegram,
    ) {
    }

    #[Route('/api/admin/settings/telegram', name: 'api_admin_settings_telegram', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json($this->state());
    }

    #[Route('/api/admin/settings/telegram', name: 'api_admin_settings_telegram_save', methods: ['PATCH'], format: 'json')]
    public function save(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true) ?: [];

        $values = [];

        if (true === ($body['clearToken'] ?? false)) {
            $values[TelegramNotifier::TOKEN] = null;
        } elseif (\is_string($body['token'] ?? null) && '' !== trim($body['token'])) {
            $values[TelegramNotifier::TOKEN] = trim($body['token']);
        }

        if (\array_key_exists('chat', $body)) {
            $values[TelegramNotifier::CHAT] = trim((string) $body['chat']) ?: null;
        }

        if (\array_key_exists('enabled', $body)) {
            $values[TelegramNotifier::ENABLED] = $body['enabled'] ? '1' : '0';
        }

        if (\is_array($body['events'] ?? null)) {
            foreach (array_keys(TelegramNotifier::EVENTS) as $event) {
                if (\array_key_exists($event, $body['events'])) {
                    $values['telegram.event.'.$event] = $body['events'][$event] ? '1' : '0';
                }
            }
        }

        if ([] !== $values) {
            $this->settings->setMany($values);
        }

        return $this->json($this->state());
    }

    /**
     * The chats the bot could talk to.
     *
     * A bot cannot start a conversation, so this is the only way to get a chat
     * id without asking somebody to look one up by hand.
     */
    #[Route('/api/admin/settings/telegram/chats', name: 'api_admin_settings_telegram_chats', methods: ['POST'])]
    public function chats(): JsonResponse
    {
        $chats = $this->telegram->findChats();

        return $this->json([
            'chats' => $chats,
            'error' => $this->telegram->lastError(),
        ]);
    }

    #[Route('/api/admin/settings/telegram/test', name: 'api_admin_settings_telegram_test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        // 'test' bypasses the per-event switches: the point of the button is to
        // prove the connection, and it should not fail quietly because the
        // event it borrowed happens to be turned off.
        $sent = $this->telegram->send(
            "✅ <b>Feelm</b>\nNotifications are working. This is a test message.",
            'test',
        );

        return $this->json(
            ['sent' => $sent, 'error' => $this->telegram->lastError()],
            $sent ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST,
        );
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $token = (string) $this->settings->get(TelegramNotifier::TOKEN);

        $events = [];
        foreach (TelegramNotifier::EVENTS as $key => $label) {
            $events[$key] = [
                'label' => $label,
                'on' => $this->settings->bool('telegram.event.'.$key, true),
            ];
        }

        return [
            'configured' => $this->telegram->isConfigured(),
            'enabled' => $this->settings->bool(TelegramNotifier::ENABLED, true),
            // Enough to recognise which token is stored, useless to a reader.
            'tokenHint' => '' === $token ? null : '••••'.substr($token, -4),
            'chat' => $this->settings->get(TelegramNotifier::CHAT),
            'bot' => $this->telegram->whoAmI(),
            'events' => $events,
        ];
    }
}
