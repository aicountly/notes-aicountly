<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Attachments\AttachmentService;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\CompanyContext;
use Aicountly\Api\Integrations\AicountlyClient;
use Aicountly\Api\Integrations\DriveContext;
use Aicountly\Api\Integrations\DriveDocumentService;
use Aicountly\Api\Integrations\DriveObjectStore;
use Aicountly\Api\Integrations\HttpTransport;
use Aicountly\Api\Integrations\LocalObjectStore;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Storing a note's file in AICOUNTLY Drive.
 *
 * Drive does not take bytes through its API. It hands out a presigned S3 URL,
 * the bytes go there, and Drive is then told to promote them — four calls to
 * Drive with one PUT to an object store in the middle. An earlier version of
 * this integration POSTed the file to Drive and would simply never have worked,
 * which is why the sequence itself is what most of this file asserts.
 *
 * Everything below drives the real {@see DriveObjectStore} with a recording
 * transport in place of the socket. That is the lowest seam there is, so what a
 * test sees is the exact request a deployment would send — including, crucially,
 * the headers that are *absent*: the presigned PUT must not carry the caller's
 * ses_key, because that hands a live AICOUNTLY session to a third-party object
 * store and to every log between here and it.
 */
final class DriveUploadTest extends TestCase
{
    private const NOTE_ID = '11111111-2222-4333-8444-555555555555';
    private const SES_KEY = 'ses_alice_key';
    private const UPLOAD_URL = 'https://s3.example.test/quarantine/incoming/product/notes/upload/UPL00042/original/x.png?X-Amz-Signature=abc';

    private ApiClient $alice;

    public function name(): string
    {
        return 'Drive upload';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        CompanyContext::clear();
    }

    // -- Fixtures -----------------------------------------------------------

    /**
     * Run one test with Drive switched on, and switch it off again afterwards.
     *
     * The runner shares one PHP process across every case, so a flag left on
     * here would change what a later, unrelated test does. The origin points at
     * a name that does not resolve, so a code path that slipped past the fake
     * transport could not reach anything real.
     */
    private function withDrive(callable $work): void
    {
        putenv('NOTES_DRIVE_ENABLED=true');
        putenv('DRIVE_API_ORIGIN=https://drive.example.test');

        try {
            $work();
        } finally {
            putenv('NOTES_DRIVE_ENABLED=false');
            putenv('DRIVE_API_ORIGIN');
            putenv('DRIVE_API_URL');
            CompanyContext::clear();
        }
    }

    /** A store wired to a transport that answers with the given replies, in order. */
    private static function store(RecordingTransport $transport, ?DriveContext $context = null): DriveObjectStore
    {
        return new DriveObjectStore(
            $context ?? DriveContext::forNote(self::SES_KEY, self::NOTE_ID, null),
            new DriveDocumentService(new AicountlyClient('drive', Features::DRIVE, 'drive', $transport)),
        );
    }

    /**
     * The replies a successful sequence gets: the four upload steps, then the
     * cross-reference back to the note (§29 step 6).
     */
    private static function happyPath(): RecordingTransport
    {
        return new RecordingTransport([
            RecordingTransport::json(201, ['session_id' => 77, 'upload_url' => self::UPLOAD_URL]),
            RecordingTransport::raw(200, ''),
            RecordingTransport::json(200, ['session_id' => 77, 'status' => 'uploaded']),
            RecordingTransport::json(201, ['id' => 4821, 'doc_ref' => 'DOC04821']),
            RecordingTransport::json(201, ['document_id' => 4821]),
        ]);
    }

    // -- The sequence -------------------------------------------------------

