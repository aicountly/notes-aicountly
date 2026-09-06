<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Attachments;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Activity\ActivityRecorder;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Jobs\JobQueue;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Integrations\DriveAttachmentService;
use Aicountly\Api\Integrations\DriveContext;
use Aicountly\Api\Integrations\DriveDocumentService;
use Aicountly\Api\Integrations\ObjectStore;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Everything an attachment *does*.
 *
 * The three rules that shape this class, in the order they matter:
 *
 *   1. **The type is what the bytes say it is.** A browser's `Content-Type` is
 *      whatever the client felt like sending and an extension is whatever the
 *      file was named, so both are treated as *claims* and checked against
 *      `finfo`'s reading of the content. A claim that disagrees with the
 *      content is refused rather than quietly believed — that disagreement is
 *      the shape of every "upload a .jpg, get a .php" story.
 *   2. **Nothing stored here is ever executable or previewable-as-HTML.** An
 *      HTML or SVG file served back from this origin is a stored XSS with the
 *      user's own session attached, so those types never enter the store at
 *      all, and downloads carry `Content-Disposition: attachment` regardless.
 *   3. **The upload does not wait for the processing.** Thumbnails, text
 *      extraction, OCR and transcription are queued and the request returns
 *      with `processing_status`. A paste of a screenshot must feel like a
 *      paste, not like an OCR run.
 */
final class AttachmentService
{
    /** Per attachment. A tsvector is capped at 1 MB, and a note may have many attachments. */
    public const MAX_EXTRACTED_CHARS = 100000;

    /** Per note, across every attachment. Same reason, one level up. */
    public const MAX_DERIVED_CHARS = 200000;

    private const DEFAULT_MAX_BYTES = 26214400;

