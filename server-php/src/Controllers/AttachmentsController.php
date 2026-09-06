<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Attachments\AttachmentService;
use Aicountly\Api\Env;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\RateLimiter;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;
use Aicountly\Api\Integrations\ObjectStore;

/**
 * HTTP for attachments.
 *
 * Thin, like every controller here: what belongs to *this* class is the shape
 * of the request, and there are two shapes because there are two ways a file
 * reaches this API.
 *
 *   - **multipart/form-data** — the file picker and drag-and-drop.
 *   - **base64 in JSON** — the clipboard-paste path (a pasted screenshot is a
 *     Blob the client already holds, not a form) and the offline queue, which
 *     serialises pending uploads to IndexedDB and replays them as JSON later.
 *
 * Both end in the same call, and neither is trusted about what the file is:
 * {@see AttachmentService} decides that from the bytes.
 */
final class AttachmentsController
{
    /** Long enough to start a download, short enough not to be a shareable link. */
    private const SIGNED_URL_TTL_SECONDS = 120;

    public function __construct(private readonly AttachmentService $attachments = new AttachmentService())
    {
    }

    public function index(Request $request, Identity $identity): Response
    {
        return Response::ok($this->attachments->list($identity, $request->uuidParam('id')));
    }

    public function show(Request $request, Identity $identity): Response
    {
        return Response::ok($this->attachments->get(
            $identity,
            $request->uuidParam('id'),
            $request->uuidParam('attachmentId'),
        ));
    }

    /**
     * Take a file.
     *
     * Returns as soon as the bytes are stored and the work is queued — with
     * `processing_status`, so the client can poll or simply show a spinner on
     * the card. Waiting for a thumbnail, let alone for OCR, would make pasting
     * a screenshot feel like uploading to a government portal.
     */
    public function store(Request $request, Identity $identity): Response
    {
        // Uploads are the expensive write in this API, and the one bucket worth
        // limiting on the writing path.
        RateLimiter::hit('upload', $identity->userId);

        // The caller's own bearer token goes with it: with Drive as the store,
        // this API runs Drive's upload sequence *as the person who asked* and
        // never as itself.
        return Response::created($this->attachments->upload(
            $identity,
            $request->uuidParam('id'),
            self::payload($request),
            $request->bearerToken,
        ));
    }

    /** Attach a file the caller already has in Drive, by id, without copying it. */
    public function linkDrive(Request $request, Identity $identity): Response
    {
        RateLimiter::hit('upload', $identity->userId);

        // The caller's own bearer token goes to Drive, so Drive re-checks that
        // *this person* may see that file. See DriveAttachmentService::file().
        return Response::created($this->attachments->linkDrive(
            $identity,
            $request->bearerToken,
            $request->uuidParam('id'),
            $request->string('drive_file_id'),
            $request->nullableString('block_id'),
        ));
    }

    public function destroy(Request $request, Identity $identity): Response
    {
        // The caller's own bearer token goes with it, so a linked Drive file's
        // cross-reference is dropped as them rather than not at all.
        $this->attachments->delete(
            $identity,
            $request->uuidParam('id'),
            $request->uuidParam('attachmentId'),
            $request->bearerToken,
        );

        return Response::noContent();
    }

