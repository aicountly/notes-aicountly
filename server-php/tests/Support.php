<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Notes\NoteDocument;
use Aicountly\Api\Support\Uuid;

/**
 * Fixtures and database reset.
 */
final class Support
{
    /** Every table the suite writes to, ordered so cascades do the work. */
    private const TABLES = [
        'note_activity', 'note_comments', 'note_members', 'notebook_members',
        'note_transcripts', 'note_processing_jobs', 'note_attachments',
        'note_embeddings', 'note_entity_links', 'note_links',
        'note_reminders', 'note_actions', 'note_tags', 'tags',
        'note_revisions', 'note_meetings', 'smart_folders', 'notes', 'notebooks',
        'note_templates', 'api_sessions', 'api_rate_limits', 'sync_operations',
    ];

    public static function reset(): void
    {
        Connection::execute('TRUNCATE ' . implode(', ', self::TABLES) . ' CASCADE');
    }

    public static function user(string $suffix = 'a', ?string $tenant = null): Identity
    {
        return new Identity(
            userId: 'user-' . $suffix,
            tenantId: $tenant,
            displayName: 'User ' . strtoupper($suffix),
            email: $suffix . '@example.test',
        );
    }

    /** A ProseMirror document containing one paragraph of the given text. */
    public static function doc(string $text): array
    {
        return NoteDocument::fromPlainText($text);
    }

    /** A document with a checklist of the given items. */
    public static function checklist(array $items): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'taskList',
                'attrs' => ['blockId' => Uuid::v4()],
                'content' => array_map(static fn (array $item) => [
                    'type' => 'taskItem',
                    'attrs' => ['blockId' => $item['id'] ?? Uuid::v4(), 'checked' => $item['checked'] ?? false],
                    'content' => [[
                        'type' => 'paragraph',
                        'attrs' => ['blockId' => Uuid::v4()],
                        'content' => [['type' => 'text', 'text' => $item['text']]],
                    ]],
                ], $items),
            ]],
        ];
    }
}
