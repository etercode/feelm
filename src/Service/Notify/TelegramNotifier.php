<?php

namespace App\Service\Notify;

use App\Repository\SettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends a line to a Telegram chat when something in the background finishes or
 * breaks.
 *
 * ---- why nothing here throws ------------------------------------------------
 *
 * Every caller is something that matters more than the notification: a backup,
 * the nightly crawl, an error already on its way to the log. A notifier that
 * can fail the thing it is reporting on is worse than no notifier, so every
 * path returns a bool and writes to the log instead of raising. The one place
 * that wants the reason is the admin's "send test message" button, which gets
 * it through `lastError()`.
 *
 * The timeout is deliberately short for the same reason. A backup script should
 * not sit for thirty seconds because api.telegram.org is having a bad night.
 */
class TelegramNotifier
{
    public const TOKEN = 'telegram.token';
    public const CHAT = 'telegram.chat';
    public const ENABLED = 'telegram.enabled';

    /**
     * The events an administrator can switch on and off, and what each covers.
     * A setting missing from the table means the event is on — a channel that
     * silently defaults to saying nothing is a channel nobody notices is broken.
     */
    public const EVENTS = [
        'backup' => 'Database backups',
        'nightly' => 'Nightly catalog run',
        'deploy' => 'Deploys',
        'error' => 'Errors that need attention',
    ];

    private ?string $lastError = null;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== (string) $this->settings->get(self::TOKEN)
            && '' !== (string) $this->settings->get(self::CHAT);
    }

    /**
     * The master switch only. Deliberately not also asking whether a token is
     * present: "you turned this off" and "this was never set up" are different
     * answers, and folding them together is what made a missing chat id report
     * itself as "switched off".
     */
    public function isEnabled(): bool
    {
        return $this->settings->bool(self::ENABLED, true);
    }

    /** Whether one particular kind of message is switched on. */
    public function wants(string $event): bool
    {
        return $this->isEnabled() && $this->settings->bool('telegram.event.'.$event, true);
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param string $event one of EVENTS, or 'test' which ignores the toggles
     */
    public function send(string $text, string $event = 'error'): bool
    {
        $this->lastError = null;

        // Configuration first, so a half-finished setup says which half.
        if (!$this->isConfigured()) {
            $this->lastError = '' === (string) $this->settings->get(self::TOKEN)
                ? 'No bot token is set.'
                : 'No chat is set. Message the bot, then pick the chat.';

            return false;
        }

        if ('test' !== $event && !$this->wants($event)) {
            return false;
        }

        try {
            $response = $this->http->request(
                'POST',
                'https://api.telegram.org/bot'.$this->settings->get(self::TOKEN).'/sendMessage',
                [
                    'json' => [
                        'chat_id' => $this->settings->get(self::CHAT),
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ],
                    'timeout' => 8,
                ],
            );

            if (200 === $response->getStatusCode()) {
                return true;
            }

            // Telegram explains itself in the body, and the description is the
            // difference between "wrong token" and "bot was never started by
            // this chat" — which is the mistake everybody makes first.
            $body = $response->toArray(false);
            $this->lastError = \is_string($body['description'] ?? null)
                ? $body['description']
                : 'Telegram answered '.$response->getStatusCode().'.';
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        $this->logger->warning('Telegram notification failed: {reason}', ['reason' => $this->lastError]);

        return false;
    }

    /** The bot's own @name, so the admin can say which bot it is talking to. */
    public function whoAmI(): ?string
    {
        $token = (string) $this->settings->get(self::TOKEN);
        if ('' === $token) {
            return null;
        }

        try {
            $body = $this->http
                ->request('GET', 'https://api.telegram.org/bot'.$token.'/getMe', ['timeout' => 8])
                ->toArray(false);

            return \is_string($body['result']['username'] ?? null) ? $body['result']['username'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Chats that have spoken to the bot, so the admin does not have to go
     * hunting for a numeric id.
     *
     * A bot cannot open a conversation — Telegram only lets it reply to a chat
     * that has messaged it first. That is the step everybody misses, so rather
     * than asking for an id this offers the ones that already exist: send the
     * bot /start, press the button, pick the chat.
     *
     * getUpdates only holds the last 24 hours, and it returns nothing at all
     * while a webhook is set. Both are worth saying out loud in the UI.
     *
     * @return list<array{id: string, type: string, name: string}>
     */
    public function findChats(): array
    {
        $this->lastError = null;
        $token = (string) $this->settings->get(self::TOKEN);

        if ('' === $token) {
            $this->lastError = 'Set the bot token first.';

            return [];
        }

        try {
            $body = $this->http
                ->request('GET', 'https://api.telegram.org/bot'.$token.'/getUpdates', ['timeout' => 10])
                ->toArray(false);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }

        if (true !== ($body['ok'] ?? false)) {
            $this->lastError = \is_string($body['description'] ?? null)
                ? $body['description']
                : 'Telegram refused the request.';

            return [];
        }

        $chats = [];
        foreach ((array) ($body['result'] ?? []) as $update) {
            $message = $update['message'] ?? $update['channel_post'] ?? $update['my_chat_member'] ?? [];
            $chat = $message['chat'] ?? null;
            if (!\is_array($chat) || !isset($chat['id'])) {
                continue;
            }

            $chats[(string) $chat['id']] = [
                'id' => (string) $chat['id'],
                'type' => (string) ($chat['type'] ?? 'private'),
                'name' => (string) ($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? $chat['id']),
            ];
        }

        return array_values($chats);
    }

    /**
     * A message with a heading, for the scripts.
     *
     * @param array<string, string> $facts
     */
    public function report(string $title, bool $ok, array $facts = [], string $event = 'error'): bool
    {
        $lines = [($ok ? '✅ ' : '🚨 ').'<b>'.self::escape($title).'</b>'];

        foreach ($facts as $label => $value) {
            if ('' === (string) $value) {
                continue;
            }
            $lines[] = self::escape($label).': <code>'.self::escape($value).'</code>';
        }

        return $this->send(implode("\n", $lines), $event);
    }

    /** Telegram's HTML mode only knows these three, and unescaped they break the message. */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
