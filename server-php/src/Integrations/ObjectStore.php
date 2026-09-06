<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

/**
 * Where attachment bytes live.
 *
 * Bytes are never stored in PostgreSQL — a 25 MB scan inside a row would be
 * read, copied and vacuumed by every query that touches the note, and the
 * database backup would grow by the size of everyone's photo library. The
 * database holds the *relationship* (note ↔ file) and this interface holds the
 * file.
 *
 * Two implementations: {@see DriveObjectStore} when AICOUNTLY Drive is
 * configured, {@see LocalObjectStore} when it is not. Both are addressed by an
 * opaque key the store itself allocates, so nothing above this interface ever
 * builds a path or a URL out of a filename a user chose.
 */
interface ObjectStore
{
    /** The value written to `note_attachments.storage_provider`. */
    public function name(): string;

    /**
     * A fresh, non-guessable address for one new object.
     *
     * The key is deliberately unrelated to the filename: a key that embedded
     * "Board pack Q3.pdf" would leak the document's subject to anyone who ever
     * saw a path, and a key that could be guessed would make the store itself
     * an access-control decision.
     */
    public function allocateKey(): string;

    public function put(string $key, string $bytes, string $mimeType): void;

    /** The whole object. Throws when it is not there. */
    public function get(string $key): string;

    /**
     * Write the object to PHP's output stream.
     *
     * Separate from `get()` so a download does not have to hold the whole file
     * in a PHP variable in addition to whatever buffer is carrying it out.
     */
    public function stream(string $key): void;

    /** True when the object was there and is now gone. Deleting twice is not an error. */
    public function delete(string $key): bool;

    public function exists(string $key): bool;

    /**
     * A short-lived URL the browser may fetch directly, or null when this store
     * has no such thing.
     *
     * Null is the honest answer for the local store: its directory is denied to
     * the web server on purpose, so there is no URL to hand out and the API
     * streams the bytes itself.
     */
    public function signedUrl(string $key, int $ttlSeconds): ?string;
}
