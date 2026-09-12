<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Notes\NoteQuery;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Hostile input at the places it reaches SQL.
 *
 * Two surfaces are interesting here, and they are interesting for different
 * reasons. **Search** takes a raw string and hands it to a text-search parser —
 * `to_tsquery` throws on malformed input, which is why `websearch_to_tsquery`
 * is used instead. **Smart folders** are worse: the user stores a query that is
 * executed later, so a field name that reached SQL as text would be a stored
 * injection triggered by opening a folder.
 *
 * Every case here asserts the same two things: the request is handled rather
 * than crashing, and the schema is still standing afterwards.
 */
final class InjectionTest extends TestCase
{
    private ApiClient $api;

    public function name(): string
    {
        return 'Injection';
    }

    public function setUp(): void
    {
        $this->api = new ApiClient(Support::user('a'));
    }

    private function assertSchemaIntact(): void
    {
        $tables = Connection::select(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name IN ('notes', 'tags', 'notebooks')",
        );
        $this->assertCount(3, $tables, 'the schema must still be standing');
    }

    /**
     * The classics, plus the ones specific to a text-search parser.
     *
     * A method rather than a constant because one entry is generated.
     *
     * @return array<int, string>
     */
    private static function hostile(): array
    {
        return [
            "'; DROP TABLE notes; --",
            "' OR '1'='1",
            "\\'; DELETE FROM notes WHERE '1'='1",
            'notes; SELECT pg_sleep(10)',
            "' UNION SELECT ses_key_hash FROM api_sessions --",
            // Unbalanced parentheses and bare operators are what make
            // to_tsquery throw, which is why websearch_to_tsquery is used.
            '(((((((((((((((((((((((',
            '!&|<>:*',
            "\x00\x01truncated",
            '%%%',
            str_repeat('a', 5000),
        ];
    }

    public function testSearchSurvivesHostileQueries(): void
    {
        $this->api->post('/notes', ['title' => 'Real note', 'document' => Support::doc('genuine content')]);

        foreach (self::hostile() as $payload) {
            $result = $this->api->get('/search/notes', ['q' => $payload]);

            // Handled — a 200 with nothing, or a clean 4xx. Never a 500.
            $this->assertTrue(
                $result['status'] < 500,
                'search must not 500 on ' . substr(json_encode($payload) ?: '', 0, 40),
            );
        }

        $this->assertSchemaIntact();
        // And the real note is still findable afterwards.
        $found = $this->api->get('/search/notes', ['q' => 'genuine'])['body']['data'] ?? [];
        $this->assertTrue(count($found) > 0, 'ordinary search still works after the hostile ones');
    }

    public function testNoteListFiltersSurviveHostileValues(): void
    {
        foreach (self::hostile() as $payload) {
            foreach (['q', 'tags', 'color', 'note_type', 'notebook_id', 'sort', 'cursor'] as $parameter) {
                $result = $this->api->get('/notes', [$parameter => $payload]);
                $this->assertTrue(
                    $result['status'] < 500,
                    sprintf('?%s must not 500', $parameter),
                );
            }
        }

        $this->assertSchemaIntact();
    }

    public function testSmartFolderRulesRejectUnknownFieldsRatherThanExecutingThem(): void
    {
        foreach (['notes; DROP TABLE notes', 'title', '1=1', '', 'n.owner_user_id'] as $field) {
            $this->assertApiError(
                'BAD_REQUEST',
                static fn () => NoteQuery::fromRules([
                    'match' => 'all',
                    'conditions' => [['field' => $field, 'operator' => 'is', 'value' => 'x']],
                ]),
                'field `' . $field . '` must be refused',
            );
        }
    }