    public function testTheFourCallsHappenInOrderWithTheBytesGoingStraightToS3(): void
    {
        $this->withDrive(function (): void {
            $transport = self::happyPath();
            $bytes = 'the quarterly numbers, as a png';

            $key = self::store($transport)->put('ignored', $bytes, 'image/png', 'board pack.png');

            // What comes back is Drive's document id, not a key this API chose:
            // Drive builds the object key itself and only names the document
            // once the upload has been scanned and promoted.
            $this->assertSame('4821', $key);

            // Four steps — create, PUT, complete, finalize — and only the PUT
            // leaves the AICOUNTLY estate. The fifth call is the document-link
            // back to the note, which happens once the file is safely stored.
            $this->assertCount(5, $transport->calls);

            $create = $transport->calls[0];
            $this->assertSame('POST', $create['method']);
            $this->assertContainsString('https://drive.example.test/api/upload-sessions?', $create['url']);

            $put = $transport->calls[1];
            $this->assertSame('PUT', $put['method']);
            $this->assertSame(self::UPLOAD_URL, $put['url'], 'the bytes go to the presigned URL');
            $this->assertSame($bytes, $put['body'], 'unchanged, and not through Drive');

            $this->assertSame('POST', $transport->calls[2]['method']);
            $this->assertSame(
                'https://drive.example.test/api/upload-sessions/77/complete?cmp_id=0&fy_id=0&bo_id=0',
                $transport->calls[2]['url'],
            );

            $this->assertSame('POST', $transport->calls[3]['method']);
            $this->assertContainsString('/api/upload-sessions/77/finalize', $transport->calls[3]['url']);

            // Only after the bytes are stored, and never instead of storing them.
            $this->assertContainsString('/api/document-links', $transport->calls[4]['url']);

            // The session was not aborted after it succeeded.
            $this->assertCount(0, array_filter(
                $transport->calls,
                static fn (array $call): bool => str_contains($call['url'], '/abort'),
            ));
        });
    }

    public function testTheSessionKeyNeverReachesTheObjectStore(): void
    {
        $this->withDrive(function (): void {
            $transport = self::happyPath();
            self::store($transport)->put('', 'bytes', 'image/png', 'x.png');

            $put = $transport->calls[1];

            foreach ($put['headers'] as $header) {
                $this->assertFalse(
                    stripos($header, 'authorization') === 0,
                    'the presigned PUT must carry no Authorization header',
                );
            }

            // Belt and braces: the key must not have reached S3 by any other
            // route either — a query parameter, a custom header, the body.
            $this->assertFalse(
                str_contains(json_encode($put, JSON_UNESCAPED_SLASHES) ?: '', self::SES_KEY),
                'the ses_key appears nowhere in the request to the object store',
            );

            // And the calls that *are* to Drive still carry it, because Drive is
            // the product that decides whether this person may upload at all.
            $this->assertContainsString(
                'Authorization: Bearer ' . self::SES_KEY,
                implode("\n", $transport->calls[0]['headers']),
            );
        });
    }

    public function testTheChecksumIsSentSoDriveVerifiesTheBytesItStored(): void
    {
        $this->withDrive(function (): void {
            $transport = self::happyPath();
            $bytes = 'a file whose digest is worth checking';

            self::store($transport)->put('', $bytes, 'application/pdf', 'contract.pdf');

            $finalize = json_decode((string) $transport->calls[3]['body'], true);

            $this->assertSame(hash('sha256', $bytes), $finalize['sha256'] ?? null);
            $this->assertSame(strlen($bytes), $finalize['size_bytes'] ?? null);
            $this->assertSame('contract.pdf', $finalize['title'] ?? null);
        });
    }

    public function testTheSessionDeclaresNotesAsTheProductAndTheNoteAsTheEntity(): void
    {
        $this->withDrive(function (): void {
            $transport = self::happyPath();

            self::store($transport, DriveContext::forNote(
                self::SES_KEY,
                self::NOTE_ID,
                null,
                DriveContext::MODULE_VOICE_NOTES,
            ))->put('', 'audio', 'audio/mpeg', 'memo.mp3');

            $body = json_decode((string) $transport->calls[0]['body'], true);

            // These four are written into an S3 key that can never be corrected.
            $this->assertSame('notes', $body['product_code'] ?? null);
            $this->assertSame('note', $body['entity_type'] ?? null);
            $this->assertSame(self::NOTE_ID, $body['entity_id'] ?? null);
            $this->assertSame('voice-notes', $body['module_code'] ?? null);

            $this->assertSame('memo.mp3', $body['filename'] ?? null);
            $this->assertSame('audio/mpeg', $body['content_type'] ?? null);
        });
    }

    // -- Failure ------------------------------------------------------------

