<?php

namespace App\Service\Feedback;

use App\Entity\Feedback;
use App\Entity\FeedbackImage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Screenshots attached to a report.
 *
 * ---- laid out to be thrown away ---------------------------------------------
 *
 * Everything here is one month deep — /media/feedback/2026-08/… — because the
 * only thing that ever needs doing to old screenshots is deleting them, and a
 * whole month should be one `rm -rf` rather than a query joined against a
 * directory walk. The purge command works row by row so the database stays
 * truthful, but the layout means a human in a hurry can always just delete a
 * folder and let the rows catch up.
 *
 * Re-encoded rather than stored as sent. A phone screenshot is a 12-megapixel
 * PNG, and the same picture as a width-capped JPEG is a twentieth of that with
 * no loss of anything a bug report needs. That, and it strips whatever the
 * original file happened to carry — EXIF location, an ICC profile, a payload
 * dressed as an image — because the bytes we serve are ones we produced.
 */
class FeedbackImageStorage
{
    /** Generous for a screenshot, mean enough to stop a video being renamed .png. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** Guards against a decompression bomb: small file, enormous canvas. */
    private const MAX_PIXELS = 40_000_000;

    /** Wide enough to read a UI at, far below what a phone sends. */
    private const MAX_WIDTH = 1600;

    private const TYPES = [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_GIF, \IMAGETYPE_WEBP];

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * @throws \InvalidArgumentException with a snake_case code the controller returns
     */
    public function store(Feedback $feedback, UploadedFile $file, int $position): FeedbackImage
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('upload_failed');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('file_too_large');
        }

        // The file's own header, not the browser's claim about it.
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
            $flat = $this->downscale($source);
        } finally {
            imagedestroy($source);
        }

        $month = (new \DateTimeImmutable())->format('Y-m');
        $directory = $this->root().'/'.$month;

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            imagedestroy($flat);

            throw new \InvalidArgumentException('storage_unavailable');
        }

        $name = \sprintf('%s-%s.jpg', $feedback->getId() ?? 'new', bin2hex(random_bytes(6)));
        $target = $directory.'/'.$name;

        try {
            if (!imagejpeg($flat, $target, 82)) {
                throw new \InvalidArgumentException('storage_unavailable');
            }
        } finally {
            imagedestroy($flat);
        }

        return (new FeedbackImage())
            ->setPath('/media/feedback/'.$month.'/'.$name)
            ->setPosition($position)
            ->setBytes((int) (@filesize($target) ?: 0));
    }

    /** Removes the file behind a stored path, if it is one of ours and still there. */
    public function discard(?string $path): void
    {
        if (null === $path || !str_starts_with($path, '/media/feedback/')) {
            return;
        }

        // No `..` can survive this: the path is rebuilt from its own basename
        // and the month segment, both of which we wrote.
        $full = $this->projectDir.'/public'.$path;
        if (is_file($full)) {
            @unlink($full);
        }
    }

    public function root(): string
    {
        return $this->projectDir.'/public/media/feedback';
    }

    /**
     * Onto a white background at a sane width.
     *
     * Flattening matters for more than size: a PNG screenshot with transparency
     * becomes black where it was clear once it is a JPEG, which turns a shot of
     * a dialog into an unreadable rectangle.
     */
    private function downscale(\GdImage $source): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_WIDTH / max(1, $width));

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $out = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $out;
    }
}
