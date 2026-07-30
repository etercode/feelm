<?php

namespace App\Service\Tmdb;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mirrors TMDB CDN images into public/media/tmdb/ and returns site-relative paths.
 */
final class TmdbMediaStore
{
    private const CDN = 'https://image.tmdb.org/t/p/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Download a TMDB image URL (or pass through an already-local /media path).
     *
     * @return ?string site-relative path e.g. /media/tmdb/w500/xx/file.jpg
     */
    public function mirror(?string $remoteOrLocal): ?string
    {
        if (null === $remoteOrLocal || '' === $remoteOrLocal) {
            return null;
        }
        if (str_starts_with($remoteOrLocal, '/media/')) {
            return $remoteOrLocal;
        }

        if (!preg_match('#^https?://image\.tmdb\.org/t/p/([^/]+)(/[^?\s]+)#', $remoteOrLocal, $m)) {
            // Not a TMDB CDN URL — leave unchanged.
            return $remoteOrLocal;
        }

        $size = $m[1];
        $filePath = $m[2]; // /abcdef.jpg
        $basename = basename($filePath);
        if ('' === $basename || str_contains($basename, '..')) {
            return $remoteOrLocal;
        }

        $shard = substr(hash('sha256', $filePath), 0, 2);
        $relative = '/media/tmdb/'.$size.'/'.$shard.'/'.$basename;
        $absolute = $this->projectDir.'/public'.$relative;

        if (is_file($absolute) && filesize($absolute) > 0) {
            return $relative;
        }

        $dir = \dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create media dir: '.$dir);
        }

        $source = self::CDN.$size.$filePath;
        try {
            $response = $this->httpClient->request('GET', $source, [
                'headers' => ['Accept' => 'image/*,*/*'],
                'timeout' => 30,
            ]);
            if ($response->getStatusCode() >= 400) {
                return $remoteOrLocal;
            }
            $bytes = $response->getContent();
            if ('' === $bytes) {
                return $remoteOrLocal;
            }
            file_put_contents($absolute, $bytes);
        } catch (\Throwable) {
            return $remoteOrLocal;
        }

        return $relative;
    }

    public function isRemoteTmdb(?string $url): bool
    {
        return \is_string($url) && str_contains($url, 'image.tmdb.org/t/p/');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    /**
     * Downloads the artwork we want our own copy of and rewrites the row to
     * point at it. Anything not mirrored keeps its TMDB CDN url and still
     * displays — mirroring decides who serves the image, not whether there is
     * one.
     *
     * @param 'all'|'posters' $scope 'posters' skips backdrops and cast photos,
     *                               which is most of the bytes and most of the time
     */
    public function localizeItem(array $row, string $scope = 'all'): array
    {
        $row['poster'] = $this->mirror(isset($row['poster']) ? (string) $row['poster'] : null);

        if ('posters' === $scope) {
            return $row;
        }

        $row['backdrop'] = $this->mirror(isset($row['backdrop']) ? (string) $row['backdrop'] : null);

        if (isset($row['details']['cast']) && \is_array($row['details']['cast'])) {
            foreach ($row['details']['cast'] as $i => $person) {
                if (!\is_array($person)) {
                    continue;
                }
                $row['details']['cast'][$i]['photo'] = $this->mirror(
                    isset($person['photo']) ? (string) $person['photo'] : null,
                );
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $season
     *
     * @return array<string, mixed>
     */
    public function localizeSeason(array $season): array
    {
        $season['poster'] = $this->mirror(isset($season['poster']) ? (string) $season['poster'] : null);
        if (isset($season['episodes']) && \is_array($season['episodes'])) {
            foreach ($season['episodes'] as $i => $ep) {
                if (!\is_array($ep)) {
                    continue;
                }
                $season['episodes'][$i]['still'] = $this->mirror(
                    isset($ep['still']) ? (string) $ep['still'] : null,
                );
            }
        }

        return $season;
    }
}