    public function testAFailedPutAbortsTheSessionAndFinalizesNothing(): void
    {
        $this->withDrive(function (): void {
            $transport = new RecordingTransport([
                RecordingTransport::json(201, ['session_id' => 77, 'upload_url' => self::UPLOAD_URL]),
                // The object store refuses the bytes — an expired signature, say.
                RecordingTransport::raw(403, '<Error>SignatureDoesNotMatch</Error>'),
                RecordingTransport::json(200, ['status' => 'aborted']),
            ]);

            $this->assertThrows('UPSTREAM_UNAVAILABLE', static function () use ($transport): void {
                self::store($transport)->put('', 'bytes', 'image/png', 'x.png');
            });

            $urls = array_column($transport->calls, 'url');
            $this->assertCount(3, $urls);
            $this->assertContainsString('/upload-sessions/77/abort', $urls[2]);

            // Nothing was completed or finalized: an object left in quarantine
            // with a session that thinks it is still pending is exactly what the
            // abort exists to prevent.
            $this->assertFalse(str_contains(implode(' ', $urls), '/complete'));
            $this->assertFalse(str_contains(implode(' ', $urls), '/finalize'));
        });
    }

    public function testAFailedFinalizeAbortsTheSessionToo(): void
    {
        $this->withDrive(function (): void {
            $transport = new RecordingTransport([
                RecordingTransport::json(201, ['session_id' => 77, 'upload_url' => self::UPLOAD_URL]),
                RecordingTransport::raw(200, ''),
                RecordingTransport::json(200, ['status' => 'uploaded']),
                // Drive's own checksum disagreed with the one that was sent.
                RecordingTransport::raw(400, '{"success":false,"error":"UPLOAD_FINALIZE_FAILED"}'),
                RecordingTransport::json(200, ['status' => 'aborted']),
            ]);

            $this->assertThrows('UPSTREAM_UNAVAILABLE', static function () use ($transport): void {
                self::store($transport)->put('', 'bytes', 'image/png', 'x.png');
            });

            $this->assertCount(5, $transport->calls);
            $this->assertContainsString('/upload-sessions/77/abort', $transport->calls[4]['url']);
        });
    }

    public function testAnAbortThatItselfFailsDoesNotHideWhyTheUploadFailed(): void
    {
        $this->withDrive(function (): void {
            $transport = new RecordingTransport([
                RecordingTransport::json(201, ['session_id' => 77, 'upload_url' => self::UPLOAD_URL]),
                RecordingTransport::raw(500, ''),
                RecordingTransport::raw(500, ''),
            ]);

            // The upload's failure, not the abort's: the caller has to hear the
            // one they can act on.
            $this->assertThrows('UPSTREAM_UNAVAILABLE', static function () use ($transport): void {
                self::store($transport)->put('', 'bytes', 'image/png', 'x.png');
            });
        });
    }

    // -- Scope --------------------------------------------------------------

    public function testAPersonalNoteUsesPersonalScopeAndDrivesNoCompanySentinel(): void
    {
        $this->withDrive(function (): void {
            // Even with a company selected in the UI: a personal note is not
            // filed under whichever company the user happened to be looking at.
            CompanyContext::capture(['cmp_id' => '42', 'fy_id' => '7', 'bo_id' => '3']);

            $transport = self::happyPath();
            self::store($transport)->put('', 'bytes', 'image/png', 'x.png');

            $this->assertSame('personal', json_decode((string) $transport->calls[0]['body'], true)['scope']);
            // All three literally 0 — Drive's documented "no company" sentinel,
            // which it accepts only for personal scope.
            $this->assertContainsString('cmp_id=0&fy_id=0&bo_id=0', $transport->calls[0]['url']);
        });
    }

    public function testACompanyNoteUsesCompanyScopeAndTheCallersCompanyContext(): void
    {
        $this->withDrive(function (): void {
            CompanyContext::capture(['cmp_id' => '42', 'fy_id' => '7', 'bo_id' => '3']);

            $transport = self::happyPath();
            $context = DriveContext::forNote(self::SES_KEY, self::NOTE_ID, 'company-uuid-abc');
            self::store($transport, $context)->put('', 'bytes', 'image/png', 'x.png');

            $this->assertSame('company', json_decode((string) $transport->calls[0]['body'], true)['scope']);
            $this->assertContainsString('cmp_id=42&fy_id=7&bo_id=3', $transport->calls[0]['url']);
        });
    }

