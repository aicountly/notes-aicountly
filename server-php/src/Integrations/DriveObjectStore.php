<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;

/**
 * Attachment bytes in AICOUNTLY Drive.
 *
 * The {@see ObjectStore} face over {@see DriveDocumentService}, so the rest of
 * the app sees one storage interface and never a feature flag. Two things about
 * Drive make this more than a thin wrapper, and both are visible in the methods
 * below.
 *
 * **Drive allocates the address, not this store.** A local object goes wherever
 * it is told. A Drive object's key is built by Drive out of tenant, product,
 * module and entity, and the document that names it only exists once the upload
 * has been scanned and promoted — so {@see allocateKey()} has nothing to
 * allocate, and {@see put()} answers with the document id it was given. That id
 * is what `note_attachments.storage_key` holds for a Drive-stored file.
 *
 * **Every call is made as the person who asked.** Notes runs Drive's sequence in
 * proxy mode with the caller's own ses_key forwarded, so Drive applies its own
 * permissions rather than trusting this API's word (§9 of Drive's architecture
 * doc). That session lives in the {@see DriveContext} this store is constructed
 * with. A store built without one — a cron worker, say — refuses rather than
 * inventing a caller: see {@see context()}.
 *
 * Note the split of responsibilities with `drive_file_id` on the attachment row.
 * A file *uploaded through Notes* is a document Notes created and may delete; a
 * file *linked from Drive* is the user's own, and Notes only ever references it.
 * Both are addressed by a Drive document id; only the first is Notes' to remove.
 */
final class DriveObjectStore implements ObjectStore
{
    public function __construct(
        private readonly ?DriveContext $context = null,
        private readonly DriveDocumentService $drive = new DriveDocumentService(),
    ) {
    }

    public function name(): string
    {
        return 'drive';
    }

    /**
     * Nothing to allocate.
     *
     * Drive builds the object key from the upload session and names the
     * document at finalize, so an address invented here would be fiction.
     * {@see put()} returns the real one.
     */
    public function allocateKey(): string
    {
        return '';
    }

    /**
     * Run the upload sequence and answer with Drive's document id.
     *
     * `$key` is ignored — see {@see allocateKey()}. The filename is what the
     * file will be known by in Drive, and it is what Drive's scanner checks the
     * declared content type against, so it is passed through rather than
     * replaced with something generic.
     */
    public function put(string $key, string $bytes, string $mimeType, string $filename = ''): string
    {
        unset($key);

        return $this->drive->store(
            $this->context(),
            $filename !== '' ? $filename : self::fallbackName($mimeType),
            $bytes,
            $mimeType,
        );
    }

    public function get(string $key): string
    {
        return $this->drive->fetch($this->context(), self::documentId($key));
    }

    /**
     * Write the object to PHP's output stream.
     *
     * Kept for the callers that genuinely need bytes in this process. It is
     * *not* how a download is served: {@see signedUrl()} is, and the download
     * endpoint prefers it, so megabytes do not pass through PHP twice.
     */
    public function stream(string $key): void
    {
        echo $this->get($key);
    }

    public function delete(string $key): bool
    {
        return $this->drive->delete($this->context(), self::documentId($key));
    }

    public function exists(string $key): bool
    {
        return $this->drive->exists($this->context(), self::documentId($key));
    }

    /**
     * A short-lived presigned GET straight from the private bucket.
     *
     * Drive re-checks the caller's permission before issuing it and keeps it
     * brief; the `$ttlSeconds` this API would like is not Drive's to take
     * instruction on, so it is not sent.
     */
    public function signedUrl(string $key, int $ttlSeconds): ?string
    {
        unset($ttlSeconds);

        return $this->drive->signedUrl($this->context(), self::documentId($key));
    }

    // -----------------------------------------------------------------------

    /**
     * The session this store acts under.
     *
     * A Drive call has to be made as somebody. Proxy mode means the caller's
     * ses_key, and there is no honest substitute for a process that has none —
     * a shared service token would make every Notes user as powerful as the
     * integration itself. So a store built without a context says so, in the
     * same voice {@see AicountlyClient} uses for the same situation, rather than
     * failing later with something that reads like Drive's fault.
     */
    private function context(): DriveContext
    {
        Features::require(Features::DRIVE);

        if ($this->context === null) {
            throw ApiException::unauthenticated('This action needs your AICOUNTLY session.');
        }

        return $this->context;
    }

    /**
     * The stored key, as a Drive document id.
     *
     * A key that has been emptied by the purge job is not a document id, and
     * asking Drive about `''` would be a request for `/documents//download`.
     */
    private static function documentId(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw ApiException::notFound('That file');
        }

        return $key;
    }

    /** A name for bytes nobody named — a generated thumbnail, say. */
    private static function fallbackName(string $mimeType): string
    {
        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'bin',
        };

        return 'file.' . $extension;
    }
}
