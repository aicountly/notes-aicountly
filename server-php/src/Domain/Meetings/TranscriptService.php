<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Meetings;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Attachments\AttachmentService;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Transcripts, and the corrections people make to them.
 *
 * Machine transcription is wrong often enough that correcting it is part of
 * using it — a name, a number, a word the model guessed. The rule that makes
 * that safe is the one this class is built around:
 *
 *   **A correction never touches the source.** `text` stays exactly as the
 *   provider returned it, `segments` keep their timings and speaker turns, and
 *   the audio is not re-encoded, re-uploaded or re-processed. The edit is
 *   written to `edited_text`, beside the original.
 *
 * That is what lets a correction be undone (send `edited_text: null` and the
 * provider's words are still there), lets a re-run of transcription replace the
 * machine text without destroying anyone's work, and keeps the recording
 * something a person can listen to and check the correction against.
 *
 * A correction does have to reach search, though: a user who fixes a client's
 * name and then cannot find the note by it has been given a text box that does
 * nothing. So the corrected words are folded into `notes.derived_text`, the
 * weight-C input to the note's search vector — never into `extracted_text`,
 * which is what the user typed.
 */
final class TranscriptService
{
    /** Selected explicitly so `privacy_mode` is present for the permission check. */
    private const NOTE_COLUMNS = 'n.id, n.privacy_mode';

    private const COLUMNS = 'id, note_id, attachment_id, provider, model, language, status,
        text, segments, edited_text, edited_by, edited_at, created_at, updated_at';

    private const STATUSES = ['pending', 'processing', 'completed', 'failed'];

    /** Timed slices are for playback, not for storage of a whole meeting. */
    private const MAX_SEGMENTS = 5000;

    public function __construct(
        private readonly NotePermissionService $permissions = new NotePermissionService(),
        private readonly AttachmentService $attachments = new AttachmentService(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    /**
     * Every transcript on a note, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForNote(Identity $identity, string $noteId): array
    {
        $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::VIEW,
            columns: self::NOTE_COLUMNS,
        );

        $rows = Connection::select(
            'SELECT ' . self::COLUMNS . ' FROM note_transcripts
             WHERE note_id = :note_id ORDER BY created_at, id',
            ['note_id' => $noteId],
        );

        return array_map(static fn (array $row): array => self::present($row), $rows);
    }

    // -----------------------------------------------------------------------
    // Correction
    // -----------------------------------------------------------------------

    /**
     * Record a user's correction to a transcript.
     *
     * Needs EDIT on the note: a correction changes what the note says and what
     * it can be found by, which is editing it. `edited_text: null` clears the
     * correction and the provider's own words stand again.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function correct(Identity $identity, string $transcriptId, array $input): array
    {
        $row = Connection::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM note_transcripts WHERE id = :id',
            ['id' => $transcriptId],
        );
        if ($row === null) {
            throw ApiException::notFound('That transcript');
        }

        $noteId = (string) $row['note_id'];
        $note = $this->permissions->requireNote(
            $identity,
            $noteId,
            NotePermissionService::EDIT,
            columns: self::NOTE_COLUMNS,
        );

        if (!array_key_exists('edited_text', $input)) {
            throw ApiException::validation([
                'edited_text' => 'Send the corrected text as `edited_text`; `text` is the provider\'s own and is not editable.',
            ]);
        }
        $corrected = self::correction($input['edited_text']);

        Connection::transaction(function () use ($identity, $transcriptId, $noteId, $note, $row, $corrected): void {
            Connection::execute(
                'UPDATE note_transcripts
                    SET edited_text = :edited_text,
                        edited_by = :edited_by,
                        edited_at = :edited_at::timestamptz,
                        updated_at = now()
                  WHERE id = :id',
                [
                    'id' => $transcriptId,
                    'edited_text' => $corrected,
                    // Cleared together: a transcript with no correction must not
                    // carry the name of whoever removed the last one.
                    'edited_by' => $corrected === null ? null : $identity->userId,
                    'edited_at' => $corrected === null ? null : Clock::iso(),
                ],
            );

            $this->indexCorrection($noteId, $note, $row, $corrected);
        });

        return $this->find($transcriptId);
    }

    /**
     * Make the correction findable.
     *
     * Two writes, for two different owners of the same text:
     *
     *   1. The attachment's `extracted_text`, when the transcript came from a
     *      file. That column is what {@see AttachmentService::rebuildDerivedText}
     *      rolls up, so the correction survives the next time a background job
     *      rebuilds the note's search text rather than being reverted by it.
     *   2. `notes.derived_text` itself, right now, so the note is findable by
     *      the corrected words before any worker runs.
     *
     * A private note is skipped whole, for the same reason the OCR rollup skips
     * it: its document is ciphertext this server cannot read, and indexing text
     * derived from it here would make part of it searchable on the server that
     * promised it could not be.
     *
     * @param array<string, mixed> $note
     * @param array<string, mixed> $row
     */
    private function indexCorrection(string $noteId, array $note, array $row, ?string $corrected): void
    {
        if ((string) ($note['privacy_mode'] ?? 'standard') === 'private') {
            return;
        }

        $attachmentId = $row['attachment_id'] === null ? null : (string) $row['attachment_id'];
        if ($attachmentId !== null) {
            $this->attachments->recordExtractedText(
                $attachmentId,
                $corrected ?? (string) $row['text'],
            );
        }

        $this->refreshDerivedText($noteId);
    }

    /**
     * Rebuild `notes.derived_text` from everything that contributed to it.
     *
     * A superset of the attachment rollup: it also picks up transcripts that
     * belong to no file, which is how a recording ingested from Connect reaches
     * search at all. Running the attachment rollup afterwards would drop that
     * half again — see the note in the result of this work.
     */
    public function refreshDerivedText(string $noteId): int
    {
        $rows = Connection::select(
            "SELECT extracted_text AS text FROM note_attachments
              WHERE note_id = :note_id AND deleted_at IS NULL
                AND coalesce(extracted_text, '') <> ''
              ORDER BY created_at, id",
            ['note_id' => $noteId],
        );

        $rows = array_merge($rows, Connection::select(
            "SELECT coalesce(edited_text, text) AS text FROM note_transcripts
              WHERE note_id = :note_id AND attachment_id IS NULL
                AND coalesce(edited_text, text) <> ''
              ORDER BY created_at, id",
            ['note_id' => $noteId],
        ));

        $parts = array_filter(array_map(
            static fn (array $row): string => trim((string) $row['text']),
            $rows,
        ));

        // Hard cap: `to_tsvector` refuses a string over 1 MB, and the note's
        // search vector is GENERATED — an oversized value would make every
        // write to that note fail, not just this one.
        $derived = Str::limit(implode("\n\n", $parts), AttachmentService::MAX_DERIVED_CHARS);

        Connection::execute(
            'UPDATE notes SET derived_text = :derived WHERE id = :id AND derived_text <> :derived',
            ['id' => $noteId, 'derived' => $derived],
        );

        return mb_strlen($derived, 'UTF-8');
    }

    // -----------------------------------------------------------------------
    // The machine path
    // -----------------------------------------------------------------------

    /**
     * Store a transcript produced by a provider for a note.
     *
     * There is no {@see Identity} here because there is no user: this is what
     * an inbound recording from Connect turns into. It is deliberately not a
     * route of its own — the caller is
     * {@see \Aicountly\Api\Integrations\ConnectIntegrationService}, which
     * resolves the note from an id a person already linked to it, so the only
     * notes reachable this way are ones somebody with edit rights pointed at
     * that meeting.
     *
     * One row per (note, provider) for transcripts with no file behind them, so
     * a webhook delivered twice corrects the same row instead of stacking
     * copies of the same conversation.
     *
     * @param array<string, mixed> $transcript
     * @return array<string, mixed>
     */
    public function ingest(string $noteId, array $transcript): array
    {
        $provider = Str::limit(trim((string) ($transcript['provider'] ?? 'unknown')), 60);
        $provider = $provider === '' ? 'unknown' : $provider;

        $status = (string) ($transcript['status'] ?? 'completed');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'completed';
        }

        // Bound exactly, per statement: PDO refuses an execute() carrying a
        // parameter the statement does not name, so a shared binding array
        // reused across an INSERT and a narrower UPDATE fails at runtime.
        $content = [
            'model' => self::nullableText($transcript['model'] ?? null, 120),
            'language' => self::nullableText($transcript['language'] ?? null, 16),
            'status' => $status,
            'text' => Str::limit(
                trim((string) ($transcript['text'] ?? '')),
                AttachmentService::MAX_EXTRACTED_CHARS,
            ),
            'segments' => (string) json_encode(
                self::segments($transcript['segments'] ?? []),
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
        ];

        return Connection::transaction(function () use ($noteId, $provider, $content): array {
            $existing = Connection::selectOne(
                'SELECT id FROM note_transcripts
                  WHERE note_id = :note_id AND attachment_id IS NULL AND provider = :provider
                  ORDER BY created_at LIMIT 1',
                ['note_id' => $noteId, 'provider' => $provider],
            );

            if ($existing !== null) {
                // `edited_text` is untouched on purpose: a re-delivery replaces
                // what the machine heard, never what a person corrected.
                Connection::execute(
                    'UPDATE note_transcripts
                        SET model = :model, language = :language, status = :status,
                            text = :text, segments = :segments::jsonb, updated_at = now()
                      WHERE id = :id',
                    $content + ['id' => $existing['id']],
                );
                $id = (string) $existing['id'];
            } else {
                $id = Uuid::v4();
                Connection::execute(
                    'INSERT INTO note_transcripts
                        (id, note_id, attachment_id, provider, model, language, status, text, segments)
                     VALUES (:id, :note_id, NULL, :provider, :model, :language, :status, :text, :segments::jsonb)',
                    $content + ['id' => $id, 'note_id' => $noteId, 'provider' => $provider],
                );
            }

            $this->refreshDerivedText($noteId);

            return $this->find($id);
        });
    }

    // -----------------------------------------------------------------------
    // Validation and presentation
    // -----------------------------------------------------------------------

    private static function correction(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::validation(['edited_text' => 'A correction must be text.']);
        }
        $trimmed = trim($value);

        // An empty correction is the same act as clearing it — the user has
        // decided their edit was not an improvement.
        return $trimmed === '' ? null : Str::limit($trimmed, AttachmentService::MAX_EXTRACTED_CHARS);
    }

    /**
     * Provider segments, kept only where they carry a usable timestamp.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function segments(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $segments = [];
        foreach (array_slice(array_values($value), 0, self::MAX_SEGMENTS) as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = [
                'start' => isset($segment['start']) ? (float) $segment['start'] : null,
                'end' => isset($segment['end']) ? (float) $segment['end'] : null,
                'speaker' => self::nullableText($segment['speaker'] ?? null, 80),
                'text' => Str::limit($text, 4000),
                'confidence' => isset($segment['confidence']) ? (float) $segment['confidence'] : null,
            ];
        }

        return $segments;
    }

    private static function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, $max);
    }

    /** @return array<string, mixed> */
    private function find(string $transcriptId): array
    {
        $row = Connection::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM note_transcripts WHERE id = :id',
            ['id' => $transcriptId],
        );
        if ($row === null) {
            throw ApiException::notFound('That transcript');
        }

        return self::present($row);
    }

    /**
     * Row → API resource.
     *
     * Both texts are returned, plus the one a reader should be shown. A client
     * that only rendered `display_text` would still be able to offer "see the
     * original", which is the point of keeping them apart.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $text = (string) ($row['text'] ?? '');
        $edited = $row['edited_text'] === null ? null : (string) $row['edited_text'];

        return [
            'id' => (string) $row['id'],
            'note_id' => (string) $row['note_id'],
            'attachment_id' => $row['attachment_id'] === null ? null : (string) $row['attachment_id'],
            'provider' => (string) $row['provider'],
            'model' => $row['model'] === null ? null : (string) $row['model'],
            'language' => $row['language'] === null ? null : (string) $row['language'],
            'status' => (string) $row['status'],
            'text' => $text,
            'edited_text' => $edited,
            'display_text' => $edited ?? $text,
            'is_edited' => $edited !== null,
            'segments' => self::decodeList($row['segments'] ?? null),
            'edited_by' => $row['edited_by'] === null ? null : (string) $row['edited_by'],
            'edited_at' => self::iso($row['edited_at'] ?? null),
            'created_at' => self::iso($row['created_at'] ?? null),
            'updated_at' => self::iso($row['updated_at'] ?? null),
        ];
    }

    /** @return array<int, mixed> */
    private static function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function iso(mixed $value): ?string
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