    /**
     * The bytes.
     *
     * The permission check happens here on every request, not once when the URL
     * was handed out: a note un-shared this morning has to stop answering for
     * its attachments this morning too.
     *
     * The response is the one in this API that is not JSON. {@see Response}
     * carries a JSON envelope and a header list, so the body is written into an
     * output buffer *before* returning: nothing has been sent while the buffer
     * is open, `Response::send()` therefore still gets to set the headers (the
     * ones below replace the JSON defaults), and PHP flushes the bytes after
     * it. Two headers are not optional — `Content-Disposition: attachment`, so
     * an uploaded file is never rendered as a page on this origin, and
     * `nosniff`, so the browser does not go looking for a better content type
     * than the one we sent.
     */
    public function download(Request $request, Identity $identity): Response
    {
        $opened = $this->attachments->openForDownload(
            $identity,
            $request->uuidParam('id'),
            $request->uuidParam('attachmentId'),
            $request->bearerToken,
        );

        /** @var array<string, mixed> $attachment */
        $attachment = $opened['attachment'];
        /** @var ObjectStore $store */
        $store = $opened['store'];

        $key = (string) $attachment['storage_key'];
        if ($key === '') {
            // The row outlived its object — a purge job has already run.
            throw ApiException::notFound('That file');
        }

        // A redirect is only usable if the browser is allowed to follow it.
        //
        // The SPA fetches this endpoint rather than navigating to it, because
        // the endpoint needs a Bearer token — and a `fetch` that follows a
        // redirect to another origin is subject to that origin's CORS rules.
        // Drive's private bucket lists exactly one origin
        // (`drive-react-app/server-php/scripts/cors-prod.json`:
        // `https://drive.aicountly.com`), and Notes is not it. So a 302 to S3
        // is not an optimisation here, it is a file that will not open:
        // "Failed to fetch", no status, nothing in the network tab but a
        // cancelled request.
        //
        // Notes therefore serves the bytes itself by default. Drive's own
        // README gives the same advice for the upload direction — "prefer a
        // server-side S3 PUT from the product API when the product origin is
        // not on the Drive S3 CORS allowlist" — and a download is the same
        // problem pointing the other way.
        //
        // An operator who has added this deployment's origin to the bucket's
        // allowlist (docs/S3_CORS.md in drive-react-app says how) can turn the
        // redirect back on and keep the megabytes out of PHP. Off by default,
        // because the default has to be the one that works.
        $signed = strtolower(Env::get('NOTES_DRIVE_DIRECT_DOWNLOAD', 'false')) === 'true'
            ? $store->signedUrl($key, self::SIGNED_URL_TTL_SECONDS)
            : null;

        if ($signed !== null) {
            // The store can serve the file itself. The check above is still
            // what issued the link, and it expires in two minutes.
            return (new Response(302, null))->withHeader('Location', $signed);
        }

        ob_start();
        try {
            $store->stream($key);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (new Response(200, null))
            ->withHeader('Content-Type', (string) $attachment['mime_type'])
            ->withHeader('Content-Length', (string) ob_get_length())
            ->withHeader('Content-Disposition', self::disposition((string) $attachment['filename']))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    // -----------------------------------------------------------------------

    /**
     * The uploaded bytes, from whichever shape the client used.
     *
     * @return array{filename: string, bytes: string, content_type: string, block_id: ?string, id: ?string}
     */
    private static function payload(Request $request): array
    {
        $file = $_FILES['file'] ?? null;
        if (is_array($file)) {
            return self::fromMultipart($file, $_POST['block_id'] ?? null, $_POST['id'] ?? null);
        }

        return self::fromJson($request);
    }

    /**
     * @param array<string, mixed> $file
     * @return array{filename: string, bytes: string, content_type: string, block_id: ?string, id: ?string}
     */
    private static function fromMultipart(array $file, mixed $blockId, mixed $id): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => ApiException::validation([
                    'file' => 'That file is larger than this server accepts.',
                ]),
                UPLOAD_ERR_NO_FILE => ApiException::validation(['file' => 'Choose a file to attach.']),
                default => ApiException::badRequest('The upload did not complete — please try again.'),
            };
        }

        $temporary = (string) ($file['tmp_name'] ?? '');
        // The only way a path is a genuine upload. Without this check a request
        // that could influence the path would make this endpoint read arbitrary
        // files off the server and store them on a note.
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw ApiException::badRequest('The upload did not complete — please try again.');
        }

        $bytes = file_get_contents($temporary);
        if ($bytes === false) {
            throw ApiException::badRequest('The upload could not be read — please try again.');
        }

        return [
            'filename' => (string) ($file['name'] ?? ''),
            'bytes' => $bytes,
            // A claim, checked against the content downstream.
            'content_type' => (string) ($file['type'] ?? ''),
            'block_id' => is_scalar($blockId) ? (string) $blockId : null,
            'id' => is_scalar($id) ? (string) $id : null,
        ];
    }

    /**
     * @return array{filename: string, bytes: string, content_type: string, block_id: ?string, id: ?string}
     */
    private static function fromJson(Request $request): array
    {
        $encoded = $request->string('content_base64');
        $contentType = $request->string('content_type');

        // A clipboard paste arrives as a data URL, which carries its own type
        // claim in front of the payload.
        if ($encoded === '' && str_starts_with($request->string('content'), 'data:')) {
            [$contentType, $encoded] = self::splitDataUrl($request->string('content'), $contentType);
        }

        if ($encoded === '') {
            // Also where an upload over `post_max_size` lands: PHP discards the
            // body entirely, so neither $_FILES nor the JSON has anything in it.
            throw ApiException::validation(['file' => 'There is nothing to attach.']);
        }

        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw ApiException::validation(['content_base64' => 'That file could not be decoded.']);
        }

        return [
            'filename' => $request->string('filename'),
            'bytes' => $bytes,
            'content_type' => $contentType,
            'block_id' => $request->nullableString('block_id'),
            'id' => $request->nullableString('id'),
        ];
    }

    /** @return array{0: string, 1: string} The declared type and the base64 payload. */
    private static function splitDataUrl(string $dataUrl, string $fallbackType): array
    {
        $comma = strpos($dataUrl, ',');
        if ($comma === false || !str_contains(substr($dataUrl, 0, $comma), ';base64')) {
            throw ApiException::validation(['content' => 'That file could not be decoded.']);
        }

        $header = substr($dataUrl, 5, $comma - 5);
        $declared = trim(explode(';', $header)[0]);

        return [$declared !== '' ? $declared : $fallbackType, substr($dataUrl, $comma + 1)];
    }

    /**
     * `Content-Disposition`, safe for any filename a user chose.
     *
     * Two forms because they are read by different browsers: a quoted ASCII
     * fallback with everything unusual replaced, and the RFC 5987 form that
     * carries the real name. Quotes and control characters are stripped from
     * the fallback — a filename ends up inside a header, and a header that can
     * be broken out of is a response-splitting bug.
     */
    private static function disposition(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $filename) ?? 'attachment';
        $ascii = str_replace(['\\', '"', ';'], '_', $ascii);

        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $ascii === '' ? 'attachment' : $ascii,
            rawurlencode($filename),
        );
    }
}