    public function testACompanyNoteWithoutCompanyContextIsRefusedRatherThanGuessedAt(): void
    {
        $this->withDrive(function (): void {
            // Notes' tenant_id is the portal's company UUID; Drive keys on the
            // numeric cmp_id. Substituting one for the other would write the
            // file into a tree that is nobody's.
            $this->assertThrows('BAD_REQUEST', static function (): void {
                DriveContext::forNote(self::SES_KEY, self::NOTE_ID, 'company-uuid-abc');
            });
        });
    }

    public function testModulesAreChosenFromWhatNotesActuallyStores(): void
    {
        $this->assertSame('meeting-recordings', DriveContext::moduleFor('meeting', 'audio'));
        $this->assertSame('meeting-recordings', DriveContext::moduleFor('meeting', 'video'));
        $this->assertSame('voice-notes', DriveContext::moduleFor('voice', 'audio'));
        $this->assertSame('scans', DriveContext::moduleFor('scan', 'image'));
        $this->assertSame('scans', DriveContext::moduleFor('scan', 'pdf'));
        // A PDF stapled to a meeting note is an attachment, not a recording.
        $this->assertSame('attachments', DriveContext::moduleFor('meeting', 'pdf'));
        $this->assertSame('attachments', DriveContext::moduleFor('document', 'image'));
    }

    // -- Download -----------------------------------------------------------

    public function testADownloadAsksDriveForAPresignedUrlRatherThanTheBytes(): void
    {
        $this->withDrive(function (): void {
            $transport = new RecordingTransport([
                RecordingTransport::json(200, [
                    'url' => 'https://s3.example.test/private/tenant/user/x/doc.png?X-Amz-Signature=def',
                    'expires_in' => 300,
                ]),
            ]);

            $url = self::store($transport)->signedUrl('4821', 120);

            $this->assertSame('https://s3.example.test/private/tenant/user/x/doc.png?X-Amz-Signature=def', $url);
            $this->assertCount(1, $transport->calls, 'one call, and no body came back through this API');
            $this->assertSame('GET', $transport->calls[0]['method']);
            $this->assertContainsString('/api/documents/4821/download', $transport->calls[0]['url']);
        });
    }

