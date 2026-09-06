<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Controllers\AttachmentsController;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Attachments over the real router.
 *
 * An attachment endpoint is the part of a notes app most likely to become
 * someone else's foothold: it takes bytes from a browser, stores them on a
 * shared host inside the document root, and serves them back from the origin
 * the app's session cookie belongs to. So most of what is asserted here is what
 * the endpoint *refuses* — a PNG that is really a PHP script, an HTML file, a
 * file bigger than the limit, and every one of these routes reached by somebody
 * the note was never shared with.
 */
final class AttachmentsTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;
    private string $storage;

    public function name(): string
    {
        return 'Attachments';
    }

    public function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/notes-attachments-' . getmypid();
        self::removeTree($this->storage);

        // Never the deployed storage path: this suite writes and deletes files.
        putenv('NOTES_STORAGE_PATH=' . $this->storage);
        putenv('NOTES_MAX_ATTACHMENT_SIZE=');
        putenv('NOTES_DRIVE_ENABLED=false');
        putenv('DRIVE_API_URL=');

        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    // -- Fixtures -----------------------------------------------------------

    private function note(): array
    {
        return $this->alice->post('/notes', [
            'title' => 'Board pack',
            'document' => Support::doc('the quarterly numbers'),
        ])['body']['data'];
    }

    /** A real PNG, made by GD rather than pasted in as a constant. */
    private static function png(int $width = 8, int $height = 8): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 40, 40));

        $stream = fopen('php://memory', 'r+b');
        imagepng($image, $stream);
        imagedestroy($image);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    private function upload(ApiClient $api, string $noteId, string $filename, string $bytes, array $extra = []): array
    {
        return $api->post('/notes/' . $noteId . '/attachments', array_merge([
            'filename' => $filename,
            'content_base64' => base64_encode($bytes),
        ], $extra));
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }

    private static function share(string $noteId, string $userId, string $role): void
    {
        Connection::execute(
            'INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, :user, :role, \'user-a\')',
            ['id' => Uuid::v4(), 'note' => $noteId, 'user' => $userId, 'role' => $role],
        );
    }

    /** @return array<string, string> The headers a Response carries. */
    private static function headersOf(Response $response): array
    {
        $property = (new \ReflectionClass($response))->getProperty('headers');

        /** @var array<string, string> $headers */
        $headers = $property->getValue($response);

        return $headers;
    }

    // -- Upload -------------------------------------------------------------

    public function testStoresAPastedImageAndReturnsWithoutProcessingIt(): void
    {
        $note = $this->note();
        $bytes = self::png();

        $created = $this->upload($this->alice, $note['id'], 'screenshot.png', $bytes);
        $this->assertSame(201, $created['status']);

        $attachment = $created['body']['data'];
        $this->assertSame('image/png', $attachment['mime_type'], 'the type comes from the bytes');
        $this->assertSame('image', $attachment['kind']);
        $this->assertSame(strlen($bytes), $attachment['byte_size']);
        $this->assertSame(hash('sha256', $bytes), $attachment['checksum_sha256']);
        $this->assertSame('local', $attachment['storage_provider']);
        // The request did not wait for a thumbnail, let alone for OCR.
        $this->assertSame('queued', $attachment['processing_status']);
        $this->assertFalse($attachment['has_thumbnail']);
        $this->assertSame(
            '/notes/' . $note['id'] . '/attachments/' . $attachment['id'] . '/content',
            $attachment['content_url'],
        );

        // The work is queued rather than done: a thumbnail and an OCR pass.
        $jobs = Connection::select(
            'SELECT job_type FROM note_processing_jobs WHERE attachment_id = :id ORDER BY job_type',
            ['id' => $attachment['id']],
        );
        $this->assertSame(
            ['attachment.ocr', 'attachment.thumbnail'],
            array_column($jobs, 'job_type'),
        );

        // The note now says it has a file, which is what the list card renders.
        $this->assertSame(1, $this->alice->get('/notes/' . $note['id'])['body']['data']['attachment_count']);
    }

    public function testAcceptsAClipboardDataUrl(): void
    {
        $note = $this->note();

        $created = $this->alice->post('/notes/' . $note['id'] . '/attachments', [
            'filename' => 'pasted.png',
            'content' => 'data:image/png;base64,' . base64_encode(self::png()),
        ]);

        $this->assertSame(201, $created['status']);
        $this->assertSame('image/png', $created['body']['data']['mime_type']);
    }

    public function testStoredKeysRevealNothingAndAreNotServable(): void
    {
        $note = $this->note();
        $created = $this->upload($this->alice, $note['id'], 'Payroll summary.png', self::png());

        $key = (string) Connection::selectOne(
            'SELECT storage_key FROM note_attachments WHERE id = :id',
            ['id' => $created['body']['data']['id']],
        )['storage_key'];

        // The key is allocated, not derived: a path must not tell anyone what a
        // document is about, and must not be guessable from the filename.
        $this->assertSame(1, preg_match('#^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}$#', $key));
        $this->assertFalse(str_contains(strtolower($key), 'payroll'));

        // On cPanel this directory sits inside the document root, so the store
        // writes its own deny-all rather than trusting the deployment to.
        $htaccess = (string) @file_get_contents($this->storage . '/.htaccess');
        $this->assertContainsString('Require all denied', $htaccess);
        $this->assertContainsString('Deny from all', $htaccess);
    }

    public function testReplayingAQueuedUploadDoesNotStoreItTwice(): void
    {
        $note = $this->note();
        $id = Uuid::v4();

        $first = $this->upload($this->alice, $note['id'], 'receipt.png', self::png(), ['id' => $id]);
        $second = $this->upload($this->alice, $note['id'], 'receipt.png', self::png(), ['id' => $id]);

        $this->assertSame(201, $second['status']);
        $this->assertSame($first['body']['data']['id'], $second['body']['data']['id']);
        $this->assertCount(1, $this->alice->get('/notes/' . $note['id'] . '/attachments')['body']['data']);
    }

    // -- What it refuses ----------------------------------------------------

    public function testRefusesAFileThatIsNotWhatItsNameSays(): void
    {
        $note = $this->note();

        // A PNG called .pdf. Believing the name is how a file ends up served as
        // something it is not.
        $result = $this->upload($this->alice, $note['id'], 'invoice.pdf', self::png());
        $this->assertSame(422, $result['status']);
        $this->assertSame('VALIDATION_FAILED', $result['body']['error']['code']);

        // And a PNG whose declared Content-Type disagrees with its bytes.
        $declared = $this->upload($this->alice, $note['id'], 'sheet.png', self::png(), [
            'content_type' => 'application/pdf',
        ]);
        $this->assertSame(422, $declared['status']);

        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/attachments')['body']['data']);
    }

    public function testRefusesHtmlAndExecutablesHoweverTheyAreNamed(): void
    {
        $note = $this->note();

        // HTML stored here would be served from this origin, with the user's
        // own session attached to it.
        $html = $this->upload($this->alice, $note['id'], 'notes.txt', '<html><body><script>alert(1)</script></body></html>');
        $this->assertSame(422, $html['status']);

        $svg = $this->upload($this->alice, $note['id'], 'diagram.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertSame(422, $svg['status']);

        // Plain text is a fine attachment; the extension is what makes this one
        // a program on a PHP host.
        $php = $this->upload($this->alice, $note['id'], 'avatar.php', 'just some text');
        $this->assertSame(422, $php['status']);

        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/attachments')['body']['data']);
    }

    public function testEnforcesTheAttachmentSizeLimit(): void
    {
        putenv('NOTES_MAX_ATTACHMENT_SIZE=2048');
        $note = $this->note();

        $result = $this->upload($this->alice, $note['id'], 'big.txt', str_repeat('a', 4096));
        $this->assertSame(422, $result['status']);
        $this->assertContainsString('MB or smaller', $result['body']['error']['details']['fields']['file']);

        $small = $this->upload($this->alice, $note['id'], 'small.txt', str_repeat('a', 512));
        $this->assertSame(201, $small['status']);

        putenv('NOTES_MAX_ATTACHMENT_SIZE=');
    }

    public function testRefusesAnEmptyUpload(): void
    {
        $note = $this->note();

        $this->assertSame(422, $this->alice->post('/notes/' . $note['id'] . '/attachments', [
            'filename' => 'nothing.txt',
        ])['status']);
        $this->assertSame(422, $this->upload($this->alice, $note['id'], 'empty.txt', '')['status']);
    }

    // -- Download -----------------------------------------------------------

    public function testDownloadStreamsTheBytesAsAnAttachment(): void
    {
        $note = $this->note();
        $bytes = self::png();
        $attachment = $this->upload($this->alice, $note['id'], 'screenshot.png', $bytes)['body']['data'];

        $result = $this->alice->get($attachment['content_url']);
        // The handler writes the body into an output buffer so Response::send()
        // still owns the headers; this is that buffer.
        $streamed = (string) ob_get_clean();

        $this->assertSame(200, $result['status']);
        $this->assertSame($bytes, $streamed, 'the bytes come back unchanged');

        // The headers are not visible through the router, so the same call is
        // made directly to assert them.
        $response = $this->downloadDirectly(Support::user('a'), $note['id'], $attachment['id']);
        $headers = self::headersOf($response);
        ob_end_clean();

        $this->assertSame(200, $response->status);
        $this->assertSame('image/png', $headers['Content-Type']);
        $this->assertSame((string) strlen($bytes), $headers['Content-Length']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        // Never inline: an uploaded file must not be rendered as a page here.
        $this->assertContainsString('attachment; filename="screenshot.png"', $headers['Content-Disposition']);
    }

    public function testADownloadFilenameCannotBreakOutOfItsHeader(): void
    {
        $note = $this->note();
        $attachment = $this->upload(
            $this->alice,
            $note['id'],
            "../../evil\"; drop=1\r\nX-Injected: yes.png",
            self::png(),
        )['body']['data'];

        // Stored under a name with no path and no quotes left in it.
        $this->assertFalse(str_contains($attachment['filename'], '/'));
        $this->assertFalse(str_contains($attachment['filename'], '"'));

        $response = $this->downloadDirectly(Support::user('a'), $note['id'], $attachment['id']);
        $disposition = self::headersOf($response)['Content-Disposition'];
        ob_end_clean();

        $this->assertFalse(str_contains($disposition, "\r"));
        $this->assertFalse(str_contains($disposition, "\n"));
    }

    private function downloadDirectly(Identity $identity, string $noteId, string $attachmentId): Response
    {
        $request = Request::forTesting('GET', 'notes/' . $noteId . '/attachments/' . $attachmentId . '/content');
        $request->routeParams = ['id' => $noteId, 'attachmentId' => $attachmentId];

        return (new AttachmentsController())->download($request, $identity);
    }

    // -- Delete -------------------------------------------------------------

    public function testDeletingAnAttachmentIsSoftAndQueuesTheObjectForRemoval(): void
    {
        $note = $this->note();
        $attachment = $this->upload($this->alice, $note['id'], 'receipt.png', self::png())['body']['data'];

        $key = (string) Connection::selectOne(
            'SELECT storage_key FROM note_attachments WHERE id = :id',
            ['id' => $attachment['id']],
        )['storage_key'];
        $this->assertTrue(is_file($this->storage . '/' . $key), 'the object was written');

        $this->assertSame(204, $this->alice->delete('/notes/' . $note['id'] . '/attachments/' . $attachment['id'])['status']);

        // Gone from the note, still on the row, and the bytes are the worker's
        // problem rather than the request's.
        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/attachments')['body']['data']);
        $this->assertSame(0, $this->alice->get('/notes/' . $note['id'])['body']['data']['attachment_count']);
        $this->assertNotNull(Connection::selectOne(
            'SELECT deleted_at FROM note_attachments WHERE id = :id AND deleted_at IS NOT NULL',
            ['id' => $attachment['id']],
        ));
        $this->assertTrue(is_file($this->storage . '/' . $key), 'the object outlives the request');

        $purge = Connection::selectOne(
            'SELECT payload, attachment_id, note_id FROM note_processing_jobs
             WHERE job_type = \'attachment.object_purge\'',
        );
        $this->assertNotNull($purge);
        // Deliberately unlinked from the row it cleans up after: a foreign key
        // there would cascade the job away when the note is purged.
        $this->assertNull($purge['attachment_id']);
        $this->assertNull($purge['note_id']);
        $this->assertContainsString($key, (string) $purge['payload']);

        $this->assertSame(404, $this->alice->get($attachment['content_url'])['status']);
    }

    // -- Drive --------------------------------------------------------------

    public function testLinkingADriveFileIsRefusedUntilDriveIsConfigured(): void
    {
        $note = $this->note();

        $result = $this->alice->post('/notes/' . $note['id'] . '/attachments/link-drive', [
            'drive_file_id' => 'drive-file-123',
        ]);

        // Not a fabricated attachment and not a silent success: the deployment
        // has no Drive, and says so.
        $this->assertSame(503, $result['status']);
        $this->assertSame('FEATURE_DISABLED', $result['body']['error']['code']);
        $this->assertSame('drive', $result['body']['error']['details']['feature']);
        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/attachments')['body']['data']);
    }

    public function testAnUnreachableDriveIsReportedRatherThanFaked(): void
    {
        // Configured, but pointed at a port nothing listens on.
        putenv('NOTES_DRIVE_ENABLED=true');
        putenv('DRIVE_API_URL=http://127.0.0.1:9');

        $note = $this->note();
        $result = $this->alice->post('/notes/' . $note['id'] . '/attachments/link-drive', [
            'drive_file_id' => 'drive-file-123',
        ]);

        putenv('NOTES_DRIVE_ENABLED=false');
        putenv('DRIVE_API_URL=');

        $this->assertSame(502, $result['status']);
        $this->assertSame('UPSTREAM_UNAVAILABLE', $result['body']['error']['code']);
        $this->assertCount(0, $this->alice->get('/notes/' . $note['id'] . '/attachments')['body']['data']);
    }

    // -- Somebody else's note -----------------------------------------------

    public function testAStrangerCannotReachAnyAttachmentEndpoint(): void
    {
        $note = $this->note();
        $attachment = $this->upload($this->alice, $note['id'], 'private.png', self::png())['body']['data'];
        $base = '/notes/' . $note['id'] . '/attachments';

        // 404 everywhere, not 403: an attachment id must not confirm that a
        // note exists, let alone that it has files on it.
        $this->assertSame(404, $this->bob->get($base)['status']);
        $this->assertSame(404, $this->bob->get($base . '/' . $attachment['id'])['status']);
        $this->assertSame(404, $this->bob->get($base . '/' . $attachment['id'] . '/content')['status']);
        $this->assertSame(404, $this->bob->delete($base . '/' . $attachment['id'])['status']);
        $this->assertSame(404, $this->upload($this->bob, $note['id'], 'theirs.png', self::png())['status']);
        $this->assertSame(404, $this->bob->post($base . '/link-drive', ['drive_file_id' => 'x'])['status']);

        // Nothing Bob tried changed anything.
        $this->assertCount(1, $this->alice->get($base)['body']['data']);
    }

    public function testAViewerCanReadTheFilesButNotAddOrRemoveThem(): void
    {
        $note = $this->note();
        $attachment = $this->upload($this->alice, $note['id'], 'shared.png', self::png())['body']['data'];
        self::share($note['id'], 'user-b', 'viewer');
        $base = '/notes/' . $note['id'] . '/attachments';

        $this->assertSame(200, $this->bob->get($base)['status']);
        $this->assertCount(1, $this->bob->get($base)['body']['data']);

        $download = $this->bob->get($base . '/' . $attachment['id'] . '/content');
        ob_end_clean();
        $this->assertSame(200, $download['status']);

        // Visible but not writable: 403 rather than 404, because Bob can see
        // the note and hiding the reason would only be confusing.
        $upload = $this->upload($this->bob, $note['id'], 'mine.png', self::png());
        $this->assertSame(403, $upload['status']);
        $this->assertSame('NOTE_ACCESS_DENIED', $upload['body']['error']['code']);
        $this->assertSame(403, $this->bob->delete($base . '/' . $attachment['id'])['status']);

        $this->assertCount(1, $this->alice->get($base)['body']['data']);
    }

    public function testAMalformedIdIsNotFoundRatherThanAServerError(): void
    {
        $note = $this->note();

        $this->assertSame(404, $this->alice->get('/notes/' . $note['id'] . '/attachments/not-a-uuid')['status']);
        $this->assertSame(404, $this->alice->get('/notes/not-a-uuid/attachments')['status']);
        $this->assertSame(404, $this->alice->get(
            '/notes/' . $note['id'] . '/attachments/' . Uuid::v4() . '/content',
        )['status']);
    }
}
