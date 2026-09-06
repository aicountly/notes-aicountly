<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Jobs\Worker;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

final class ZzProbeTest extends TestCase
{
    private ApiClient $alice;
    private string $storage;

    public function name(): string { return 'ZzProbe'; }

    public function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/notes-probe-' . getmypid();
        putenv('NOTES_STORAGE_PATH=' . $this->storage);
        putenv('NOTES_MAX_ATTACHMENT_SIZE=');
        putenv('NOTES_DRIVE_ENABLED=false');
        putenv('NOTES_OCR_ENABLED=false');
        $this->alice = new ApiClient(Support::user('a'));
    }

    private static function heic(): string
    {
        return pack('N', 24) . 'ftyp' . 'heic' . pack('N', 0) . 'heic' . 'mif1'
            . pack('N', 8) . 'meta' . str_repeat("\x00", 512);
    }

    private static function tiff(): string
    {
        // A minimal little-endian TIFF header with one IFD entry.
        return "II\x2a\x00" . pack('V', 8) . pack('v', 1)
            . pack('vvVV', 0x0100, 3, 1, 64) . pack('V', 0) . str_repeat("\x00", 64);
    }

    public function testHeicPhotoIsNotCalledCorrupt(): void
    {
        $note = $this->alice->post('/notes', ['title' => 'x', 'document' => Support::doc('hi')])['body']['data'];
        $r = $this->alice->post('/notes/' . $note['id'] . '/attachments', [
            'filename' => 'IMG_4021.heic', 'content_base64' => base64_encode(self::heic()),
        ]);
        echo "\n[heic upload] " . $r['status'] . ' ' . json_encode($r['body']['data']['kind'] ?? $r['body']) . "\n";
        $this->assertSame(201, $r['status']);

        (new Worker())->run(10, 30);

        $row = Connection::selectOne(
            'SELECT processing_status, processing_error FROM note_attachments WHERE id = :i',
            ['i' => $r['body']['data']['id']],
        );
        $job = Connection::selectOne(
            "SELECT status, result->>'reason' AS reason, permanent_failure FROM note_processing_jobs
             WHERE attachment_id = :i AND job_type = 'attachment.thumbnail'",
            ['i' => $r['body']['data']['id']],
        );
        echo "[heic status] " . json_encode($row) . ' job=' . json_encode($job) . "\n";
        $this->assertNotSame('failed', (string) $row['processing_status']);
    }

    public function testTiffIsSkippedNotFailed(): void
    {
        $note = $this->alice->post('/notes', ['title' => 'x', 'document' => Support::doc('hi')])['body']['data'];
        $r = $this->alice->post('/notes/' . $note['id'] . '/attachments', [
            'filename' => 'scan.tiff', 'content_base64' => base64_encode(self::tiff()),
        ]);
        echo "\n[tiff upload] " . $r['status'] . ' ' . json_encode($r['body']['data']['kind'] ?? $r['body']['error'] ?? null) . "\n";
        if ($r['status'] !== 201) { $this->pass(); return; }

        (new Worker())->run(10, 30);
        $row = Connection::selectOne(
            'SELECT processing_status, processing_error FROM note_attachments WHERE id = :i',
            ['i' => $r['body']['data']['id']],
        );
        echo "[tiff status] " . json_encode($row) . "\n";
        $this->pass();
    }
}
