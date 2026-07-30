<?php

namespace App\Service\Tmdb;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin TMDB API v3 client. Prefers Authorization: Bearer read-access token;
 * falls back to api_key query param. See https://developer.themoviedb.org/
 *
 * Rate limit: 40 requests per 10 seconds (TMDB documented ceiling).
 */
final class TmdbClient
{
    private const BASE = 'https://api.themoviedb.org/3';
    private const RATE_WINDOW_US = 10_000_000; // 10 seconds

    /** @var list<int> microtimestamps of recent successful/attempted calls */
    private array $requestTimes = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $readAccessToken = '',
        private readonly string $apiKey = '',
        /**
         * Requests allowed per ten seconds. TMDB's old documented ceiling was
         * 40; they have since moved to a much higher per-second limit, so this
         * is deliberately conservative and worth raising if a long crawl needs
         * to finish sooner — it is the only thing setting the pace.
         */
        private readonly int $requestsPerWindow = 40,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->readAccessToken) || '' !== trim($this->apiKey);
    }

    /**
     * Fetches several paths with requests in flight at the same time.
     *
     * One request at a time is bounded by round-trip latency, not by TMDB's
     * limit: at ~290 ms per call that is about four a second however generous
     * the allowance. Sending a window of them concurrently turns the same
     * allowance into real throughput — the rate limiter still governs how fast
     * they leave, so this goes faster without going harder.
     *
     * @param array<string|int, array{path: string, query?: array<string, scalar|null>}> $requests
     *
     * @return array<string|int, array<string, mixed>|null> keyed like $requests; null for 404s
     *
     * @throws TmdbAuthException
     * @throws TmdbRateLimitedException
     */
    public function getMany(array $requests): array
    {
        if (!$this->isConfigured()) {
            throw new TmdbAuthException('Missing TMDB_API_READ_ACCESS_TOKEN or TMDB_API_KEY.');
        }

        $pending = [];
        foreach ($requests as $key => $request) {
            // Each one still passes the rate limiter before it leaves.
            $this->throttle();
            $pending[$key] = $this->httpClient->request('GET', self::BASE.$request['path'], [
                'headers' => $this->headers(),
                'query' => $this->queryFor($request['query'] ?? []),
            ]);
        }

        $results = [];
        $retry = [];

        foreach ($pending as $key => $response) {
            try {
                $status = $response->getStatusCode();

                if (401 === $status || 403 === $status) {
                    throw new TmdbAuthException(sprintf('TMDB rejected credentials (HTTP %d).', $status));
                }
                if (404 === $status) {
                    $results[$key] = null;
                    continue;
                }
                if (429 === $status || $status >= 500) {
                    // Anything throttled or broken goes round again, one at a time.
                    $retry[$key] = $requests[$key];
                    continue;
                }

                $results[$key] = $response->toArray(false);
            } catch (TmdbAuthException $e) {
                throw $e;
            } catch (\Throwable) {
                $retry[$key] = $requests[$key];
            }
        }

        if ([] !== $retry) {
            // A full window of quiet, then the slow path with its own retries.
            usleep(self::RATE_WINDOW_US);
            foreach ($retry as $key => $request) {
                $results[$key] = $this->get($request['path'], $request['query'] ?? []);
            }
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/json'];
        if ('' !== trim($this->readAccessToken)) {
            $headers['Authorization'] = 'Bearer '.trim($this->readAccessToken);
        }

        return $headers;
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, scalar>
     */
    private function queryFor(array $query): array
    {
        $query['language'] = $query['language'] ?? 'en-US';
        if ('' === trim($this->readAccessToken)) {
            $query['api_key'] = trim($this->apiKey);
        }

        return array_filter($query, static fn ($value) => null !== $value && '' !== $value);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>|null null on 404
     *
     * @throws TmdbAuthException
     * @throws TmdbRateLimitedException
     * @throws \RuntimeException
     */
    public function get(string $path, array $query = []): ?array
    {
        if (!$this->isConfigured()) {
            throw new TmdbAuthException('Missing TMDB_API_READ_ACCESS_TOKEN or TMDB_API_KEY.');
        }

        $headers = [
            'Accept' => 'application/json',
        ];
        if ('' !== trim($this->readAccessToken)) {
            $headers['Authorization'] = 'Bearer '.trim($this->readAccessToken);
        } else {
            $query['api_key'] = trim($this->apiKey);
        }

        $query['language'] = $query['language'] ?? 'en-US';

        $url = self::BASE.$path;
        $lastStatus = 0;

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->throttle();

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => $headers,
                    'query' => array_filter(
                        $query,
                        static fn ($v) => null !== $v && '' !== $v,
                    ),
                ]);
                $lastStatus = $response->getStatusCode();
            } catch (TransportExceptionInterface $e) {
                if (3 === $attempt) {
                    throw new TmdbRateLimitedException('TMDB transport error: '.$e->getMessage(), 0, $e);
                }
                usleep(1500 * $attempt * 1000);
                continue;
            }

            if (401 === $lastStatus || 403 === $lastStatus) {
                throw new TmdbAuthException(sprintf('TMDB rejected credentials (HTTP %d).', $lastStatus));
            }

            if (404 === $lastStatus) {
                return null;
            }

            if (429 === $lastStatus || $lastStatus >= 500) {
                if (3 === $attempt) {
                    throw new TmdbRateLimitedException(sprintf('TMDB HTTP %d after retries: %s', $lastStatus, $path));
                }
                // Back off a full window on 429.
                usleep(self::RATE_WINDOW_US);
                continue;
            }

            if ($lastStatus < 200 || $lastStatus >= 300) {
                throw new \RuntimeException(sprintf('TMDB HTTP %d: %s', $lastStatus, $path));
            }

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);

            return $data;
        }

        throw new TmdbRateLimitedException(sprintf('TMDB failed: %s', $path));
    }

    /** Sleep until we are under the allowance for the last ten seconds. */
    private function throttle(): void
    {
        $now = (int) (microtime(true) * 1_000_000);

        $this->requestTimes = array_values(array_filter(
            $this->requestTimes,
            static fn (int $t) => ($now - $t) < self::RATE_WINDOW_US,
        ));

        if (\count($this->requestTimes) >= $this->requestsPerWindow) {
            $oldest = $this->requestTimes[0];
            $wait = self::RATE_WINDOW_US - ($now - $oldest) + 5_000; // +5ms slack
            if ($wait > 0) {
                usleep($wait);
            }
            $now = (int) (microtime(true) * 1_000_000);
            $this->requestTimes = array_values(array_filter(
                $this->requestTimes,
                static fn (int $t) => ($now - $t) < self::RATE_WINDOW_US,
            ));
        }

        $this->requestTimes[] = (int) (microtime(true) * 1_000_000);
    }
}
