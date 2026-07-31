<?php

namespace App\Service\User;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Turns an uploaded portrait into a file the site can serve.
 *
 * Nothing the browser sends is trusted. The cropper on the front end already
 * produces a square, but the upload is decoded and redrawn here regardless:
 * that is what guarantees the result is an image at all, and it drops any EXIF
 * riding along — which for a photo taken on a phone means a home address.
 *
 * Files land in public/media/avatars, the volume nginx already serves at
 * /media, so the stored value is a path the front end can use directly.
 */
final class AvatarStorage
{
    /** Stored edge length. Displayed at 104px at the largest, doubled for retina. */
    private const SIZE = 512;

    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Pixel ceiling, checked from the header before anything is decoded. Eight
     * megabytes of PNG can describe a picture far larger than memory: the file
     * size limit above says nothing about what unpacking it costs.
     */
    private const MAX_PIXELS = 40_000_000;

    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the upload is not a usable image
     *
     * @return string the public path to store on the user
     */
    public function store(User $user, UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('upload_failed');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('file_too_large');
        }

        // Read from the file's own header rather than the declared MIME type,
        // which is whatever the browser felt like sending.
        $info = @getimagesize($file->getPathname());
        if (false === $info || !\in_array($info[2], self::TYPES, true)) {
            throw new \InvalidArgumentException('unsupported_type');
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('image_too_large');
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if (false === $source) {
            throw new \InvalidArgumentException('unreadable_image');
        }

        try {
            $square = $this->square($source);
        } finally {
            imagedestroy($source);
        }

        $directory = $this->projectDir.'/public/media/avatars';
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \InvalidArgumentException('storage_unavailable');
        }

        // The random half means a replaced portrait gets a new URL, so no cache
        // anywhere — browser, Cloudflare — keeps serving the old face.
        $name = \sprintf('%d-%s.jpg', (int) $user->getId(), bin2hex(random_bytes(6)));

        try {
            if (!imagejpeg($square, $directory.'/'.$name, 86)) {
                throw new \InvalidArgumentException('storage_unavailable');
            }
        } finally {
            imagedestroy($square);
        }

        $this->discard($user->getAvatar());

        return '/media/avatars/'.$name;
    }

    /** Removes the file behind a stored path, if it is one of ours and still there. */
    public function discard(?string $path): void
    {
        if (null === $path || !str_starts_with($path, '/media/avatars/')) {
            return;
        }

        // basename() rather than the path as given: a stored value should never
        // contain traversal, and unlink is not where that assumption is tested.
        $file = $this->projectDir.'/public/media/avatars/'.basename($path);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Centre-crops to a square and scales it to SIZE.
     *
     * @param \GdImage $source
     */
    private function square(\GdImage $source): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $edge = min($width, $height);

        $target = imagecreatetruecolor(self::SIZE, self::SIZE);

        // JPEG has no alpha, so anything transparent in a PNG or WebP would
        // come out black. Fill first and let the copy composite over white.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            intdiv($width - $edge, 2),
            intdiv($height - $edge, 2),
            self::SIZE,
            self::SIZE,
            $edge,
            $edge,
        );

        return $target;
    }
}
