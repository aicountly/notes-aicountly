<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs\Handlers;

use Aicountly\Api\Domain\Attachments\AttachmentService;
use Aicountly\Api\Domain\Jobs\JobHandler;

/**
 * A small preview of an image, made with ext-gd.
 *
 * Real work, done locally: nothing outside this server is needed to resize a
 * JPEG, so there is no feature flag and no adapter here. The two things worth
 * knowing:
 *
 *   - **Dimensions are read before the image is decoded.** A 40-megapixel
 *     source is a few hundred kilobytes on disk and roughly 160 MB once GD has
 *     it in memory, which is how a decompression bomb takes a shared host down.
 *     `getimagesizefromstring()` costs nothing and answers first.
 *   - **"We cannot read this format" is never reported as "this is not an
 *     image."** HEIC is what an iPhone camera produces and PHP has no reader
 *     for it at all; TIFF has none in GD. Both make `getimagesizefromstring()`
 *     answer `false` — exactly as a truncated PNG would — and calling that a
 *     corrupt file would put a red "failed" on a perfectly good photograph.
 *     What the build can decode is asked of the build, up front.
 *   - **The thumbnail is a separate object with its own key.** It is not
 *     derived from the original's key, so nothing can be reached by guessing at
 *     a suffix, and the purge job deletes both keys from the row it reads.
 */
final class ThumbnailHandler implements JobHandler
{
    /** Longest edge. Big enough for a retina card, small enough to be free. */
    private const MAX_EDGE = 480;

    /** Above this, resizing costs more memory than a preview is worth. */
    private const MAX_PIXELS = 40000000;

    private const JPEG_QUALITY = 82;

    public function __construct(private readonly AttachmentService $attachments = new AttachmentService())
    {
    }

    public function handle(array $job): array
    {
        $attachmentId = $job['attachment_id'] ?? null;
        $attachment = is_string($attachmentId) ? $this->attachments->find($attachmentId) : null;
        // A note deleted while its jobs were queued takes its attachments with
        // it; there is nothing left to make a preview of, ever.
        if ($attachment === null || $attachment['deleted_at'] !== null) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'attachment_missing'];
        }
        if ((string) $attachment['kind'] !== 'image') {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'unsupported_mime'];
        }
        if (!extension_loaded('gd')) {
            // An honest disabled state rather than a failure: the deployment
            // simply cannot make previews, and no number of retries changes it.
            return ['outcome' => self::SKIPPED, 'reason' => 'gd_unavailable'];
        }
        if (!self::gdCanDecode((string) $attachment['mime_type'])) {
            // Asked before the bytes are fetched, because the answer does not
            // depend on them: reading a 12 MB photo off the store to discover
            // this build has no decoder for its format is work nobody asked
            // for, and the file is not at fault either way.
            return ['outcome' => self::SKIPPED, 'reason' => 'format_unsupported_by_gd'];
        }

        $store = $this->attachments->storeFor($attachment);
        $bytes = $store->get((string) $attachment['storage_key']);

        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            // A format this build *does* read, that still would not parse —
            // so the bytes really are not the picture they claim to be.
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'not_an_image'];
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];
        if ($width < 1 || $height < 1) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'not_an_image'];
        }
        if ($width * $height > self::MAX_PIXELS) {
            // The dimensions are still worth recording — the UI can reserve the
            // right space for the image even without a preview of it.
            $this->attachments->recordMedia((string) $attachment['id'], ['width' => $width, 'height' => $height]);

            return ['outcome' => self::SKIPPED, 'reason' => 'image_too_large', 'width' => $width, 'height' => $height];
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            // A format GD was not built with (HEIC, AVIF on most hosts) is a
            // property of the deployment, not of the file.
            return ['outcome' => self::SKIPPED, 'reason' => 'format_unsupported_by_gd'];
        }

        try {
            $scale = min(1.0, self::MAX_EDGE / max($width, $height));
            $thumbWidth = max(1, (int) round($width * $scale));
            $thumbHeight = max(1, (int) round($height * $scale));

            $thumbnail = imagescale($image, $thumbWidth, $thumbHeight, IMG_BICUBIC);
            if ($thumbnail === false) {
                return ['outcome' => self::SKIPPED, 'reason' => 'resize_failed'];
            }

            $keepsAlpha = in_array((string) $attachment['mime_type'], ['image/png', 'image/gif', 'image/webp'], true);
            $encoded = $this->encode($thumbnail, $keepsAlpha);
            imagedestroy($thumbnail);
        } finally {
            imagedestroy($image);
        }

        if ($encoded === null) {
            return ['outcome' => self::SKIPPED, 'reason' => 'encode_failed'];
        }

        // The key comes back from the write: a store that allocates its own
        // addresses — Drive does — only names the object once it has it.
        $thumbnailKey = $store->put(
            $store->allocateKey(),
            $encoded,
            $keepsAlpha ? 'image/png' : 'image/jpeg',
            'thumbnail.' . ($keepsAlpha ? 'png' : 'jpg'),
        );

        $this->attachments->recordMedia((string) $attachment['id'], [
            'width' => $width,
            'height' => $height,
            'thumbnail_key' => $thumbnailKey,
        ]);

        return [
            'outcome' => self::COMPLETED,
            'width' => $width,
            'height' => $height,
            'thumbnail_bytes' => strlen($encoded),
        ];
    }

    /**
     * Can this build read this format at all?
     *
     * `imagetypes()` answers for the deployment rather than for the file, which
     * is the question worth asking: a host built without WebP and a host
     * without an AVIF decoder are ordinary, and so is one meeting a `.heic`
     * that no PHP build can open. Anything not on this list has no GD reader,
     * so there is nothing to try.
     */
    private static function gdCanDecode(string $mimeType): bool
    {
        $bit = match ($mimeType) {
            'image/jpeg' => IMG_JPG,
            'image/png' => IMG_PNG,
            'image/gif' => IMG_GIF,
            'image/webp' => IMG_WEBP,
            'image/bmp' => IMG_BMP,
            // Added in PHP 8.1, and only when GD was built with libavif.
            'image/avif' => defined('IMG_AVIF') ? IMG_AVIF : 0,
            // HEIC, HEIF and TIFF: no reader in GD, on any build.
            default => 0,
        };

        return $bit !== 0 && (imagetypes() & $bit) === $bit;
    }

    /** Encode through a memory stream rather than an output buffer: the worker's stdout is a log. */
    private function encode(\GdImage $image, bool $keepsAlpha): ?string
    {
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return null;
        }

        try {
            if ($keepsAlpha) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $ok = imagepng($image, $stream, 6);
            } else {
                $ok = imagejpeg($image, $stream, self::JPEG_QUALITY);
            }
            if (!$ok) {
                return null;
            }
            rewind($stream);
            $encoded = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        return $encoded === false || $encoded === '' ? null : $encoded;
    }
}
