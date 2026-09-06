<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Database\Migrator;
use Aicountly\Api\Tests\TestCase;

/**
 * The schema's own invariants.
 *
 * A migration that cannot be rolled back is discovered at the worst possible
 * moment — mid-incident, on production — so reversibility is checked here
 * rather than trusted. These are static checks against the files plus a live
 * check against the applied schema; the full up/down/up cycle is exercised by
 * `php bin/migrate.php` against a scratch database.
 */
final class MigrationTest extends TestCase
{
    private Migrator $migrator;

    public function name(): string
    {
        return 'Migrations';
    }

    public function setUp(): void
    {
        $this->migrator = new Migrator(__DIR__ . '/../../migrations');
    }

    public function testEveryMigrationDeclaresBothDirections(): void
    {
        foreach ($this->migrator->available() as $migration) {
            $sql = (string) file_get_contents($migration['path']);

            $this->assertTrue(
                str_contains($sql, '-- @UP'),
                $migration['version'] . ' has no @UP section',
            );
            $this->assertTrue(
                str_contains($sql, '-- @DOWN'),
                $migration['version'] . ' has no @DOWN section — it cannot be rolled back',
            );
        }
    }

    public function testEveryTableCreatedIsAlsoDropped(): void
    {
        foreach ($this->migrator->available() as $migration) {
            $sql = (string) file_get_contents($migration['path']);
            $downAt = strpos($sql, '-- @DOWN');
            $up = substr($sql, 0, $downAt === false ? null : $downAt);
            $down = $downAt === false ? '' : substr($sql, $downAt);

            preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?(\w+)/i', $up, $matches);

            foreach ($matches[1] ?? [] as $table) {
                $this->assertTrue(
                    preg_match('/DROP TABLE (?:IF EXISTS )?' . preg_quote($table, '/') . '\b/i', $down) === 1,
                    sprintf('%s creates `%s` but never drops it', $migration['version'], $table),
                );
            }
        }
    }

    public function testTheOptionalMigrationIsMarkedAsSuch(): void
    {
        $pgvector = null;
        foreach ($this->migrator->available() as $migration) {
            if (str_contains($migration['version'], 'pgvector')) {
                $pgvector = (string) file_get_contents($migration['path']);
            }
        }

        $this->assertNotNull($pgvector, 'the pgvector migration exists');
        // Without @OPTIONAL a host that cannot install the extension would fail
        // its whole deploy over a feature that is switched off anyway.
        $this->assertContainsString('@OPTIONAL', (string) $pgvector);
    }

    public function testTheSearchVectorIsGeneratedRatherThanMaintainedByHand(): void
    {
        $column = Connection::selectOne(
            "SELECT is_generated, generation_expression
             FROM information_schema.columns
             WHERE table_name = 'notes' AND column_name = 'search_vector'",
        );

        $this->assertNotNull($column);
        // Generated, so it can never drift from the columns it summarises.
        $this->assertSame('ALWAYS', $column['is_generated'] ?? null);

        $expression = (string) ($column['generation_expression'] ?? '');
        foreach (['title', 'extracted_text', 'derived_text'] as $source) {
            $this->assertContainsString($source, $expression);
        }
    }

    public function testTheHotQueryPathsAreIndexed(): void
    {
        $required = [
            'notes' => ['notes_owner_updated_idx', 'notes_search_idx', 'notes_notebook_idx'],
            'note_links' => ['note_links_target_idx'],
            'note_processing_jobs' => ['note_processing_jobs_claim_idx'],
            'note_reminders' => ['note_reminders_due_idx'],
        ];

        foreach ($required as $table => $indexes) {
            $present = array_column(
                Connection::select(
                    'SELECT indexname FROM pg_indexes WHERE tablename = :t',
                    ['t' => $table],
                ),
                'indexname',
            );

            foreach ($indexes as $index) {
                $this->assertTrue(
                    in_array($index, $present, true),
                    sprintf('%s is missing — %s would be scanned', $index, $table),
                );
            }
        }
    }

    public function testNotesCascadeToEverythingThatReferencesThem(): void
    {
        // Permanently deleting a note must not leave its revisions, comments,
        // attachments or links behind as orphans.
        $children = [
            'note_revisions', 'note_comments', 'note_members', 'note_attachments',
            'note_actions', 'note_reminders', 'note_embeddings', 'note_entity_links',
            'note_transcripts', 'note_meetings',
        ];

        foreach ($children as $table) {
            $constraint = Connection::selectOne(
                "SELECT rc.delete_rule
                 FROM information_schema.table_constraints tc
                 JOIN information_schema.referential_constraints rc
                      ON rc.constraint_name = tc.constraint_name
                 JOIN information_schema.constraint_column_usage ccu
                      ON ccu.constraint_name = tc.constraint_name
                 WHERE tc.table_name = :t AND tc.constraint_type = 'FOREIGN KEY'
                   AND ccu.table_name = 'notes'
                 LIMIT 1",
                ['t' => $table],
            );

            $this->assertSame('CASCADE', $constraint['delete_rule'] ?? null, $table . ' must cascade from notes');
        }
    }

    public function testDeletingANotebookDoesNotDeleteItsNotes(): void
    {
        $constraint = Connection::selectOne(
            "SELECT rc.delete_rule
             FROM information_schema.table_constraints tc
             JOIN information_schema.referential_constraints rc
                  ON rc.constraint_name = tc.constraint_name
             JOIN information_schema.constraint_column_usage ccu
                  ON ccu.constraint_name = tc.constraint_name
             WHERE tc.table_name = 'notes' AND tc.constraint_type = 'FOREIGN KEY'
               AND ccu.table_name = 'notebooks'
             LIMIT 1",
        );

        // A note outlives the folder it was filed in. CASCADE here would mean
        // deleting a notebook silently destroys everything inside it.
        $this->assertSame('SET NULL', $constraint['delete_rule'] ?? null);
    }
}
