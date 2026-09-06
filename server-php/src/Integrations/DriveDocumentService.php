<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;

/**
 * Drive's upload-session sequence, run on the caller's behalf.
 *
 * ## Drive never receives the bytes
 *
 * This is the thing to understand before reading anything below, and the thing
 * an earlier version of this integration got wrong. A file does not arrive at
 * Drive by being POSTed to it. Drive is a **metadata service in front of an
 * object store**: it hands out a presigned S3 URL, the bytes go straight there,
 * and Drive is then told to promote them. Four calls, in this order
 * (`drive-react-app/docs/UPLOAD_SAVE_FLOW.md`):
 *
 *   1. `POST /upload-sessions` — declares the file and answers with a presigned
 *      PUT into the **quarantine** bucket.
 *   2. `PUT {upload_url}` — the bytes, **to S3, not to Drive**, and with no
 *      Authorization header on the request (see {@see AicountlyClient}).
 *   3. `POST /upload-sessions/{id}/complete` — the session is `uploaded`.
 *   4. `POST /upload-sessions/{id}/finalize` — Drive scans the object, hashes
 *      it, copies quarantine → private, deletes the quarantine copy and inserts
 *      its `documents` row. Only now does a document id exist.
 *
 * ## Why the abort matters, and where it stops
 *
 * Between step 1 and step 3 there is a session and, usually, an object sitting
 * in quarantine. A failure that just throws leaves both behind until Drive's
 * lifecycle cron notices. So a failure in steps 2 or 3 aborts the session,
 * which deletes the quarantine object immediately. The abort is best-effort and
 * never replaces the original error: if Drive cannot even be told to abort, the
 * caller still needs to hear why the upload failed.
 *
 * **Step 4 is not covered by it, on purpose.** Drive's `abortUpload()` deletes
 * whatever `storage_objects.object_key` names and never checks the session's
 * status, and finalize rewrites that same row to the promoted file — so an
 * abort after a finalize that actually succeeded deletes the user's file and
 * leaves a `documents` row pointing at nothing. Since a lost answer is
 * indistinguishable from a refusal here, step 4 never aborts. See the comment
 * on that call.
 *
 * ## What is sent, and why
 *
 * `sha256` on finalize is optional to Drive and sent anyway. Notes already
 * hashes the bytes — it stores the digest on `note_attachments.checksum_sha256`
 * — so sending it costs nothing and turns "Drive holds some bytes" into "Drive
 * holds *these* bytes": Drive compares it against the digest it computes from
 * the object it actually stored and rejects a mismatch. A truncated PUT that
 * returned 200 is caught here rather than discovered by the person who opens
 * the file next year.
 */
final class DriveDocumentService
{
    private const SERVICE = 'drive';

    private const SESSIONS = '/upload-sessions';
    private const LINKS = '/document-links';
    private const DOCUMENTS = '/documents';

    public function __construct(
        private readonly AicountlyClient $client = new AicountlyClient(
            self::SERVICE,
            Features::DRIVE,
            'drive',
        ),
    ) {
    }

