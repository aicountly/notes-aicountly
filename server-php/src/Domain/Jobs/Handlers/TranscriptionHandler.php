<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs\Handlers;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Attachments\AttachmentService;
use Aicountly\Api\Domain\Jobs\JobHandler;
use Aicountly\Api\Domain\Jobs\JobQueue;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Speech into words.
 *
 * The same adapter shape as {@see OcrHandler} and the same honesty rule: no
 * local engine exists, so with `NOTES_TRANSCRIPTION_ENABLED` off the job is
 * skipped rather than failed, and a voice note simply stays a voice note.
 *
 * What it writes is deliberately split in two. The transcript itself — with the
 * segments and speaker turns the engine returned — goes to `note_transcripts`,
 * which is where a correction can be recorded without touching the machine
 * output. A flat copy goes on the attachment so the rollup can put it in the
 * note's search text. The audio is never modified and the note's own text is
 * never overwritten.
 */
final class TranscriptionHandler implements JobHandler
{
    private const LISTENABLE = ['audio', 'video'];

    public function __construct(
        private readonly AttachmentService $attachments = new AttachmentService(),
        private readonly JobQueue $jobs = new JobQueue(),
        private readonly ?RemoteEngine $engine = null,
    ) {
    }

    public function handle(array $job): array
    {
        $engine = $this->engine ?? RemoteEngine::transcription();

        if (!$engine->available()) {
            return ['outcome' => self::SKIPPED, 'reason' => $engine->unavailableReason()];
        }

        $attachmentId = $job['attachment_id'] ?? null;
        $attachment = is_string($attachmentId) ? $this->attachments->find($attachmentId) : null;
        if ($attachment === null || $attachment['deleted_at'] !== null) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'attachment_missing'];
        }
        if (!in_array((string) $attachment['kind'], self::LISTENABLE, true)) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'unsupported_mime'];
        }

        $bytes = $this->attachments->storeFor($attachment)->get((string) $attachment['storage_key']);
        $result = $engine->process((string) $attachment['filename'], (string) $attachment['mime_type'], $bytes);

        if ($result['status'] >= 400 && $result['status'] < 500 && $result['status'] !== 429) {
            $this->recordTranscript($attachment, $result, 'failed');

            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'engine_rejected_' . $result['status']];
        }
        if ($result['status'] !== 200) {
            throw new \RuntimeException('engine_status_' . $result['status']);
        }

        $text = trim($result['text']);
        $this->recordTranscript($attachment, $result, 'completed');

        if ($text === '') {
            return ['outcome' => self::COMPLETED, 'characters' => 0, 'reason' => 'no_speech_found'];
        }

        $text = Str::limit($text, AttachmentService::MAX_EXTRACTED_CHARS);
        $this->attachments->recordExtractedText((string) $attachment['id'], $text);
        $this->jobs->enqueue(
            (string) $job['requested_by'],
            JobQueue::DERIVED_TEXT,
            (string) $attachment['note_id'],
        );

        return [
            'outcome' => self::COMPLETED,
            'characters' => mb_strlen($text, 'UTF-8'),
            'segments' => count($result['segments']),
            'model' => $result['model'],
        ];
    }

    /**
     * One transcript row per attachment, rewritten on a re-run.
     *
     * A user's correction lives in `edited_text`, so replacing the machine
     * `text` here never destroys it.
     *
     * @param array<string, mixed> $attachment
     * @param array<string, mixed> $result
     */
    private function recordTranscript(array $attachment, array $result, string $status): void
    {
        $existing = Connection::selectOne(
            'SELECT id FROM note_transcripts WHERE attachment_id = :attachment_id',
            ['attachment_id' => $attachment['id']],
        );

        $bindings = [
            'note_id' => $attachment['note_id'],
            'attachment_id' => $attachment['id'],
            'model' => $result['model'],
            'language' => $result['language'],
            'status' => $status,
            'text' => Str::limit(trim((string) $result['text']), AttachmentService::MAX_EXTRACTED_CHARS),
            'segments' => json_encode($result['segments'], JSON_UNESCAPED_SLASHES),
        ];

        if ($existing !== null) {
            Connection::execute(
                'UPDATE note_transcripts
                    SET model = :model, language = :language, status = :status,
                        text = :text, segments = :segments::jsonb, updated_at = now()
                  WHERE id = :id',
                $bindings + ['id' => $existing['id']],
            );

            return;
        }

        Connection::execute(
            'INSERT INTO note_transcripts
                (id, note_id, attachment_id, provider, model, language, status, text, segments)
             VALUES (:id, :note_id, :attachment_id, \'remote\', :model, :language, :status, :text, :segments::jsonb)',
            $bindings + ['id' => Uuid::v4()],
        );
    }
}
