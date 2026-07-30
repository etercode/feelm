<?php

namespace App\Service;

/** Turns stored media paths into absolute URLs for the SPA (different origin). */
final class PublicUrlGenerator
{
    public function __construct(
        private readonly string $publicBaseUrl,
    ) {
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
