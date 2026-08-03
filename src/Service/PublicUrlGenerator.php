<?php

namespace App\Service;

use App\Service\Media\ObjectStorage;

/** Turns stored media paths into absolute URLs for the SPA (different origin). */
final class PublicUrlGenerator
{
    public function __construct(
        private readonly string $publicBaseUrl,
        private readonly ObjectStorage $storage,
    ) {
    }

    /**
     * Our own copy if there is one, TMDB's otherwise.
     *
     * The fallback is the point of keeping both columns. A work mirrored last
     * night serves from the bucket; one the crawl added an hour ago serves from
     * TMDB until the next mirror run reaches it; one whose object was lost
     * serves from TMDB forever. None of those is a broken image, and none needs
     * the two to be migrated in step.
     */
    public function artwork(?string $mirroredKey, ?string $originalUrl): ?string
    {
        if (null !== $mirroredKey && '' !== $mirroredKey) {
            return $this->storage->url($mirroredKey);
        }

        return $this->media($originalUrl);
    }

    public function media(?string $pathOrUrl): ?string
    {
        if (null === $pathOrUrl || '' === $pathOrUrl) {
            return null;
        }
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }
        if (!str_starts_with($pathOrUrl, '/')) {
            $pathOrUrl = '/'.$pathOrUrl;
        }

        return rtrim($this->publicBaseUrl, '/').$pathOrUrl;
    }
}
