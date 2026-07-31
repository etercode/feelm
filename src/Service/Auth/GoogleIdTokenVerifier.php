<?php

namespace App\Service\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks that an ID token really came from Google, and really was meant for us.
 *
 * The token is a JWT signed by Google. Verifying it means four things, and all
 * four matter:
 *
 *   - the signature matches one of Google's current public keys
 *   - `iss` is Google
 *   - `aud` is OUR client id — a token minted for some other site is a valid
 *     Google token, and accepting one would let that site's operator sign in
 *     as any of our users
 *   - it has not expired
 *
 * The library checks the signature and the expiry; the other two are checked
 * here, because JWT::decode() does not know what it is verifying for.
 *
 * Keys are fetched from Google and cached. Google rotates them, so this cannot
 * be pinned to a fixed set, but re-fetching per sign-in would put Google in the
 * path of every login.
 */
final class GoogleIdTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** Google's keys are long-lived; an hour keeps a rotation from locking us out. */
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::GOOGLE_CLIENT_ID)%')]
        private readonly ?string $clientId = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->clientId && '' !== $this->clientId;
    }

    /**
     * @throws \RuntimeException when the token is not a usable Google identity
     *
     * @return array{sub: string, email: string|null, emailVerified: bool, name: string|null, picture: string|null}
     */
    public function verify(string $idToken): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('google_not_configured');
        }

        try {
            $claims = (array) JWT::decode($idToken, $this->keys());
        } catch (\Throwable $e) {
            // The reason is useful to us and not to the caller: an attacker
            // should not learn whether it was the signature or the clock.
            $this->logger->info('Rejected a Google ID token: '.$e->getMessage());

            throw new \RuntimeException('invalid_token', 0, $e);
        }

        if (!\in_array((string) ($claims['iss'] ?? ''), self::ISSUERS, true)) {
            throw new \RuntimeException('invalid_issuer');
        }

        if (($claims['aud'] ?? null) !== $this->clientId) {
            throw new \RuntimeException('invalid_audience');
        }

        $subject = (string) ($claims['sub'] ?? '');
        if ('' === $subject) {
            throw new \RuntimeException('invalid_token');
        }

        return [
            'sub' => $subject,
            'email' => isset($claims['email']) ? mb_strtolower(trim((string) $claims['email'])) : null,
            // Google sends this as a real boolean, but has historically sent
            // the string "true" as well. Anything else counts as unverified.
            'emailVerified' => true === $claims['email_verified'] || 'true' === ($claims['email_verified'] ?? null),
            'name' => isset($claims['name']) ? trim((string) $claims['name']) : null,
            'picture' => isset($claims['picture']) ? (string) $claims['picture'] : null,
        ];
    }

    /**
     * @return array<string, \Firebase\JWT\Key>
     */
    private function keys(): array
    {
        $certs = $this->cache->get('google_oauth_certs', function ($item) {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->httpClient
                ->request('GET', self::CERTS_URL, ['timeout' => 10])
                ->toArray();
        });

        return JWK::parseKeySet($certs);
    }
}
