<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

/**
 * Attachment bytes in AICOUNTLY Drive.
 *
 * A thin face over {@see DriveAttachmentService} so the rest of the app sees
 * one storage interface and never a feature flag. Every method there begins
 * with `Features::require(Features::DRIVE)`, so this class cannot half-work on
 * a deployment where Drive is switched off: it answers FEATURE_DISABLED rather
 * than writing bytes somewhere nobody expects them.
 *
 * Note the split of responsibilities with `drive_file_id` on the attachment
 * row: a file *uploaded through Notes* is an object this store owns and
 * addresses by an allocated key, while a file *linked from Drive* is the
 * user's own file, addressed by its Drive id and never copied.
 */
final class DriveObjectStore implements ObjectStore
{
    public function __construct(private readonly DriveAttachmentService $drive = new DriveAttachmentService())
    {
    }

    public function name(): string
    {
        return 'drive';
    }

    public function allocateKey(): string
    {
        // Namespaced: Drive holds objects for every AICOUNTLY product.
        return 'notes/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(16));
    }

    public function put(string $key, string $bytes, string $mimeType): void
    {
        $this->drive->putObject($key, $bytes, $mimeType);
    }

    public function get(string $key): string
    {
        return $this->drive->getObject($key);
    }

    public function stream(string $key): void
    {
        // Drive answers over HTTP, so there is no incremental read to make: the
        // body has already arrived by the time it can be written out.
        echo $this->drive->getObject($key);
    }

    public function delete(string $key): bool
    {
        return $this->drive->deleteObject($key);
    }

    public function exists(string $key): bool
    {
        return $this->drive->objectExists($key);
    }

    public function signedUrl(string $key, int $ttlSeconds): ?string
    {
        return $this->drive->objectSignedUrl($key, $ttlSeconds);
    }
}