    /**
     * Run the whole sequence and return the id of the document Drive created.
     *
     * @return string Drive's `documents.id`, which is what addresses the file
     *         from here on — there is no key of ours to keep.
     */
    public function store(
        DriveContext $context,
        string $filename,
        string $bytes,
        string $mimeType,
        string $title = '',
    ): string {
        Features::require(Features::DRIVE);

        $session = $this->client->send('POST', self::SESSIONS, $context->sesKey, [
            'filename' => $filename,
            'content_type' => $mimeType,
            'scope' => $context->scope,
            'product_code' => DriveContext::PRODUCT_CODE,
            'module_code' => $context->moduleCode,
            // The note is the business record these bytes belong to. Its UUID
            // is permanent from the moment it is created, so unlike Books —
            // which uploads against `draft-{id}` and later rebinds — Notes never
            // needs `POST /documents/{id}/rebind`.
            'entity_type' => DriveContext::ENTITY_TYPE,
            'entity_id' => $context->noteId,
            // Declared up front so Drive can refuse an oversized upload before
            // a byte moves. It re-counts at finalize regardless.
            'size_bytes' => strlen($bytes),
        ], $context->companyParams());

        $sessionId = self::sessionId($session);
        $uploadUrl = self::string($session, 'upload_url');
        if ($sessionId === '' || $uploadUrl === '') {
            throw ApiException::upstream(self::SERVICE, 'Drive did not offer anywhere to put the file.');
        }

        try {
            // Step 2. The one call in this class that does not go to Drive.
            $this->client->putBytes($uploadUrl, $bytes, $mimeType);

            $this->client->send(
                'POST',
                self::SESSIONS . '/' . AicountlyClient::segment($sessionId) . '/complete',
                $context->sesKey,
                null,
                $context->companyParams(),
            );
        } catch (\Throwable $e) {
            // Safe to abort: nothing has been promoted yet, so the only thing
            // to delete is the quarantine copy.
            $this->abort($context, $sessionId);

            throw $e;
        }

        try {
            // Step 4, deliberately OUTSIDE the abort guard.
            //
            // Abort is destructive after this point, and not in a way Drive
            // protects against. `DocumentService::abortUpload()` deletes
            // whatever `storage_objects.object_key` currently names and does
            // not look at the session's status — and finalize has by then
            // rewritten that same row to the private bucket and the final key.
            // So aborting a session Drive has already finalized deletes the
            // **promoted file**, leaving a `documents` row pointing at nothing.
            //
            // And this API cannot tell a finalize that failed from one that
            // succeeded with the answer lost. A timeout says nothing. Drive
            // returns 500 from work that runs *after* its commit — the storage
            // audit log, then re-reading the document — so even a definite 5xx
            // can carry a document that exists. There is no
            // `GET /upload-sessions/{id}` to ask.
            //
            // So it is never aborted here. The cost of not aborting a finalize
            // that genuinely failed is a quarantine object left for Drive's
            // lifecycle cron, which is exactly what Drive's own failure path
            // arranges (it sets `quarantine_purge_at` and moves on). The cost
            // of aborting one that succeeded is the user's file.
            $document = $this->client->send(
                'POST',
                self::SESSIONS . '/' . AicountlyClient::segment($sessionId) . '/finalize',
                $context->sesKey,
                [
                    'title' => $title !== '' ? $title : $filename,
                    'size_bytes' => strlen($bytes),
                    // The verification, not a claim Drive is asked to trust:
                    // Drive stores its own digest either way and rejects this
                    // one if the two disagree.
                    'sha256' => hash('sha256', $bytes),
                ],
                $context->companyParams(),
            );
        } catch (\Throwable $e) {
            // Logged with the session id because this is the one failure a
            // person may have to reconcile by hand: the document may exist in
            // Drive with no attachment row in Notes pointing at it.
            Logger::warn('drive.finalize_failed', [
                'session_id' => $sessionId,
                'note_id' => $context->noteId,
                'error' => get_debug_type($e),
            ]);

            throw $e;
        }

        $documentId = self::documentId($document);
        if ($documentId === '') {
            // Finalized, but this API cannot address what was created. Aborting
            // now would be wrong — the document exists and the quarantine copy
            // is already gone — so the failure is reported as what it is.
            Logger::error('drive.finalize_without_document_id', []);
            throw ApiException::upstream(self::SERVICE, 'Drive stored the file but did not say where.');
        }

        $this->link($context, $documentId);

        return $documentId;
    }

    /**
     * Tell Drive which business record this document belongs to.
     *
     * Step 6 of Drive's onboarding pattern (§29). Without it the file exists but
     * nothing outside Notes knows what it is attached to: Drive's own document
     * manager shows an orphan, and a future "where is this note's evidence"
     * query has nothing to join on. The object key already carries the note id,
     * but a key is a storage detail, not a queryable relationship.
     *
     * Best-effort on purpose. The bytes are stored and the document exists by
     * the time this runs; failing the upload over a missing cross-reference
     * would throw away a file the user successfully uploaded. Drive's endpoint
     * is idempotent (`ON CONFLICT DO NOTHING`), so a retry later is safe.
     */
    private function link(DriveContext $context, string $documentId): void
    {
        try {
            $this->client->send('POST', self::LINKS, $context->sesKey, [
                'doc_id' => $documentId,
                'product_code' => DriveContext::PRODUCT_CODE,
                'external_record_type' => DriveContext::ENTITY_TYPE,
                'external_record_id' => $context->noteId,
            ], $context->companyParams());
        } catch (\Throwable $e) {
            Logger::warn('drive.link_failed', [
                'document_id' => $documentId,
                'error' => get_debug_type($e),
            ]);
        }
    }

    /**
     * Drop the cross-reference when an attachment is detached.
     *
     * Also best-effort, and also not the same as deleting the document: a user
     * removing an attachment from a note is not asking Drive to destroy the
     * file, which may be linked from elsewhere or wanted in their document
     * manager.
     */
    public function unlink(DriveContext $context, string $documentId): void
    {
        try {
            $links = $this->client->send(
                'GET',
                self::LINKS,
                $context->sesKey,
                null,
                $context->companyParams() + [
                    'product_code' => DriveContext::PRODUCT_CODE,
                    'external_record_id' => $context->noteId,
                ],
            );

            foreach (is_array($links) ? $links : [] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $linkId = self::string($link, 'id');
                $linked = self::string($link, 'document_id') ?: self::string($link, 'doc_id');
                if ($linkId !== '' && $linked === $documentId) {
                    $this->client->send(
                        'DELETE',
                        self::LINKS . '/' . AicountlyClient::segment($linkId),
                        $context->sesKey,
                        null,
                        $context->companyParams(),
                    );
                }
            }
        } catch (\Throwable $e) {
            Logger::warn('drive.unlink_failed', [
                'document_id' => $documentId,
                'error' => get_debug_type($e),
            ]);
        }
    }

