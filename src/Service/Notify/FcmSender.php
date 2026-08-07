<?php

namespace App\Service\Notify;

use App\Repository\DeviceTokenRepository;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to Firebase Cloud Messaging's HTTP v1 API.
 *
 * ---- why there is no Firebase SDK here --------------------------------------
 *
 * The official library exists to do two things: mint an OAuth2 access token
 * from a service account, and POST JSON. The first is a signed JWT, and
 * firebase/php-jwt is already a dependency for verifying Google sign-in. The
 * second is HttpClient. Pulling in google/apiclient and its transitive tree to
 * avoid forty lines is a worse trade than the forty lines.
 *
 * ---- why nothing here throws ------------------------------------------------
 *
 * Same rule as TelegramNotifier, for the same reason. Every caller is doing
 * something that matters more than the notification: somebody pressed follow,
 * or the nightly digest is halfway through a thousand users. A push that can
 * fail the action it is reporting on is worse than a push that is quietly
 * dropped and logged.
 *
 * ---- why dead tokens are deleted here, not by a sweeper ---------------------
 *
 * FCM answers UNREGISTERED the moment a token stops being valid — the app was
 * uninstalled, the data cleared, the phone wiped. That response is the only
 * reliable signal we ever get, and it arrives at exactly one place. Throwing it
 * away and hoping a nightly job works it out later means sending to the dead
 * for however long that takes.
 */
class FcmSender
{
    public const ENABLED = 'push.enabled';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Errors that mean "this token will never work again". Anything else — a
     * timeout, a 503, an INTERNAL — is FCM having a bad minute and the token
     * has to survive it, or one outage empties the table.
     */
    private const DEAD_TOKEN_ERRORS = [
        'UNREGISTERED',
        'INVALID_ARGUMENT',
        'SENDER_ID_MISMATCH',
    ];

    private ?string $lastError = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly DeviceTokenRepository $deviceTokens,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ?string $serviceAccountJson,
    ) {
    }

    /**
     * Whether push can work at all.
     *
     * Config comes from the environment rather than the settings table that
     * Telegram uses, because this one is a private key. An administrator who
     * can edit a bot token in a form is a different level of trust from one who
     * can paste in a credential that sends notifications to every phone.
     */
    public function isConfigured(): bool
    {
        return null !== $this->credentials();
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Deliver one message to one device.
     *
     * @param array<string, string> $data string values only — FCM rejects a
     *                                    data payload with anything else in it,
     *                                    including numbers
     *
     * @return bool whether FCM accepted it, which is not whether it was shown
     */
    public function send(
        string $token,
        string $title,
        string $body,
        array $data = [],
        ?string $collapseKey = null,
    ): bool {
        $this->lastError = null;

        $credentials = $this->credentials();
        if (null === $credentials) {
            $this->lastError = 'No FCM service account is configured.';

            return false;
        }

        $accessToken = $this->accessToken($credentials);
        if (null === $accessToken) {
            return false;
        }

        /*
         * Both halves on purpose. The `notification` block is drawn by the
         * system whether or not the app is allowed to run, which matters on the
         * aggressive OEM builds — a data-only message on HyperOS or EMUI may
         * never reach a process that has been frozen. The `data` block carries
         * the deep link and the key, and the app overrides the display with its
         * own copy when it is alive to do it.
         */
        $message = [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id' => $data['channel'] ?? 'general',
                    // Tapping opens the activity; the app reads the deep link
                    // out of the data payload rather than from an intent extra.
                    'click_action' => 'android.intent.action.MAIN',
                ],
            ],
        ];

        if (null !== $collapseKey) {
            // Two "Severance aired" pushes queued behind a dead connection
            // should arrive as one when the phone wakes, not as two.
            $message['android']['collapse_key'] = $collapseKey;
        }

        try {
            $response = $this->http->request(
                'POST',
                sprintf(
                    'https://fcm.googleapis.com/v1/projects/%s/messages:send',
                    $credentials['project_id'],
                ),
                [
                    'auth_bearer' => $accessToken,
                    'json' => ['message' => $message],
                    'timeout' => 10,
                ],
            );

            if (200 === $response->getStatusCode()) {
                return true;
            }

            $payload = $response->toArray(false);
            $status = (string) ($payload['error']['status'] ?? '');
            $this->lastError = (string) ($payload['error']['message'] ?? 'FCM answered '.$response->getStatusCode().'.');

            // Google reports a dead token as 404 UNREGISTERED, but a malformed
            // one as 400 INVALID_ARGUMENT — and the second is indistinguishable
            // from a bug in this payload, so the message goes to the log either
            // way before the row disappears.
            if (\in_array($status, self::DEAD_TOKEN_ERRORS, true)) {
                $this->logger->info('Dropping dead FCM token: {status} {reason}', [
                    'status' => $status,
                    'reason' => $this->lastError,
                ]);
                $this->deviceTokens->deleteByToken($token);

                return false;
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        $this->logger->warning('FCM send failed: {reason}', ['reason' => $this->lastError]);

        return false;
    }

    /**
     * An OAuth2 access token for the messaging scope.
     *
     * Cached for slightly less than the hour Google grants, because the digest
     * sends to every device in one run and re-signing a JWT per message would
     * be a round trip to Google's token endpoint per message.
     *
     * @param array{client_email: string, private_key: string, project_id: string} $credentials
     */
    private function accessToken(array $credentials): ?string
    {
        try {
            return $this->cache->get(
                'fcm.access_token.'.substr(sha1($credentials['client_email']), 0, 12),
                function (ItemInterface $item) use ($credentials): string {
                    $item->expiresAfter(3300);

                    $now = time();
                    $assertion = JWT::encode(
                        [
                            'iss' => $credentials['client_email'],
                            'scope' => self::SCOPE,
                            'aud' => self::TOKEN_URL,
                            'iat' => $now,
                            'exp' => $now + 3600,
                        ],
                        $credentials['private_key'],
                        'RS256',
                    );

                    $body = $this->http->request('POST', self::TOKEN_URL, [
                        'body' => [
                            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                            'assertion' => $assertion,
                        ],
                        'timeout' => 10,
                    ])->toArray(false);

                    $token = $body['access_token'] ?? null;
                    if (!\is_string($token) || '' === $token) {
                        throw new \RuntimeException(
                            \is_string($body['error_description'] ?? null)
                                ? $body['error_description']
                                : 'Google refused the service account assertion.',
                        );
                    }

                    return $token;
                },
            );
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logger->error('Could not mint an FCM access token: {reason}', [
                'reason' => $this->lastError,
            ]);

            return null;
        }
    }

    /**
     * The service account, parsed once.
     *
     * @return array{client_email: string, private_key: string, project_id: string}|null
     */
    private function credentials(): ?array
    {
        if (null === $this->serviceAccountJson || '' === trim($this->serviceAccountJson)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $parsed */
            $parsed = json_decode($this->serviceAccountJson, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->error('FCM_SERVICE_ACCOUNT is not valid JSON.');

            return null;
        }

        foreach (['client_email', 'private_key', 'project_id'] as $key) {
            if (!\is_string($parsed[$key] ?? null) || '' === $parsed[$key]) {
                $this->logger->error('FCM_SERVICE_ACCOUNT is missing {key}.', ['key' => $key]);

                return null;
            }
        }

        return [
            'client_email' => $parsed['client_email'],
            // Env files carry the key with literal \n rather than newlines,
            // which openssl rejects with an unhelpful "cannot read key".
            'private_key' => str_replace('\n', "\n", $parsed['private_key']),
            'project_id' => $parsed['project_id'],
        ];
    }
}
