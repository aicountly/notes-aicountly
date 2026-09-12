<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Notes;

use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Support\Str;

/**
 * Database row → API resource.
 *
 * One place decides the wire shape, so the TypeScript `Note` type has exactly
 * one thing to mirror. Two rules it enforces:
 *
 *   - A **list** response never carries `document_json`. A list of 200 notes
 *     would otherwise ship megabytes of ProseMirror trees to render cards that
 *     show a two-line excerpt.
 *   - A note with no title gets a `display_title` derived from its first line,
 *     computed here rather than in every component that renders a note.
 */
final class NotePresenter
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function summary(array $row, array $extra = []): array
    {
        $title = self::nullableString($row['title'] ?? null);
        $excerptSource = (string) ($row['extracted_text'] ?? '');

        return array_merge([
            'id' => (string) $row['id'],
            'note_type' => (string) ($row['note_type'] ?? 'document'),
            'title' => $title,
            'display_title' => self::displayTitle($title, $excerptSource),
            'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $excerptSource) ?? '', 280),
            'notebook_id' => self::nullableString($row['notebook_id'] ?? null),
            'color' => self::nullableString($row['color'] ?? null),
            'is_pinned' => (bool) ($row['is_pinned'] ?? false),
            'is_favourite' => (bool) ($row['is_favourite'] ?? false),
            'is_archived' => (bool) ($row['is_archived'] ?? false),
            'is_locked' => (bool) ($row['is_locked'] ?? false),
            'privacy_mode' => (string) ($row['privacy_mode'] ?? 'standard'),
            'version' => (int) ($row['version'] ?? 1),
            'word_count' => (int) ($row['word_count'] ?? 0),
            'char_count' => (int) ($row['char_count'] ?? 0),
            'owner_user_id' => (string) ($row['owner_user_id'] ?? ''),
            'created_at' => self::timestamp($row['created_at'] ?? null),
            'updated_at' => self::timestamp($row['updated_at'] ?? null),
            'deleted_at' => self::timestamp($row['deleted_at'] ?? null),
            'role' => (string) ($row['_role'] ?? 'owner'),
            'is_shared' => (bool) ($extra['is_shared'] ?? false),
            'attachment_count' => (int) ($extra['attachment_count'] ?? 0),
            'has_reminder' => (bool) ($extra['has_reminder'] ?? false),
            'checklist' => $extra['checklist'] ?? null,
            'tags' => $extra['tags'] ?? [],
        ], $extra['append'] ?? []);
    }

    /**
     * The full note, document included. Used by GET /notes/{id} only.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function detail(array $row, array $extra = []): array
    {
        $role = (string) ($row['_role'] ?? 'owner');
        $document = $row['document_json'] ?? null;
        if (is_string($document)) {
            $decoded = json_decode($document, true);
            $document = is_array($decoded) ? $decoded : NoteDocument::empty();
        }

        return array_merge(self::summary($row, $extra), [
            'document' => $document ?? NoteDocument::empty(),
            'document_schema_version' => (int) ($row['document_schema_version'] ?? NoteDocument::SCHEMA_VERSION),
            'content_hash' => (string) ($row['content_hash'] ?? ''),
            'source' => self::nullableString($row['source'] ?? null),
            'language' => self::nullableString($row['language'] ?? null),
            'template_key' => self::nullableString($row['template_key'] ?? null),
            'capabilities' => NotePermissionService::capabilities($role),
        ]);
    }

    /**
     * Google Keep's rule: an untitled note shows its first line instead of
     * "Untitled", so a note captured in two seconds still reads as something.
     */
    public static function displayTitle(?string $title, string $bodyText): string
    {
        if ($title !== null && trim($title) !== '') {
            return $title;
        }
        $firstLine = Str::firstLine($bodyText);

        return $firstLine !== '' ? $firstLine : 'Untitled note';
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : $string;
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
