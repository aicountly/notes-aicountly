<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Jobs\Handlers;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Attachments\AttachmentService;
use Aicountly\Api\Domain\Jobs\JobHandler;

/**
 * Fold what the attachments say into the note's search text.
 *
 * `notes.derived_text` is the third input to the note's generated tsvector, at
 * weight C — under the title and under what the user typed, which is the right
 * order when the match came out of a photograph. Until this job runs, an OCR
 * result exists but is not findable, so every handler that produces text asks
 * for this one afterwards.
 *
 * It is a rollup rather than an append. Re-running it is harmless, a
 * re-processed attachment does not double its own text, and a deleted
 * attachment takes its text back out of the index — none of which is true of a
 * job that concatenates onto whatever was there before.
 */
final class DerivedTextHandler implements JobHandler
{
    public function __construct(private readonly AttachmentService $attachments = new AttachmentService())
    {
    }

    public function handle(array $job): array
    {
        $noteId = $job['note_id'] ?? null;
        if (!is_string($noteId)) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'note_missing'];
        }

        $note = Connection::selectOne(
            'SELECT id, privacy_mode FROM notes WHERE id = :id AND deleted_at IS NULL',
            ['id' => $noteId],
        );
        if ($note === null) {
            return ['outcome' => self::PERMANENT_FAILURE, 'reason' => 'note_missing'];
        }

        if ((string) $note['privacy_mode'] === 'private') {
            // A private note is ciphertext the server cannot read. Indexing
            // text derived from its attachments would make part of it
            // searchable here, which is exactly what the mode promises not to
            // happen.
            return ['outcome' => self::SKIPPED, 'reason' => 'private_note'];
        }

        return [
            'outcome' => self::COMPLETED,
            'characters' => $this->attachments->rebuildDerivedText($noteId),
        ];
    }
}