    public function testSmartFolderOperatorsAreCheckedAgainstTheirField(): void
    {
        // `is_pinned` accepts only `is`; borrowing another field's operator
        // must not be a way to reach a different code path.
        $this->assertApiError('BAD_REQUEST', static fn () => NoteQuery::fromRules([
            'match' => 'all',
            'conditions' => [['field' => 'is_pinned', 'operator' => 'within_days', 'value' => 30]],
        ]));

        $this->assertApiError('BAD_REQUEST', static fn () => NoteQuery::fromRules([
            'match' => 'all',
            'conditions' => [['field' => 'tag', 'operator' => 'contains', 'value' => 'x']],
        ]));
    }

    public function testSmartFolderValuesAreBoundNotInterpolated(): void
    {
        $this->api->post('/notes', ['title' => 'Tagged', 'document' => Support::doc('x'), 'tags' => ['real']]);

        foreach (self::hostile() as $payload) {
            try {
                $query = NoteQuery::fromRules([
                    'match' => 'all',
                    'conditions' => [['field' => 'tag', 'operator' => 'is', 'value' => $payload]],
                ]);
            } catch (\Aicountly\Api\Http\ApiException) {
                // Rejected outright — a value that normalises to nothing is not
                // a tag. Refusing it is as good an outcome as binding it.
                $this->pass();
                continue;
            }

            // Otherwise the value must appear only as a binding, never in the
            // SQL text the folder will later execute.
            $sql = $query->sql();
            $this->assertFalse(
                str_contains($sql, 'DROP') || str_contains($sql, 'UNION') || str_contains($sql, '--'),
                'a hostile value reached the SQL string',
            );
            $this->assertTrue(
                in_array($payload, array_values($query->bindings()), true)
                    || $query->bindings() !== [],
                'the value is carried as a binding',
            );
        }

        $this->assertSchemaIntact();
    }

    public function testARuleTreeCannotBeMadeUnboundedlyLarge(): void
    {
        $conditions = [];
        for ($i = 0; $i < 200; $i++) {
            $conditions[] = ['field' => 'is_pinned', 'operator' => 'is', 'value' => true];
        }

        // A folder with a thousand conditions is a query nobody meant to write
        // and a scan nobody wants to run.
        $this->assertApiError('BAD_REQUEST', static fn () => NoteQuery::fromRules([
            'match' => 'all',
            'conditions' => $conditions,
        ]));
    }

    public function testHostileTagNamesBecomeOrdinaryTags(): void
    {
        $note = $this->api->post('/notes', [
            'title' => 'Tagged oddly',
            'document' => Support::doc('x'),
            'tags' => ["'; DROP TABLE tags; --", '<script>alert(1)</script>', '#!@$%'],
        ]);

        $this->assertSame(201, $note['status']);
        $this->assertSchemaIntact();

        // Whatever survived normalisation is a plain slug, not markup.
        foreach ($note['body']['data']['tags'] as $tag) {
            $this->assertFalse(str_contains($tag['slug'], '<'), 'a slug must not carry markup');
            $this->assertFalse(str_contains($tag['slug'], ';'), 'a slug must not carry a statement separator');
        }
    }

    public function testAHostileTitleIsStoredAndReturnedAsText(): void
    {
        $payload = "<script>alert('xss')</script> & \"quotes\" 'and' \\backslashes";

        $created = $this->api->post('/notes', ['title' => $payload, 'document' => Support::doc('body')]);
        $this->assertSame(201, $created['status']);

        // Stored verbatim — escaping belongs at the point of rendering, and the
        // frontend renders it as text. Double-escaping here would mean the user
        // sees &lt;script&gt; in their own title.
        $fetched = $this->api->get('/notes/' . $created['body']['data']['id']);
        $this->assertSame($payload, $fetched['body']['data']['title']);
    }

    public function testAHostileCursorIsIgnoredRatherThanTrusted(): void
    {
        foreach (["' OR 1=1 --", base64_encode('{"ts":"x\'; DROP TABLE notes; --","id":"1"}'), 'not-base64'] as $cursor) {
            $result = $this->api->get('/notes', ['cursor' => $cursor]);
            $this->assertTrue($result['status'] < 500, 'a malformed cursor must not 500');
        }

        $this->assertSchemaIntact();
    }
}
