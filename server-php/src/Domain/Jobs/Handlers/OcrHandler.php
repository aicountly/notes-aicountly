<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs\Handlers;

use Aicountly\Api\Domain\Attachments\AttachmentService;
use Aicountly\Api\Domain\Jobs\JobHandler;
use Aicountly\Api\Domain\Jobs\JobQueue;
use Aicountly\Api\Support\Str;

/**
 * Words off a photograph.
 *
 * There is no OCR engine in PHP, so this handler is an adapter and nothing
 * more. With `NOTES_OCR_ENABLED` off — the default — it reports `skipped` and
 * the job is finished, not retried and not counted as an error: a deployment
 * without an OCR service is a smaller product, not a broken one, and a queue
 * full of red rows would say the opposite.
 *
 * The result lands on the attachment and is rolled into the note's
 * `derived_text`, which is the tsvector's weight-C input. That is the whole
 * point of running it: a photo of a receipt becomes something the user can find
 * by searching for what it says.
 */
final class OcrHandler implements JobHandler
{
    /** Kinds an OCR engine can do anything with. */
    private const READABLE = ['image', 'pdf'];

    public function __construct(
        private readonly AttachmentService $attachments = new AttachmentService(),
        private readonly JobQueue $jobs = new JobQueue(),
        private readonly ?RemoteEngine $engine = null,
    ) {
    }

    public function handle(array $job): array
    {
        $engine = $this->engine ?? RemoteEngine::ocr();

        // Checked before the file is loaded: reading 20 MB off disk to discover
        // there is nowhere to send it is work nobody asked for.
        if (!$engine->available()) {
            return ['outcome' => self::SKIPPED, 'reason' => $engine->unavailableReason()];
        }

        $attachmentId = $job['attachment_id'] ?? null;
        $attachment = is_string($attachmentId) ? $this->attachments->find($attachmentId) : null;
        if ($attachment === null || $attachment['deleted_at'] !== null) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'attachment_missing'];
        }
        if (!in_array((string) $attachment['kind'], self::READABLE, true)) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'unsupported_mime'];
        }

        $bytes = $this->attachments->storeFor($attachment)->get((string) $attachment['storage_key']);
        $result = $engine->process((string) $attachment['filename'], (string) $attachment['mime_type'], $bytes);

        if ($result['status'] >= 400 && $result['status'] < 500 && $result['status'] !== 429) {
            // The engine understood the request and refused it — an unsupported
            // format or a file it will not accept. Retrying sends the same
            // bytes to the same answer.
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'engine_rejected_' . $result['status']];
        }
        if ($result['status'] !== 200) {
            // 5xx and 429 are the engine asking for time, so the queue's
            // exponential backoff answers them.
            throw new \RuntimeException('engine_status_' . $result['status']);
        }

        $text = trim($result['text']);
        if ($text === '') {
            // A photo of a sunset has no words in it. That is a real outcome.
            return ['outcome' => self::COMPLETED, 'characters' => 0, 'reason' => 'no_text_found'];
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
            'model' => $result['model'],
        ];
    }
}