    /**
     * Put a document Notes created into Drive's trash.
     *
     * Detaching an attachment should not leave its bytes in Drive for ever
     * with nothing pointing at them — the user removed the file, and the only
     * reason it existed was this note. But `DELETE /documents/{id}` is
     * permanent, and a note is not the only lens on a document: the person may
     * have filed, shared or tagged it in Drive since. `POST /documents/{id}/trash`
     * is the honest middle — it leaves their document list, Drive's own
     * restore puts it back, and Drive's retention policy still gets a veto
     * (`RetentionPolicyService::assertCanTrash`).
     *
     * Only ever for a document **Notes uploaded**. A file the user linked from
     * their own Drive is theirs; detaching it from a note is not a request to
     * throw it away.
     *
     * Best-effort, like the unlink beside it: the attachment is already gone
     * from the note by the time this runs, and Drive being unreachable is not
     * a reason to refuse a detach the user asked for.
     */
    public function trash(DriveContext $context, string $documentId): void
    {
        try {
            $this->client->send(
                'POST',
                self::DOCUMENTS . '/' . AicountlyClient::segment($documentId) . '/trash',
                $context->sesKey,
                null,
                $context->companyParams(),
            );
        } catch (\Throwable $e) {
            Logger::warn('drive.trash_failed', [
                'document_id' => $documentId,
                'error' => get_debug_type($e),
            ]);
        }
    }

    /**
     * A short-lived presigned GET for a document, or null.
     *
     * Drive checks the caller's permission and answers with a URL into the
     * private bucket. Notes hands that URL to the browser rather than fetching
     * the bytes and re-serving them: a download that streams through PHP costs
     * the whole file in transfer twice and a worker process for its duration,
     * for no gain — the permission check has already happened, on both sides.
     */
    public function signedUrl(DriveContext $context, string $documentId): ?string
    {
        Features::require(Features::DRIVE);

        $answer = $this->client->send(
            'GET',
            self::DOCUMENTS . '/' . AicountlyClient::segment($documentId) . '/download',
            $context->sesKey,
            null,
            $context->companyParams(),
        );

        $url = self::string($answer, 'url');

        return $url === '' ? null : $url;
    }

    /**
     * The bytes themselves.
     *
     * Two calls — Drive for the presigned URL, then the object store for the
     * body — because there is no endpoint that returns bytes. Used by the parts
     * of Notes that genuinely need the content in memory, such as text
     * extraction, and not by the download endpoint.
     */
    public function fetch(DriveContext $context, string $documentId): string
    {
        $url = $this->signedUrl($context, $documentId);
        if ($url === null) {
            throw ApiException::notFound('That file');
        }

        return $this->client->fetchBytes($url);
    }

    /** True when the document was there and is now gone. */
    public function delete(DriveContext $context, string $documentId): bool
    {
        Features::require(Features::DRIVE);

        try {
            $this->client->send(
                'DELETE',
                self::DOCUMENTS . '/' . AicountlyClient::segment($documentId),
                $context->sesKey,
                null,
                $context->companyParams(),
            );
        } catch (ApiException $e) {
            // Already gone is the state the caller asked for. Anything else is
            // a real failure and worth another run of the job.
            if ($e->status === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function exists(DriveContext $context, string $documentId): bool
    {
        Features::require(Features::DRIVE);

        try {
            $this->client->send(
                'GET',
                self::DOCUMENTS . '/' . AicountlyClient::segment($documentId),
                $context->sesKey,
                null,
                $context->companyParams(),
            );
        } catch (ApiException $e) {
            if ($e->status === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    // -----------------------------------------------------------------------

    /**
     * Tell Drive to drop the session and its quarantine object.
     *
     * Deliberately swallowing: this runs while another exception is on its way
     * up, and replacing "the upload failed because X" with "the abort failed"
     * would hide the thing the caller has to act on. Drive's lifecycle cron is
     * the backstop for the object either way.
     */
    private function abort(DriveContext $context, string $sessionId): void
    {
        try {
            $this->client->send(
                'POST',
                self::SESSIONS . '/' . AicountlyClient::segment($sessionId) . '/abort',
                $context->sesKey,
                null,
                $context->companyParams(),
            );
        } catch (\Throwable $e) {
            Logger::warn('drive.abort_failed', ['error' => get_debug_type($e)]);
        }
    }

    /** @param array<string, mixed> $data */
    private static function sessionId(array $data): string
    {
        $value = $data['session_id'] ?? $data['id'] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $data */
    private static function documentId(array $data): string
    {
        $value = $data['id'] ?? $data['document_id'] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