    public function testTheDownloadEndpointHandsADriveFileToTheDriveStore(): void
    {
        $this->withDrive(function (): void {
            $note = $this->alice->post('/notes', ['document' => Support::doc('has a drive file')])['body']['data'];
            $attachmentId = Uuid::v4();

            // A row as the new upload path writes one: the storage key is the
            // Drive document id, and there is no drive_file_id because this is
            // a file Notes uploaded rather than one the user linked.
            Connection::execute(
                'INSERT INTO note_attachments
                    (id, note_id, storage_provider, storage_key, filename, mime_type,
                     byte_size, kind, upload_status, processing_status, created_by)
                 VALUES (:id, :note, \'drive\', \'4821\', \'board.png\', \'image/png\',
                     10, \'image\', \'ready\', \'skipped\', \'user-a\')',
                ['id' => $attachmentId, 'note' => $note['id']],
            );

            $opened = (new AttachmentService())->openForDownload(
                Support::user('a'),
                $note['id'],
                $attachmentId,
                'ses_alice_key',
            );

            // The store, not the bytes: the endpoint asks it for a presigned URL
            // and redirects, so megabytes never pass through PHP.
            $this->assertTrue($opened['store'] instanceof DriveObjectStore);
        });
    }

    // -- The flag, and the local store ---------------------------------------

    public function testWithDriveOffTheLocalStoreIsUnchanged(): void
    {
        $root = sys_get_temp_dir() . '/notes-drive-local-' . getmypid();
        putenv('NOTES_DRIVE_ENABLED=false');
        putenv('NOTES_STORAGE_PATH=' . $root);

        $store = new LocalObjectStore($root);
        $key = $store->allocateKey();

        // The local store hands back exactly the key it was given — the
        // return value only differs for a store that allocates its own.
        $this->assertSame($key, $store->put($key, 'bytes', 'image/png', 'x.png'));
        $this->assertSame('bytes', $store->get($key));
        $this->assertNull($store->signedUrl($key, 120), 'nothing to sign: the directory is denied to Apache');

        $store->delete($key);
        @unlink($root . '/.htaccess');
        putenv('NOTES_STORAGE_PATH');
    }

    public function testAnUploadThatNeverLandedIsRecordedAsFailedAndCanBeRetried(): void
    {
        // Configured, but pointed at a port nothing listens on, so the very
        // first call of the sequence fails and no session ever exists.
        putenv('NOTES_DRIVE_ENABLED=true');
        putenv('DRIVE_API_ORIGIN=http://127.0.0.1:9');

        $note = $this->alice->post('/notes', ['document' => Support::doc('losing a file')])['body']['data'];
        $attachmentId = Uuid::v4();
        $bytes = self::png();

        $failed = $this->alice->post('/notes/' . $note['id'] . '/attachments', [
            'id' => $attachmentId,
            'filename' => 'receipt.png',
            'content_base64' => base64_encode($bytes),
        ]);

        putenv('NOTES_DRIVE_ENABLED=false');
        putenv('DRIVE_API_ORIGIN');

        $this->assertSame(502, $failed['status']);
        $this->assertSame('UPSTREAM_UNAVAILABLE', $failed['body']['error']['code']);

        // The attempt is visible rather than silent, and names no object.
        $row = Connection::selectOne(
            'SELECT upload_status, storage_key, storage_provider, processing_status
             FROM note_attachments WHERE id = :id',
            ['id' => $attachmentId],
        );
        $this->assertNotNull($row);
        $this->assertSame('failed', (string) $row['upload_status']);
        $this->assertSame('drive', (string) $row['storage_provider']);
        $this->assertSame('', (string) $row['storage_key']);
        $this->assertSame('skipped', (string) $row['processing_status']);

        // No processing was queued for a file that does not exist.
        $this->assertCount(0, Connection::select(
            'SELECT id FROM note_processing_jobs WHERE attachment_id = :id',
            ['id' => $attachmentId],
        ));

        // Replaying the same id retries rather than handing back the failure:
        // the offline queue re-sends what it could not deliver, and a failed
        // attempt is not a create that already happened.
        $retried = $this->alice->post('/notes/' . $note['id'] . '/attachments', [
            'id' => $attachmentId,
            'filename' => 'receipt.png',
            'content_base64' => base64_encode($bytes),
        ]);

        $this->assertSame(201, $retried['status']);
        $this->assertSame('local', $retried['body']['data']['storage_provider']);
        $this->assertSame('ready', $retried['body']['data']['upload_status']);
        $this->assertCount(1, $this->alice->get('/notes/' . $note['id'] . '/attachments')['body']['data']);
    }

    /** A real PNG, so the type check that guards every upload has something to read. */
    private static function png(): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefilledrectangle($image, 0, 0, 7, 7, imagecolorallocate($image, 10, 90, 200));

        $stream = fopen('php://memory', 'r+b');
        imagepng($image, $stream);
        imagedestroy($image);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    /** @param callable(): void $work */
    private function assertThrows(string $code, callable $work): void
    {
        try {
            $work();
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);

            return;
        }

        $this->fail('expected ' . $code . ', nothing was thrown');
    }
}

/**
 * The socket, replaced.
 *
 * It records every exchange in full — method, URL, headers and body — and
 * answers from a queue of canned replies. Recording the headers is the point:
 * the property that matters most about the presigned PUT is a header it does
 * *not* have, and that cannot be asserted anywhere above this layer.
 */
final class RecordingTransport implements HttpTransport
{
    /** @var array<int, array{method: string, url: string, headers: array<int, string>, body: ?string}> */
    public array $calls = [];

    /** @param array<int, array{status: int, body: string}> $replies */
    public function __construct(private array $replies = [])
    {
    }

    /** @param array<string, mixed> $data */
    public static function json(int $status, array $data): array
    {
        // Drive's envelope, as `SesAuthController::jsonSuccess()` writes it.
        return [
            'status' => $status,
            'body' => (string) json_encode(['success' => true, 'data' => $data, 'errors' => []]),
        ];
    }

    public static function raw(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body];
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeoutSeconds,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        unset($connectTimeoutSeconds, $timeoutSeconds, $maxResponseBytes);

        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $reply = array_shift($this->replies);
        if ($reply === null) {
            // An unplanned call is a failure of the test's expectations, not a
            // service outage — so it is answered in a way nothing retries.
            return ['connection_failed' => false, 'status' => 418, 'body' => '{"unexpected":true}'];
        }

        return ['connection_failed' => false, 'status' => $reply['status'], 'body' => $reply['body']];
    }
}