    /** Detected content type → the `kind` column. Anything absent is not accepted. */
    private const KINDS = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/gif' => 'image',
        'image/webp' => 'image',
        'image/bmp' => 'image',
        'image/tiff' => 'image',
        'image/heic' => 'image',
        'image/heif' => 'image',
        'image/avif' => 'image',
        'application/pdf' => 'pdf',
        'audio/mpeg' => 'audio',
        'audio/mp4' => 'audio',
        'audio/aac' => 'audio',
        'audio/ogg' => 'audio',
        'audio/wav' => 'audio',
        'audio/x-wav' => 'audio',
        'audio/webm' => 'audio',
        'audio/flac' => 'audio',
        'audio/x-flac' => 'audio',
        'audio/x-m4a' => 'audio',
        'video/mp4' => 'video',
        'video/quicktime' => 'video',
        'video/webm' => 'video',
        'video/x-msvideo' => 'video',
        'video/x-matroska' => 'video',
        'text/plain' => 'text',
        'text/markdown' => 'text',
        'text/csv' => 'text',
        'application/msword' => 'document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
        'application/vnd.oasis.opendocument.text' => 'document',
        'application/rtf' => 'document',
        'application/vnd.ms-excel' => 'spreadsheet',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'spreadsheet',
        'application/vnd.oasis.opendocument.spreadsheet' => 'spreadsheet',
        'application/vnd.ms-powerpoint' => 'presentation',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'presentation',
        'application/vnd.oasis.opendocument.presentation' => 'presentation',
        'application/zip' => 'file',
    ];

    /**
     * Types that are refused however they arrive.
     *
     * Not because they are exotic, but because each one is a program: a browser
     * that fetched it from this origin would run it. SVG is on the list for the
     * same reason as HTML — it carries `<script>`.
     */
    private const BLOCKED_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'text/x-php', 'application/x-php', 'application/x-httpd-php',
        'application/javascript', 'text/javascript', 'application/x-javascript',
        'application/x-dosexec', 'application/vnd.microsoft.portable-executable',
        'application/x-msdownload', 'application/x-msi', 'application/x-executable',
        'application/x-pie-executable', 'application/x-sharedlib', 'application/x-object',
        'application/x-mach-binary', 'application/x-elf', 'application/x-sh',
        'text/x-shellscript', 'application/x-bat', 'application/java-archive',
        'application/x-python-code', 'text/x-python',
    ];

    /** Extensions refused outright — the name alone is enough to make a file dangerous on a PHP host. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtml', 'phar',
        'shtml', 'html', 'htm', 'xhtml', 'svg', 'js', 'mjs', 'cjs', 'wasm',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'cpl', 'jar', 'apk',
        'sh', 'bash', 'zsh', 'ps1', 'psm1', 'py', 'pl', 'rb', 'cgi', 'so', 'dylib',
        'htaccess', 'htpasswd', 'ini', 'lnk',
    ];

    /** What an extension *claims* the content is. Checked against what it turns out to be. */
    private const EXTENSION_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpe' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'heic' => 'image/heic', 'heif' => 'image/heif', 'avif' => 'image/avif',
        'pdf' => 'application/pdf',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav',
        'flac' => 'audio/flac', 'weba' => 'audio/webm',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime',
        'webm' => 'video/webm', 'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
        'txt' => 'text/plain', 'log' => 'text/plain', 'text' => 'text/plain',
        'md' => 'text/markdown', 'markdown' => 'text/markdown', 'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'rtf' => 'application/rtf',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'zip' => 'application/zip',
    ];

    /**
     * Container formats whose reading may be narrowed by the extension.
     *
     * A .docx *is* a zip and a .csv *is* plain text, so `finfo` is right and
     * incomplete rather than wrong. Refinement is allowed only within a
     * container's own family — never from `application/octet-stream`, which
     * would be the extension deciding after all.
     */
    private const REFINABLE = [
        'application/zip' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
        ],
        'text/plain' => ['text/markdown', 'text/csv'],
        'application/csv' => ['text/csv'],
        'application/CDFV2' => [
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
        ],
    ];

    private const COLUMNS = 'id, note_id, block_id, storage_provider, storage_key, drive_file_id,
        filename, mime_type, byte_size, checksum_sha256, kind, duration_seconds, width, height,
        thumbnail_key, upload_status, processing_status, processing_error, extracted_text,
        metadata, created_by, created_at, updated_at, deleted_at';

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly JobQueue $jobs = new JobQueue(),
        private readonly ActivityRecorder $activity = new ActivityRecorder(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function list(Identity $identity, string $noteId): array
    {
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW, columns: 'n.id');

        $rows = Connection::select(
            'SELECT ' . self::COLUMNS . ' FROM note_attachments
             WHERE note_id = :note_id AND deleted_at IS NULL
             ORDER BY created_at, id',
            ['note_id' => $noteId],
        );

        return array_map(static fn (array $row): array => self::present($row), $rows);
    }

    /** @return array<string, mixed> */
    public function get(Identity $identity, string $noteId, string $attachmentId): array
    {
        $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW, columns: 'n.id');

        return self::present($this->requireAttachment($noteId, $attachmentId), detail: true);
    }

    /**
     * Metadata plus the store the bytes are in, for the download endpoint.
     *
     * The permission check is here rather than in the controller because it has
     * to happen on *every* download: a note un-shared five minutes ago must
     * stop answering for its attachments too, and a URL that once worked is not
     * a grant.
     *
     * `$sesKey` is the caller's own session, and it is what lets a Drive-stored
     * attachment answer with a presigned URL: Drive re-checks the caller before
     * issuing one, so the file is authorised twice — here against the note, and
     * there against the document — rather than served on this API's say-so.
     *
     * @return array{attachment: array<string, mixed>, store: ObjectStore}
     */
    public function openForDownload(
        Identity $identity,
        string $noteId,
        string $attachmentId,
        string $sesKey = '',
    ): array {
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            columns: 'n.id, n.tenant_id',
        );

        $attachment = $this->requireAttachment($noteId, $attachmentId);
        $provider = (string) $attachment['storage_provider'];

        return [
            'attachment' => $attachment,
            'store' => DriveAttachmentService::storeFor(
                $provider,
                $provider === 'drive' ? $this->driveContext($sesKey, $note) : null,
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Upload
    // -----------------------------------------------------------------------

    /**
     * Store bytes against a note and queue whatever can be derived from them.
     *
     * `$sesKey` is the caller's own session. It is unused by the local store and
     * required by Drive: Notes runs Drive's upload sequence as the person who
     * asked, never as itself (see {@see DriveContext}).
     *
     * @param array{filename?: string, bytes: string, content_type?: string, block_id?: ?string, id?: ?string} $upload
     * @return array<string, mixed>
     */
    public function upload(Identity $identity, string $noteId, array $upload, string $sesKey = ''): array
    {
        // tenant_id and note_type come back because Drive files by scope and by
        // module: a personal note's file goes under the user, a company note's
        // under the company, and a meeting recording is not a stray attachment.
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::EDIT,
            columns: 'n.id, n.tenant_id, n.note_type',
        );

        $attachmentId = isset($upload['id']) && Uuid::isValid($upload['id'])
            ? strtolower((string) $upload['id'])
            : Uuid::v4();

        // The offline queue replays what it could not send. A create it already
        // applied must not become a second copy of the same file.
        //
        // Scoped to this note on purpose: the id comes from the client, so
        // "return whatever row has this id" would turn a guessed UUID into a
        // read of somebody else's attachment. A collision outside this note is
        // refused without saying what it collided with.
        $existing = Connection::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM note_attachments WHERE id = :id',
            ['id' => $attachmentId],
        );
        if ($existing !== null) {
            // A detached attachment does not come back by re-sending its id
            // either: the object behind it has been queued for deletion.
            if ((string) $existing['note_id'] !== $noteId || $existing['deleted_at'] !== null) {
                throw ApiException::validation(['id' => 'That attachment id is already in use.']);
            }

            // A row left by an upload that never landed is not a create to
            // replay — there are no bytes behind it. It is cleared so this
            // attempt can be the one that works, which is what the client
            // retrying with the same id is asking for.
            if ((string) $existing['upload_status'] === 'failed') {
                Connection::execute(
                    'DELETE FROM note_attachments WHERE id = :id AND upload_status = \'failed\'',
                    ['id' => $attachmentId],
                );
            } else {
                return self::present($existing);
            }
        }

        $bytes = (string) $upload['bytes'];
        $filename = self::sanitiseFilename((string) ($upload['filename'] ?? ''));
        $inspected = $this->inspect($bytes, $filename, (string) ($upload['content_type'] ?? ''));

        $store = DriveAttachmentService::defaultStore($this->driveContext(
            $sesKey,
            $note,
            DriveContext::moduleFor((string) ($note['note_type'] ?? 'document'), $inspected['kind']),
        ));

        // Bytes first: the row that appears on the note is only ever written
        // once there is an object behind it, because a note showing a file that
        // cannot be opened is worse than an object nothing points at. The row
        // the failure path writes below is the opposite of that — it says
        // plainly that nothing was stored.
        //
        // The key comes back from the write rather than going into it. The local
        // store hands back what it was given; Drive builds its own object key
        // and only names the document once the bytes have been scanned and
        // promoted, so there is nothing to record until then.
        $key = $store->allocateKey();

        try {
            $key = $store->put($key, $bytes, $inspected['mime_type'], $filename);
        } catch (\Throwable $e) {
            // Nothing landed. The store has already cleaned up after itself —
            // Drive aborts its upload session, which deletes the quarantine
            // object — and what is left is the note's own record of an attempt
            // that failed, so a client polling this id learns that rather than
            // waiting for a file that is never coming.
            $this->recordFailedAttempt($identity, [
                'id' => $attachmentId,
                'note_id' => $noteId,
                'block_id' => self::blockId($upload['block_id'] ?? null),
                'storage_provider' => $store->name(),
                'filename' => $filename,
                'mime_type' => $inspected['mime_type'],
                'byte_size' => strlen($bytes),
                'checksum' => hash('sha256', $bytes),
                'kind' => $inspected['kind'],
            ]);

            throw $e;
        }

        try {
            $row = $this->insert($identity, [
                'id' => $attachmentId,
                'note_id' => $noteId,
                'block_id' => self::blockId($upload['block_id'] ?? null),
                'storage_provider' => $store->name(),
                'storage_key' => $key,
                'drive_file_id' => null,
                'filename' => $filename,
                'mime_type' => $inspected['mime_type'],
                'byte_size' => strlen($bytes),
                // The same digest Drive was asked to verify the upload against,
                // recorded here as what this API saw arrive.
                'checksum' => hash('sha256', $bytes),
                'kind' => $inspected['kind'],
                'metadata' => ['source' => 'upload'],
            ]);
        } catch (\Throwable $e) {
            $store->delete($key);
            throw $e;
        }

        $this->activity->record($identity, ActivityRecorder::ATTACHMENT_ADDED, $noteId, null, [
            'attachment_id' => $attachmentId,
            'kind' => $inspected['kind'],
            'byte_size' => strlen($bytes),
        ]);

        return self::present($row);
    }

    /**
     * Attach a file the user already has in Drive, without copying it.
     *
     * The point of the endpoint: a 200 MB recording that is already in Drive
     * should appear on a note instantly and exist once, not twice.
     *
     * @return array<string, mixed>
     */
    public function linkDrive(
        Identity $identity,
        string $sesKey,
        string $noteId,
        string $driveFileId,
        ?string $blockId,
    ): array {
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::EDIT,
            columns: 'n.id, n.tenant_id',
        );

        if ($driveFileId === '' || strlen($driveFileId) > 128) {
            throw ApiException::validation(['drive_file_id' => 'A Drive file id is required.']);
        }

        // Checked before the context is built, so a deployment without Drive
        // answers FEATURE_DISABLED rather than a complaint about company
        // parameters it would never have used.
        Features::require(Features::DRIVE);

        // Drive answers whether this caller may see the file, on the caller's
        // own session. This API never decides that, and never treats an id as
        // proof of access.
        $file = (new DriveAttachmentService())->file(
            DriveContext::forNote($sesKey, $noteId, self::tenantOf($note)),
            $driveFileId,
        );

        $mime = $file['mime_type'];
        if (in_array($mime, self::BLOCKED_TYPES, true)) {
            throw ApiException::validation([
                'drive_file_id' => 'That kind of file cannot be attached to a note.',
            ]);
        }

        $existing = Connection::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM note_attachments
             WHERE note_id = :note_id AND drive_file_id = :drive_id AND deleted_at IS NULL',
            ['note_id' => $noteId, 'drive_id' => $driveFileId],
        );
        if ($existing !== null) {
            return self::present($existing);
        }

        $row = $this->insert($identity, [
            'id' => Uuid::v4(),
            'note_id' => $noteId,
            'block_id' => self::blockId($blockId),
            'storage_provider' => 'drive',
            // A linked file is addressed by its Drive id: there is no object of
            // ours to allocate a key for.
            'storage_key' => $driveFileId,
            'drive_file_id' => $driveFileId,
            'filename' => self::sanitiseFilename($file['filename']),
            'mime_type' => $mime,
            'byte_size' => max(0, $file['byte_size']),
            'checksum' => $file['checksum'],
            'kind' => self::KINDS[$mime] ?? 'file',
            'metadata' => ['source' => 'drive_link'],
        ]);

        $this->activity->record($identity, ActivityRecorder::ATTACHMENT_ADDED, $noteId, null, [
            'attachment_id' => (string) $row['id'],
            'kind' => (string) $row['kind'],
            'source' => 'drive_link',
        ]);

        return self::present($row);
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    /**
     * Detach a file.
     *
     * A soft delete, so the note's history and activity trail still make sense
     * and a mis-click is recoverable by an operator. The object itself is
     * removed by {@see JobQueue::OBJECT_PURGE}, which is deliberately queued
     * with **no** attachment_id: the job's whole purpose is to outlive the row,
     * and a foreign key to the row it is cleaning up would cascade it away.
     */
    /**
     * @param string $sesKey The caller's own session, forwarded to Drive so the
     *        cross-reference is dropped as them. Optional: without it the
     *        detach still succeeds and only the Drive link row is left behind.
     */
    public function delete(Identity $identity, string $noteId, string $attachmentId, string $sesKey = ''): void
    {
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::EDIT,
            columns: 'n.id, n.tenant_id',
        );

        $attachment = $this->requireAttachment($noteId, $attachmentId);

        Connection::transaction(function () use ($identity, $attachment, $attachmentId, $noteId): void {
            Connection::execute(
                'UPDATE note_attachments SET deleted_at = now(), updated_at = now()
                 WHERE id = :id AND deleted_at IS NULL',
                ['id' => $attachmentId],
            );

            $keys = array_values(array_filter([
                (string) $attachment['storage_key'],
                $attachment['thumbnail_key'] === null ? null : (string) $attachment['thumbnail_key'],
            ]));

            // A linked Drive file belongs to the user, not to this note. Notes
            // deleting it would destroy a file they still have in Drive.
            //
            // A row that names no object gets no job either: an upload that
            // failed, or one whose object a previous purge already removed, has
            // nothing left to clean up, and a job with no keys can only fail.
            if ($attachment['drive_file_id'] === null && $keys !== []) {
                $this->jobs->enqueue($identity, JobQueue::OBJECT_PURGE, null, null, [
                    'storage_provider' => (string) $attachment['storage_provider'],
                    'keys' => $keys,
                    'attachment_id' => $attachmentId,
                ]);
            }

            // A file the user linked from their own Drive survives, but the
            // cross-reference saying this note points at it should not: Drive's
            // document manager would otherwise keep showing a note that no
            // longer has the file. Deleting a document Notes uploaded removes
            // its links in Drive already, so only the linked case needs this.
            if ($attachment['drive_file_id'] !== null && $sesKey !== '') {
                $this->unlinkFromDrive($sesKey, $note, (string) $attachment['drive_file_id']);
            }

            $this->activity->record($identity, ActivityRecorder::ATTACHMENT_REMOVED, $noteId, null, [
                'attachment_id' => $attachmentId,
            ]);
        });

        // The note's search text is built from its attachments, so removing one
        // has to take its OCR out of the index too.
        $this->jobs->enqueue($identity, JobQueue::DERIVED_TEXT, $noteId);
    }

    /**
     * Drop the note↔document cross-reference in Drive.
     *
     * Best-effort and deliberately quiet. The attachment is already gone from
     * the note by the time this runs, and Drive being unreachable is not a
     * reason to refuse a detach the user asked for — it leaves a stale link
     * row, which is untidy rather than harmful, and Drive's endpoint is
     * idempotent so a later retry converges.
     *
     * Skipped entirely when Drive is switched off, which is the default.
     */
    /** @param array<string, mixed> $note */
    private function unlinkFromDrive(string $sesKey, array $note, string $documentId): void
    {
        try {
            $context = $this->driveContext($sesKey, $note);
            if ($context !== null) {
                (new DriveDocumentService())->unlink($context, $documentId);
            }
        } catch (\Throwable $e) {
            Logger::warn('drive.unlink_skipped', ['error' => get_debug_type($e)]);
        }
    }

    // -----------------------------------------------------------------------
    // The worker's side
    //
    // No permission checks below this line, and that is on purpose: a job is
    // work that was already authorised when it was enqueued. Nothing here is
    // reachable from HTTP.
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function find(string $attachmentId): ?array
    {
        return Connection::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM note_attachments WHERE id = :id',
            ['id' => $attachmentId],
        );
    }

    /**
     * The store an attachment's bytes are in, with no caller behind it.
     *
     * The worker's view. It is enough for the local store, and deliberately not
     * enough for Drive: see {@see enqueueProcessing()} for why no job that has
     * to read a Drive object is ever queued in the first place.
     */
    public function storeFor(array $attachment): ObjectStore
    {
        return DriveAttachmentService::storeFor((string) $attachment['storage_provider']);
    }

    /**
     * The Drive context for one note, or null when Drive is not in play.
     *
     * Null rather than an exception when the flag is off: a deployment on the
     * local store must never be asked for company parameters it has no use for,
     * and building this eagerly would turn a working local upload into a 400.
     *
     * @param array<string, mixed> $note
     */
    private function driveContext(
        string $sesKey,
        array $note,
        string $moduleCode = DriveContext::MODULE_ATTACHMENTS,
    ): ?DriveContext {
        if (!Features::enabled(Features::DRIVE)) {
            return null;
        }

        return DriveContext::forNote($sesKey, (string) $note['id'], self::tenantOf($note), $moduleCode);
    }

    /** @param array<string, mixed> $note */
    private static function tenantOf(array $note): ?string
    {
        // A personal note has no tenant, and null is not the same as ''. The
        // difference decides whether the file is filed under the user or under
        // the company, which is not a distinction to get wrong.
        return ($note['tenant_id'] ?? null) === null ? null : (string) $note['tenant_id'];
    }

    /**
     * Record an upload whose bytes never landed.
     *
     * The row exists so the attempt is visible — a client that polls this id
     * sees `upload_status: failed` instead of waiting for a file that is not
     * coming, and an operator can see that uploads are failing at all. It names
     * no object (`storage_key` is empty) and queues no processing, because there
     * is nothing to process; re-sending the same id clears it and tries again.
     *
     * Failing to write it must not replace the error that caused it: the caller
     * needs to hear why the upload failed, not why the note-keeping did.
     *
     * @param array<string, mixed> $values
     */
    private function recordFailedAttempt(Identity $identity, array $values): void
    {
        try {
            Connection::execute(
                'INSERT INTO note_attachments
                    (id, note_id, block_id, storage_provider, storage_key, drive_file_id,
                     filename, mime_type, byte_size, checksum_sha256, kind,
                     upload_status, processing_status, metadata, created_by)
                 VALUES
                    (:id, :note_id, :block_id, :storage_provider, \'\', NULL,
                     :filename, :mime_type, :byte_size, :checksum, :kind,
                     \'failed\', \'skipped\', :metadata::jsonb, :actor)
                 ON CONFLICT (id) DO NOTHING',
                [
                    'id' => $values['id'],
                    'note_id' => $values['note_id'],
                    'block_id' => $values['block_id'],
                    'storage_provider' => $values['storage_provider'],
                    'filename' => $values['filename'],
                    'mime_type' => $values['mime_type'],
                    'byte_size' => $values['byte_size'],
                    'checksum' => $values['checksum'],
                    'kind' => $values['kind'],
                    'metadata' => json_encode(
                        ['source' => 'upload', 'upload_failed' => true],
                        JSON_UNESCAPED_SLASHES,
                    ),
                    'actor' => $identity->userId,
                ],
            );
        } catch (\Throwable $e) {
            Logger::error('attachment.failed_row_not_written', ['error' => get_debug_type($e)]);
        }
    }

    /**
     * Record what a handler managed to read out of a file.
     *
     * `extracted_text` on the attachment, never on the note: `notes.extracted_text`
     * is what the user typed, and an OCR pass overwriting it would delete
     * someone's writing to make room for a guess at a photo.
     */
    public function recordExtractedText(string $attachmentId, string $text): void
    {
        Connection::execute(
            'UPDATE note_attachments SET extracted_text = :text, updated_at = now() WHERE id = :id',
            ['id' => $attachmentId, 'text' => Str::limit($text, self::MAX_EXTRACTED_CHARS)],
        );
    }

    /** @param array<string, mixed> $fields */
    public function recordMedia(string $attachmentId, array $fields): void
    {
        $updates = [];
        $bindings = ['id' => $attachmentId];

        foreach (['width', 'height', 'duration_seconds'] as $column) {
            if (array_key_exists($column, $fields)) {
                $updates[] = $column . ' = :' . $column;
                $bindings[$column] = $fields[$column] === null ? null : (int) $fields[$column];
            }
        }
        if (array_key_exists('thumbnail_key', $fields)) {
            $updates[] = 'thumbnail_key = :thumbnail_key';
            $bindings['thumbnail_key'] = $fields['thumbnail_key'];
        }
        if ($updates === []) {
            return;
        }

        $updates[] = 'updated_at = now()';
        Connection::execute(
            'UPDATE note_attachments SET ' . implode(', ', $updates) . ' WHERE id = :id',
            $bindings,
        );
    }

    /**
     * Re-derive `processing_status` from the jobs that actually ran.
     *
     * One column summarising several jobs has to be computed, not assigned:
     * a thumbnail that succeeded and an OCR that was skipped is not "skipped",
     * and the last handler to finish is not necessarily the interesting one.
     */
    public function refreshProcessingStatus(string $attachmentId): string
    {
        $row = Connection::selectOne(
            'SELECT
                count(*) FILTER (WHERE status = \'queued\')     AS queued,
                count(*) FILTER (WHERE status = \'processing\') AS running,
                count(*) FILTER (WHERE status = \'failed\')     AS failed,
                count(*) FILTER (WHERE status = \'completed\'
                                   AND coalesce(result->>\'outcome\', \'completed\') <> \'skipped\') AS done,
                count(*) AS total,
                max(last_error) FILTER (WHERE status = \'failed\') AS error
             FROM note_processing_jobs WHERE attachment_id = :id',
            ['id' => $attachmentId],
        ) ?? [];

        $status = match (true) {
            (int) ($row['running'] ?? 0) > 0 => 'processing',
            (int) ($row['queued'] ?? 0) > 0 => 'queued',
            (int) ($row['failed'] ?? 0) > 0 => 'failed',
            (int) ($row['done'] ?? 0) > 0 => 'completed',
            default => 'skipped',
        };

        Connection::execute(
            'UPDATE note_attachments
                SET processing_status = :status::text,
                    processing_error = :error,
                    updated_at = now()
              WHERE id = :id',
            [
                'id' => $attachmentId,
                'status' => $status,
                'error' => $status === 'failed' && isset($row['error'])
                    ? Str::limit((string) $row['error'], 300)
                    : null,
            ],
        );

        return $status;
    }

    /**
     * Rebuild `notes.derived_text` from the note's attachments.
     *
     * Rebuilt rather than appended to, though the effect is per-attachment
     * accumulation: appending blindly would double a document's text every time
     * its attachment was re-processed, and there is no way to un-append. The
     * source of truth is the attachments table, so this is idempotent by
     * construction and a deleted attachment takes its text out of the index.
     *
     * The column feeds the note's generated tsvector at weight C — below the
     * title and below what the user typed, which is the right order for a hit
     * that came from a photo.
     */
    public function rebuildDerivedText(string $noteId): int
    {
        $rows = Connection::select(
            'SELECT extracted_text FROM note_attachments
             WHERE note_id = :note_id AND deleted_at IS NULL
               AND extracted_text IS NOT NULL AND extracted_text <> \'\'
             ORDER BY created_at, id',
            ['note_id' => $noteId],
        );

        $parts = array_map(static fn (array $row): string => trim((string) $row['extracted_text']), $rows);
        // Hard cap: `to_tsvector` refuses a string over 1 MB, and the vector is
        // GENERATED, so an oversized value would make every write to the note
        // fail rather than just this one.
        $derived = Str::limit(implode("\n\n", array_filter($parts)), self::MAX_DERIVED_CHARS);

        Connection::execute(
            'UPDATE notes SET derived_text = :derived WHERE id = :id AND derived_text <> :derived',
            ['id' => $noteId, 'derived' => $derived],
        );

        return mb_strlen($derived, 'UTF-8');
    }

    /**
     * Queue the work a newly stored file makes possible.
     *
     * OCR and transcription are queued even when their feature flag is off. The
     * handler answers `skipped` and the job row records why, which is the
     * difference between an operator being able to see "47 images arrived and
     * nothing read them" and there being no trace at all.
     */
    public function enqueueProcessing(Identity $identity, array $attachment): int
    {
        $noteId = (string) $attachment['note_id'];
        $attachmentId = (string) $attachment['id'];
        $queued = 0;

        // A private note is skipped whole, the same way NotesService skips its
        // links and checklist extraction. Its content is meant to be unreadable
        // here, and sending one of its files to an OCR service — or indexing
        // what came back — would be the first step away from that promise.
        $note = Connection::selectOne('SELECT privacy_mode FROM notes WHERE id = :id', ['id' => $noteId]);
        if ((string) ($note['privacy_mode'] ?? 'standard') === 'private') {
            return 0;
        }

        // Every job below has to read the object back. For a Drive-stored file
        // that means a call to Drive, and Drive is reached on the caller's own
        // ses_key — which a cron worker does not have and must not be given.
        // Queuing them anyway would produce a job that fails, retries and fails
        // again for every upload; the honest state is that this deployment
        // cannot derive anything from a file it does not hold.
        if ((string) $attachment['storage_provider'] === 'drive') {
            return 0;
        }

        $queue = function (string $type) use ($identity, $noteId, $attachmentId, &$queued): void {
            if ($this->jobs->enqueue($identity, $type, $noteId, $attachmentId) !== null) {
                $queued++;
            }
        };

        switch ((string) $attachment['kind']) {
            case 'image':
                $queue(JobQueue::THUMBNAIL);
                $queue(JobQueue::OCR);
                break;
            case 'pdf':
            case 'text':
                // A PDF with a text layer needs no OCR; the extractor decides
                // that and queues OCR itself when it finds nothing to read.
                $queue(JobQueue::TEXT_EXTRACTION);
                break;
            case 'audio':
            case 'video':
                $queue(JobQueue::TRANSCRIPTION);
                break;
        }

        if ($queued > 0) {
            Connection::execute(
                'UPDATE note_attachments SET processing_status = \'queued\', updated_at = now() WHERE id = :id',
                ['id' => $attachmentId],
            );
        }

        return $queued;
    }

    public function queue(): JobQueue
    {
        return $this->jobs;
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Decide what this file really is, or refuse it.
     *
     * @return array{mime_type: string, kind: string}
     */
    private function inspect(string $bytes, string $filename, string $claimedType): array
    {
        $maxBytes = Features::int('NOTES_MAX_ATTACHMENT_SIZE', self::DEFAULT_MAX_BYTES, 1024, 1073741824);
        $size = strlen($bytes);

        if ($size === 0) {
            throw ApiException::validation(['file' => 'That file is empty.']);
        }
        if ($size > $maxBytes) {
            throw ApiException::validation([
                'file' => sprintf('Files must be %d MB or smaller.', (int) round($maxBytes / 1048576)),
            ]);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw ApiException::validation([
                'file' => 'That kind of file cannot be attached to a note.',
            ]);
        }

        $detected = self::detectType($bytes);
        if (in_array($detected, self::BLOCKED_TYPES, true)) {
            // Deliberately the same message as the extension rejection: the
            // upload is refused either way, and naming the detection method
            // only tells someone probing which check to work around.
            Logger::warn('attachment.blocked_type', ['detected' => $detected, 'extension' => $extension]);
            throw ApiException::validation([
                'file' => 'That kind of file cannot be attached to a note.',
            ]);
        }

        $claimedByExtension = self::EXTENSION_TYPES[$extension] ?? null;
        $effective = $detected;

        // A container is narrowed by the extension only within its own family.
        if ($claimedByExtension !== null
            && in_array($claimedByExtension, self::REFINABLE[$detected] ?? [], true)) {
            $effective = $claimedByExtension;
        }

        $kind = self::KINDS[$effective] ?? null;
        if ($kind === null) {
            throw ApiException::validation([
                'file' => 'That file type is not supported yet.',
            ]);
        }

        // The claims, now that there is something to compare them against.
        // Disagreement is refused rather than resolved: a PNG named .pdf is
        // either a mistake worth telling the user about or an attempt to have
        // the file served as something it is not.
        self::assertClaimMatches($claimedByExtension, $kind, 'file');
        self::assertClaimMatches(self::normaliseType($claimedType), $kind, 'content_type');

        return ['mime_type' => $effective, 'kind' => $kind];
    }

    private static function assertClaimMatches(?string $claimed, string $kind, string $field): void
    {
        if ($claimed === null || $claimed === '') {
            return;
        }

        $claimedKind = self::KINDS[$claimed] ?? null;
        if ($claimedKind !== null && $claimedKind !== $kind) {
            throw ApiException::validation([
                $field => 'That file is not the type its name says it is.',
            ]);
        }
    }

    private static function detectType(string $bytes): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($bytes);

        return $detected === false ? 'application/octet-stream' : strtolower(trim($detected));
    }

    private static function normaliseType(string $type): string
    {
        // "image/png; charset=binary" and " IMAGE/PNG " are the same claim.
        $type = strtolower(trim($type));
        $semicolon = strpos($type, ';');

        return $semicolon === false ? $type : trim(substr($type, 0, $semicolon));
    }

    private static function sanitiseFilename(string $filename): string
    {
        // basename() first: a client is free to send "../../.htaccess", and the
        // name reaches a Content-Disposition header later.
        $name = basename(str_replace('\\', '/', trim($filename)));
        $name = preg_replace('/[\x00-\x1f\x7f"]+/u', '', $name) ?? '';
        $name = trim($name, ". \t");

        return $name === '' ? 'attachment' : Str::limit($name, 400);
    }

    private static function blockId(mixed $blockId): ?string
    {
        if (!is_scalar($blockId)) {
            return null;
        }
        $value = trim((string) $blockId);

        return $value === '' ? null : Str::limit($value, 64);
    }

    // -----------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function insert(Identity $identity, array $values): array
    {
        return Connection::transaction(function () use ($identity, $values): array {
            Connection::execute(
                'INSERT INTO note_attachments
                    (id, note_id, block_id, storage_provider, storage_key, drive_file_id,
                     filename, mime_type, byte_size, checksum_sha256, kind,
                     upload_status, processing_status, metadata, created_by)
                 VALUES
                    (:id, :note_id, :block_id, :storage_provider, :storage_key, :drive_file_id,
                     :filename, :mime_type, :byte_size, :checksum, :kind,
                     \'ready\', \'pending\', :metadata::jsonb, :actor)',
                [
                    'id' => $values['id'],
                    'note_id' => $values['note_id'],
                    'block_id' => $values['block_id'],
                    'storage_provider' => $values['storage_provider'],
                    'storage_key' => $values['storage_key'],
                    'drive_file_id' => $values['drive_file_id'],
                    'filename' => $values['filename'],
                    'mime_type' => $values['mime_type'],
                    'byte_size' => $values['byte_size'],
                    'checksum' => $values['checksum'],
                    'kind' => $values['kind'],
                    'metadata' => json_encode($values['metadata'], JSON_UNESCAPED_SLASHES),
                    'actor' => $identity->userId,
                ],
            );

            $row = $this->requireAttachment((string) $values['note_id'], (string) $values['id']);

            // Queued after the row exists — a worker claiming the job in the
            // same second has to find something to work on — and inside the
            // same transaction, so a file never appears on a note with the work
            // it needs missing.
            if ($this->enqueueProcessing($identity, $row) > 0) {
                $row['processing_status'] = 'queued';
            } else {
                Connection::execute(
                    'UPDATE note_attachments SET processing_status = \'skipped\' WHERE id = :id',
                    ['id' => $values['id']],
                );
                $row['processing_status'] = 'skipped';
            }

            return $row;
        });
    }

    /** @return array<string, mixed> */
    private function requireAttachment(string $noteId, string $attachmentId): array
    {
        $row = Connection::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM note_attachments
             WHERE id = :id AND note_id = :note_id AND deleted_at IS NULL',
            ['id' => $attachmentId, 'note_id' => $noteId],
        );

        if ($row === null) {
            throw ApiException::notFound('That attachment');
        }

        return $row;
    }

    // -----------------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------------

    /**
     * Row → API resource.
     *
     * `storage_key` never appears: it is an address in a store, and the only
     * legitimate way to reach the bytes is `content_url`, which re-checks the
     * caller's permission. The extracted text is on the detail view only —
     * a list of twenty attachments should not carry twenty OCR transcripts.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row, bool $detail = false): array
    {
        $noteId = (string) $row['note_id'];
        $id = (string) $row['id'];
        $text = (string) ($row['extracted_text'] ?? '');

        $resource = [
            'id' => $id,
            'note_id' => $noteId,
            'block_id' => $row['block_id'] === null ? null : (string) $row['block_id'],
            'filename' => (string) $row['filename'],
            'mime_type' => (string) $row['mime_type'],
            'kind' => (string) $row['kind'],
            'byte_size' => (int) $row['byte_size'],
            'checksum_sha256' => $row['checksum_sha256'] === null ? null : (string) $row['checksum_sha256'],
            'storage_provider' => (string) $row['storage_provider'],
            'drive_file_id' => $row['drive_file_id'] === null ? null : (string) $row['drive_file_id'],
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
            'duration_seconds' => $row['duration_seconds'] === null ? null : (int) $row['duration_seconds'],
            'has_thumbnail' => $row['thumbnail_key'] !== null,
            'upload_status' => (string) $row['upload_status'],
            'processing_status' => (string) $row['processing_status'],
            'processing_error' => $row['processing_error'] === null ? null : (string) $row['processing_error'],
            'has_text' => trim($text) !== '',
            'content_url' => sprintf('/notes/%s/attachments/%s/content', $noteId, $id),
            'created_by' => (string) $row['created_by'],
            'created_at' => self::timestamp($row['created_at'] ?? null),
            'updated_at' => self::timestamp($row['updated_at'] ?? null),
        ];

        if ($detail) {
            $resource['extracted_text'] = $text === '' ? null : $text;
        }

        return $resource;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::RFC3339);
        } catch (\Throwable) {
            return null;
        }
    }
}
