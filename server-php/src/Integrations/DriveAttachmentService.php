<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;

/**
 * Where Notes' files live, and how a file already in Drive is resolved.
 *
 * Drive owns files across the whole suite; Notes borrows them. Two jobs live
 * here, and nothing else in the codebase does either of them:
 *
 *   1. **Choosing where new bytes go.** {@see defaultStore()} answers Drive
 *      when Drive is switched on and the local disk when it is not, so no
 *      caller has to branch on a feature flag to save a file.
 *   2. **Resolving a file the user already has in Drive.** `link-drive`
 *      attaches an existing Drive document by id *without copying its bytes*,
 *      which only works if Drive confirms that this caller may see it. The
 *      caller's own ses_key goes with the request for exactly that reason: a
 *      Drive document id must never be treated as a capability on its own.
 *
 * The upload sequence itself is not here — it is four calls and a presigned PUT
 * to an object store, and it lives in {@see DriveDocumentService}. This class
 * only decides *which* store, and answers one question about a file.
 *
 * Every method that reaches the network requires {@see Features::DRIVE}, which
 * is off by default. An unconfigured deployment gets FEATURE_DISABLED and keeps
 * using {@see LocalObjectStore}; it never gets a fabricated file.
 */
final class DriveAttachmentService
{
    private const SERVICE = 'drive';

    /** Drive's own route for one document. Confirmed against `drive-react-app` Routes.php. */
    private const DOCUMENTS = '/documents';

    public function __construct(
        private readonly AicountlyClient $client = new AicountlyClient(
            self::SERVICE,
            Features::DRIVE,
            'drive',
        ),
    ) {
    }

    // -----------------------------------------------------------------------
    // Store selection
    // -----------------------------------------------------------------------

    /**
     * Where bytes uploaded right now should be written.
     *
     * The context carries the caller's session and the note the file belongs
     * to. It is optional only so that a caller with nothing to store — a
     * capability check, a test — can still ask which store is in use; a Drive
     * store built without one refuses every call that reaches the network.
     */
    public static function defaultStore(?DriveContext $context = null): ObjectStore
    {
        return Features::enabled(Features::DRIVE) ? new DriveObjectStore($context) : new LocalObjectStore();
    }

    /**
     * The store an existing attachment lives in.
     *
     * Driven by the row's `storage_provider`, never by the current flag: a file
     * written to disk before Drive was switched on is still on that disk, and
     * asking Drive for it would 404 a file the user can see in their note.
     */
    public static function storeFor(string $provider, ?DriveContext $context = null): ObjectStore
    {
        return match ($provider) {
            'drive' => new DriveObjectStore($context),
            default => new LocalObjectStore(),
        };
    }

    /**
     * Where Drive's API is, `https://drive.aicountly.com/api` in production.
     *
     * Resolved through {@see SiblingApi}, so a sandbox deployment reaches
     * sandbox Drive with nothing configured. Note the host is `drive` while the
     * product_code Drive stores in its own rows and object keys is `docs` —
     * SiblingApi keeps the two apart; nothing here should spell either by hand.
     */
    public static function base(): string
    {
        return SiblingApi::apiBase('drive');
    }

    // -----------------------------------------------------------------------
    // Files the user already has in Drive
    // -----------------------------------------------------------------------

    /**
     * Metadata for a Drive document this caller is allowed to see.
     *
     * Drive decides. The call carries the caller's own ses_key and the company
     * context of the note the file is being attached to, so "may this person
     * open this document" is answered by the product that holds the answer —
     * and 403 and 404 come back as one indistinguishable "not found", the same
     * way they do for a note.
     *
     * @return array{drive_file_id: string, filename: string, mime_type: string, byte_size: int, checksum: ?string}
     */
    public function file(DriveContext $context, string $driveFileId): array
    {
        Features::require(Features::DRIVE);

        $file = $this->client->send(
            'GET',
            self::DOCUMENTS . '/' . AicountlyClient::segment($driveFileId),
            $context->sesKey,
            null,
            $context->companyParams(),
        );

        // The filename and the type belong to the *version*, not to the
        // document, and this is the thing to get right here.
        //
        // `DocumentService::formatDocumentRow()` reads them from
        // `current_filename` / `current_mime` / `current_checksum`, and those
        // three are aliases produced by the LEFT JOIN in the *list* query
        // (`listDocuments()`). The single-document route does not join:
        // `getDocumentDetails()` fetches through `getDocumentForCtx()`, which is
        // `SELECT * FROM documents`, and the `documents` table has no filename
        // or mime column at all. So `GET /api/documents/{id}` answers
        // `filename: null` and `mime_type: null`, always — reading them there
        // would fail every link, for every file.
        //
        // What that route *does* carry is `versions[]`, the `document_versions`
        // rows in full, and the current one is the answer.
        $version = self::currentVersion($file);

        $name = self::firstString($version, ['filename'])
            // The document's `title` is what the uploader named it — Drive
            // defaults it to the filename at finalize — and is the only name
            // left if a document somehow has no readable version row.
            ?: self::firstString($file, ['filename', 'title']);
        $mime = self::firstString($version, ['mime_type']) ?: self::firstString($file, ['mime_type']);
        if ($name === '' || $mime === '') {
            throw ApiException::upstream(self::SERVICE, 'Drive returned a file this app cannot describe.');
        }

        // `documents.size_bytes` is the real column and is set at finalize, so
        // unlike the two above it is trustworthy on this route; the version's
        // own count is preferred only because it names the same bytes the name
        // and the type came from.
        $size = $version['size_bytes'] ?? $file['size_bytes'] ?? 0;
        $checksum = self::firstString($version, ['checksum_sha256'])
            ?: self::firstString($file, ['checksum_sha256']);

        return [
            'drive_file_id' => $driveFileId,
            'filename' => Str::limit($name, 400),
            'mime_type' => Str::limit(strtolower($mime), 160),
            'byte_size' => is_scalar($size) ? max(0, (int) $size) : 0,
            'checksum' => $checksum === '' ? null : Str::limit($checksum, 64),
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * The `document_versions` row for the file as it stands today.
     *
     * Drive marks exactly one row per document `is_current = 1` and returns them
     * newest first, so the flag is what is read and the ordering is only the
     * fallback. An empty array — a document with no version, which finalize does
     * not produce — is handled by the caller rather than guessed at here.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function currentVersion(array $document): array
    {
        $versions = $document['versions'] ?? null;
        if (!is_array($versions)) {
            return [];
        }

        $first = [];
        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }
            if ($first === []) {
                $first = $version;
            }
            if ((int) ($version['is_current'] ?? 0) === 1) {
                return $version;
            }
        }

        return $first;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    private static function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }
}
