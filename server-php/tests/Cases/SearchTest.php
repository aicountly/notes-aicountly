<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Search\NotesSearchService;
use Aicountly\Api\Domain\Search\SearchSnippet;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\ApiClient;
use Aicountly\Api\Tests\Support;
use Aicountly\Api\Tests\TestCase;

/**
 * Search, over the real router.
 *
 * Two kinds of test live here and the second kind matters more. The first is
 * "does it find the note" — ranking, snippets, filters, paging. The second is
 * "what can it never find": a note in the trash, a note whose owner archived
 * it, a note encrypted on the client, and above all somebody else's note.
 * Search is the endpoint that reads the widest, so it is the one where a
 * missing access check leaks the most.
 */
final class SearchTest extends TestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    public function name(): string
    {
        return 'Search';
    }

    public function setUp(): void
    {
        $this->alice = new ApiClient(Support::user('a'));
        $this->bob = new ApiClient(Support::user('b'));
    }

    // -- Fixtures -----------------------------------------------------------

    /** @param array<string, mixed> $extra */
    private function note(ApiClient $api, string $title, string $body, array $extra = []): array
    {
        return $api->post('/notes', array_merge([
            'title' => $title,
            'document' => Support::doc($body),
        ], $extra))['body']['data'];
    }

    /** A client-encrypted note. Creating one needs the flag the mode promises. */
    private function privateNote(ApiClient $api, string $title, string $body): array
    {
        putenv('NOTES_PRIVATE_NOTES_ENABLED=true');
        try {
            return $this->note($api, $title, $body, ['privacy_mode' => 'private']);
        } finally {
            putenv('NOTES_PRIVATE_NOTES_ENABLED');
        }
    }

    /** Notebooks have no controller yet, so the row is written directly. */
    private function notebook(string $name, string $owner = 'user-a'): string
    {
        $id = Uuid::v4();
        Connection::execute(
            'INSERT INTO notebooks (id, owner_user_id, name, created_by, updated_by)
             VALUES (:id, :owner, :name, :owner, :owner)',
            ['id' => $id, 'owner' => $owner, 'name' => $name],
        );

        return $id;
    }

    private function share(string $noteId, string $userId, string $role): void
    {
        Connection::execute(
            "INSERT INTO note_members (id, note_id, user_id, role, invited_by)
             VALUES (:id, :note, :user, :role, 'user-a')",
            ['id' => Uuid::v4(), 'note' => $noteId, 'user' => $userId, 'role' => $role],
        );
    }

    /** @param array<string, string|int> $query */
    private function search(ApiClient $api, string $q, array $query = []): array
    {
        return $api->get('/search/notes', array_merge(['q' => $q], $query));
    }

    /** @return array<int, string> */
    private function ids(array $result): array
    {
        return array_map(static fn (array $note): string => $note['id'], $result['body']['data']);
    }

    private function snippetFor(array $result, string $noteId): string
    {
        foreach ($result['body']['data'] as $note) {
            if ($note['id'] === $noteId) {
                return (string) $note['snippet'];
            }
        }

        return '';
    }

    // -- Finding things -----------------------------------------------------

    public function testFindsNotesByTitleAndBodyWithTitleRankedFirst(): void
    {
        $titled = $this->note($this->alice, 'GST return checklist', 'Everything to gather before filing.');
        $mentioned = $this->note($this->alice, 'Client meeting', 'We went through the gst return line by line.');
        $unrelated = $this->note($this->alice, 'Groceries', 'Milk, bread, coffee.');

        $result = $this->search($this->alice, 'gst return');

        $this->assertSame(200, $result['status']);
        $this->assertCount(2, $result['body']['data']);
        // The tsvector weights title A and body B, so the note *about* the GST
        // return outranks the note that mentions it.
        $this->assertSame($titled['id'], $this->ids($result)[0]);
        $this->assertSame($mentioned['id'], $this->ids($result)[1]);
        $this->assertFalse(in_array($unrelated['id'], $this->ids($result), true));
    }

    public function testAResultCarriesTheSameShapeAsAListRow(): void
    {
        $note = $this->note($this->alice, 'GST return', 'Filed on Friday.', ['tags' => ['gst', 'filing']]);

        $hit = $this->search($this->alice, 'gst')['body']['data'][0];

        $this->assertSame($note['id'], $hit['id']);
        $this->assertSame('GST return', $hit['display_title']);
        $this->assertCount(2, $hit['tags'], 'a search hit is hydrated like a note card');
        $this->assertTrue($hit['score'] > 0.0);
        $this->assertFalse(array_key_exists('document', $hit), 'a result page never ships documents');
    }

    public function testStopwordOnlyQueriesFindNothingRatherThanEverything(): void
    {
        $this->note($this->alice, 'GST return', 'Filed on Friday.');

        $this->assertCount(0, $this->search($this->alice, 'the and of')['body']['data']);
    }

    public function testTheQuerySpeaksTheSearchBoxDialect(): void
    {
        $blocked = $this->note($this->alice, 'Filing notes', 'We agreed the input credit is blocked.');
        $filed = $this->note($this->alice, 'Other notes', 'The credit note input was filed.');

        // `websearch_to_tsquery` is what makes a search box behave the way
        // people expect from one: a quoted phrase matches word order…
        $this->assertSame([$blocked['id']], $this->ids($this->search($this->alice, '"input credit"')));
        // …and a minus excludes.
        $this->assertSame([$filed['id']], $this->ids($this->search($this->alice, 'input -blocked')));
        // Malformed input is a normal empty answer, never a 500.
        $this->assertSame(200, $this->search($this->alice, '"unclosed AND ((')['status']);
    }

    public function testOcrAndTranscriptTextIsSearchableAndRetrievable(): void
    {
        $note = $this->note($this->alice, 'Scanned invoice', 'Attached below.');
        // What the processing pipeline writes back after OCR or transcription.
        Connection::execute(
            'UPDATE notes SET derived_text = :text WHERE id = :id',
            ['text' => 'Invoice 4471 issued to Meera Traders for consultancy services.', 'id' => $note['id']],
        );

        // The generated search_vector carries that text at weight C, so it is
        // findable without being ranked above what the user actually wrote.
        $this->assertSame([$note['id']], $this->ids($this->search($this->alice, 'meera traders')));

        $chunks = (new NotesSearchService())->retrieveForAi(Support::user('a'), 'meera traders', 5);
        $this->assertTrue(
            in_array('derived', array_column($chunks, 'source_type'), true),
            'text read out of an attachment is context an answer may use',
        );
    }

    public function testEmptyQueryIsAnEmptyResultNotAnError(): void
    {
        $this->note($this->alice, 'GST return', 'Filed on Friday.');

        $result = $this->search($this->alice, '   ');
        $this->assertSame(200, $result['status'], 'a search box fires on mount; nothing typed is not an error');
        $this->assertCount(0, $result['body']['data']);
        $this->assertFalse($result['body']['meta']['has_more']);
    }

    public function testResultsPaginate(): void
    {
        foreach (['first', 'second', 'third'] as $which) {
            $this->note($this->alice, 'GST ' . $which, 'A gst return for the quarter.');
        }

        $page = $this->search($this->alice, 'gst', ['limit' => 2]);
        $this->assertCount(2, $page['body']['data']);
        $this->assertTrue($page['body']['meta']['has_more']);

        $next = $this->search($this->alice, 'gst', ['limit' => 2, 'offset' => 2]);
        $this->assertCount(1, $next['body']['data']);
        $this->assertFalse($next['body']['meta']['has_more']);
    }

    // -- Snippets -----------------------------------------------------------

    public function testSnippetsAreHighlightedWithParsableMarkersNotHtml(): void
    {
        $note = $this->note(
            $this->alice,
            'Quarterly filing',
            'The GST return for the quarter is due on Friday, and the input credit ledger needs reconciling first.',
        );

        $result = $this->search($this->alice, 'input credit');
        $snippet = $this->snippetFor($result, $note['id']);

        $this->assertContainsString(SearchSnippet::HIGHLIGHT_OPEN . 'input', $snippet);
        $this->assertContainsString(SearchSnippet::HIGHLIGHT_CLOSE, $snippet);
        // The client renders each piece as a text node, so the server must
        // never hand it markup to insert.
        $this->assertFalse(str_contains($snippet, '<'), 'a snippet is never HTML');
        $this->assertSame(
            ['open' => SearchSnippet::HIGHLIGHT_OPEN, 'close' => SearchSnippet::HIGHLIGHT_CLOSE],
            $result['body']['meta']['highlight'],
            'the markers travel with the response so the client need not hard-code them',
        );
    }

    public function testANoteCannotForgeAHighlightOfItsOwn(): void
    {
        $note = $this->note(
            $this->alice,
            'Quarterly filing',
            'Reminder: [[hl]]refund approved[[/hl]] — the gst return is still outstanding.',
        );

        $snippet = $this->snippetFor($this->search($this->alice, 'gst'), $note['id']);

        // The markers the author typed are stripped before the highlighter
        // runs, so only a real match can appear highlighted.
        $this->assertFalse(
            str_contains($snippet, SearchSnippet::HIGHLIGHT_OPEN . 'refund'),
            'text typed into a note must not come back looking like a match',
        );
        $this->assertContainsString('refund approved', $snippet);
    }

    // -- What search must never return --------------------------------------

    public function testTrashedNotesAreNotSearchable(): void
    {
        $kept = $this->note($this->alice, 'GST return kept', 'gst filing notes');
        $binned = $this->note($this->alice, 'GST return binned', 'gst filing notes');
        $this->alice->delete('/notes/' . $binned['id']);

        $this->assertSame([$kept['id']], $this->ids($this->search($this->alice, 'gst')));
    }

    public function testArchivedNotesAreExcludedUnlessAskedFor(): void
    {
        $active = $this->note($this->alice, 'GST return active', 'gst filing notes');
        $filed = $this->note($this->alice, 'GST return filed away', 'gst filing notes');
        $this->alice->post('/notes/' . $filed['id'] . '/archive');

        $this->assertSame([$active['id']], $this->ids($this->search($this->alice, 'gst')));

        $withArchived = $this->ids($this->search($this->alice, 'gst', ['include_archived' => 'true']));
        $this->assertCount(2, $withArchived);
        $this->assertTrue(in_array($filed['id'], $withArchived, true));
    }

    public function testPrivateNotesAreNeverSearchableEvenByTheirOwner(): void
    {
        $standard = $this->note($this->alice, 'GST return', 'gst filing notes');
        $private = $this->privateNote($this->alice, 'GST return private', 'gst filing notes');

        // The server holds ciphertext for a private note; ranking or excerpting
        // it would be meaningless, and indexing it contradicts the promise.
        $this->assertSame([$standard['id']], $this->ids($this->search($this->alice, 'gst')));
        $this->assertCount(0, $this->alice->get('/search/suggest', ['q' => 'gst return p'])['body']['data']['notes']);

        // …and it is still there, still readable by its owner.
        $this->assertSame(200, $this->alice->get('/notes/' . $private['id'])['status']);
    }

    // -- Filters ------------------------------------------------------------

    public function testFiltersComposeWithTheQuery(): void
    {
        $clients = $this->notebook('Clients');
        $inNotebook = $this->note($this->alice, 'GST return for Meera', 'gst filing', [
            'notebook_id' => $clients,
            'tags' => ['gst'],
        ]);
        $tagged = $this->note($this->alice, 'GST return draft', 'gst filing', ['tags' => ['gst']]);
        $checklist = $this->note($this->alice, 'GST return steps', 'gst filing', ['note_type' => 'checklist']);

        $this->assertSame([$inNotebook['id']], $this->ids($this->search($this->alice, 'gst', ['notebook_id' => $clients])));
        $this->assertSame([$checklist['id']], $this->ids($this->search($this->alice, 'gst', ['note_type' => 'checklist'])));

        $byTag = $this->ids($this->search($this->alice, 'gst', ['tags' => 'gst']));
        $this->assertCount(2, $byTag);
        $this->assertTrue(in_array($tagged['id'], $byTag, true));

        // An unknown filter field is a 400, not a query built from user text.
        $this->assertSame(400, $this->search($this->alice, 'gst', ['note_type' => 'nonsense'])['status']);
    }

    // -- Suggestions --------------------------------------------------------

    public function testSuggestOffersTitlesTagsAndNotebooks(): void
    {
        $note = $this->note($this->alice, 'GST return checklist', 'body text nobody suggests', ['tags' => ['gst']]);
        $notebook = $this->notebook('GST 2026');

        $result = $this->alice->get('/search/suggest', ['q' => 'gs']);
        $this->assertSame(200, $result['status']);

        $data = $result['body']['data'];
        $this->assertSame([$note['id']], array_column($data['notes'], 'id'));
        $this->assertSame(['gst'], array_column($data['tags'], 'slug'));
        $this->assertSame([$notebook], array_column($data['notebooks'], 'id'));
    }

    public function testSuggestMatchesAWordInsideATitleButNeverABody(): void
    {
        $this->note($this->alice, 'Draft GST return', 'nothing');
        $this->note($this->alice, 'Groceries', 'the gst return is mentioned only in this body');

        $data = $this->alice->get('/search/suggest', ['q' => 'gst'])['body']['data'];

        $this->assertCount(1, $data['notes'], 'suggestions come from titles; scanning bodies on every keystroke does not');
        $this->assertSame('Draft GST return', $data['notes'][0]['title']);
    }

    public function testAWildcardTypedIntoSuggestMatchesNothing(): void
    {
        $this->note($this->alice, 'GST return', 'nothing');

        // `%` is a LIKE wildcard; unescaped it would turn a prefix lookup into
        // "everything you have".
        $this->assertCount(0, $this->alice->get('/search/suggest', ['q' => '%'])['body']['data']['notes']);
    }

    // -- Somebody else's notes ----------------------------------------------

    public function testAStrangerFindsNothingOfMine(): void
    {
        $note = $this->note($this->alice, 'GST return for Meera', 'The refund is 41,000 rupees.');
        $this->note($this->bob, 'Bob shopping list', 'Nothing to do with tax.');

        // Every surface, one identity: none of them may reach Alice's note.
        $this->assertCount(0, $this->search($this->bob, 'gst')['body']['data']);
        $this->assertCount(0, $this->search($this->bob, 'meera')['body']['data']);
        $this->assertCount(0, $this->search($this->bob, 'refund rupees')['body']['data']);
        $this->assertCount(0, $this->bob->get('/search/suggest', ['q' => 'gst'])['body']['data']['notes']);
        $this->assertCount(0, (new NotesSearchService())->retrieveForAi(Support::user('b'), 'gst refund', 5));

        // Naming the note explicitly is a 404, the same answer an id that does
        // not exist gets.
        $this->assertApiError(
            'NOT_FOUND',
            fn () => (new NotesSearchService())->retrieveForAi(
                Support::user('b'),
                'gst refund',
                5,
                ['note_id' => $note['id']],
            ),
        );

        // Alice still finds her own.
        $this->assertCount(1, $this->search($this->alice, 'gst')['body']['data']);
    }

    public function testASharedNoteIsSearchableByTheMemberAndNobodyElse(): void
    {
        $note = $this->note($this->alice, 'GST return for Meera', 'The refund is due next week.');
        $this->share($note['id'], 'user-b', 'viewer');

        $found = $this->search($this->bob, 'gst refund');
        $this->assertSame([$note['id']], $this->ids($found));
        $this->assertSame('viewer', $found['body']['data'][0]['role']);

        // A third party with no grant still finds nothing.
        $this->assertCount(0, $this->search(new ApiClient(Support::user('c')), 'gst refund')['body']['data']);
    }

    public function testATenantBoundaryIsNotCrossedByAStaleGrant(): void
    {
        $note = $this->note($this->alice, 'GST return for Meera', 'The refund is due next week.');
        $this->share($note['id'], 'user-b', 'editor');

        // Same user, same grant, different company context: the note is
        // personal (tenant_id IS NULL) so it stays reachable, while a note
        // owned by another company never is.
        Connection::execute("UPDATE notes SET tenant_id = 'acme' WHERE id = :id", ['id' => $note['id']]);

        $inAcme = new ApiClient(Support::user('b', 'acme'));
        $inOther = new ApiClient(Support::user('b', 'globex'));

        $this->assertSame([$note['id']], $this->ids($this->search($inAcme, 'gst refund')));
        $this->assertCount(0, $this->search($inOther, 'gst refund')['body']['data']);
    }

    // -- Retrieval for AI ---------------------------------------------------

    public function testRetrievalChunksLongNotesAtSentenceBoundaries(): void
    {
        $sentences = [];
        for ($i = 0; $i < 40; $i++) {
            $sentences[] = $i % 7 === 0
                ? 'The gst return for quarter ' . $i . ' was filed with the input credit ledger attached.'
                : 'Paragraph ' . $i . ' records the reconciliation of ledgers, invoices and payment advices in detail.';
        }
        $note = $this->note($this->alice, 'Quarterly log', implode(' ', $sentences));

        $chunks = (new NotesSearchService())->retrieveForAi(Support::user('a'), 'gst input credit', 6);

        $this->assertTrue($chunks !== [], 'retrieval found nothing to ground an answer in');
        $this->assertTrue(count($chunks) > 1, 'a long note is more than one chunk');
        $this->assertSame($note['id'], $chunks[0]['note_id']);
        $this->assertSame('note', $chunks[0]['source_type']);
        $this->assertContainsString('gst', mb_strtolower($chunks[0]['snippet']));

        $previous = 1.0;
        foreach ($chunks as $chunk) {
            $this->assertTrue(
                mb_strlen($chunk['snippet']) <= SearchSnippet::CHUNK_CHARS,
                'a chunk must fit the retrieval budget',
            );
            $this->assertTrue($chunk['score'] <= $previous, 'chunks come back highest relevance first');
            $previous = $chunk['score'];
        }
    }

    public function testRetrievalExcludesTrashedAndPrivateNotes(): void
    {
        $kept = $this->note($this->alice, 'GST return', 'The gst refund is due next week.');
        $binned = $this->note($this->alice, 'GST return binned', 'The gst refund is due next week.');
        $this->alice->delete('/notes/' . $binned['id']);
        $this->privateNote($this->alice, 'GST return private', 'The gst refund is due next week.');

        $noteIds = array_unique(array_column(
            (new NotesSearchService())->retrieveForAi(Support::user('a'), 'gst refund', 10),
            'note_id',
        ));

        $this->assertSame([$kept['id']], array_values($noteIds));
    }

    public function testRetrievalScopedToOneNoteReturnsItEvenWhenTheWordsDiffer(): void
    {
        $note = $this->note($this->alice, 'Board meeting', 'We settled on the smaller office and signed nothing yet.');

        // "What did we decide?" is asked of notes that never say "decide".
        // Scoped to one note, that note is the corpus, not a candidate.
        $chunks = (new NotesSearchService())->retrieveForAi(Support::user('a'), 'what did we decide', 5, [
            'note_id' => $note['id'],
        ]);

        $this->assertCount(1, $chunks);
        $this->assertSame($note['id'], $chunks[0]['note_id']);
        $this->assertContainsString('smaller office', $chunks[0]['snippet']);
    }

    public function testRetrievalCanBeScopedToOneNotebook(): void
    {
        $clients = $this->notebook('Clients');
        $inNotebook = $this->note($this->alice, 'GST return for Meera', 'The gst refund is due.', [
            'notebook_id' => $clients,
        ]);
        $this->note($this->alice, 'GST return elsewhere', 'The gst refund is due.');

        $chunks = (new NotesSearchService())->retrieveForAi(Support::user('a'), 'gst refund', 10, [
            'notebook_id' => $clients,
        ]);

        $this->assertSame([$inNotebook['id']], array_values(array_unique(array_column($chunks, 'note_id'))));

        // A notebook the caller cannot open is a 404 before anything is read.
        $this->assertApiError('NOT_FOUND', fn () => (new NotesSearchService())->retrieveForAi(
            Support::user('b'),
            'gst refund',
            10,
            ['notebook_id' => $clients],
        ));
    }

    // -- Semantic search ----------------------------------------------------

    public function testSemanticSearchIsDisabledUntilItIsConfigured(): void
    {
        $this->note($this->alice, 'GST return', 'The gst refund is due next week.');

        $result = $this->alice->post('/search/semantic', ['query' => 'when is my refund due']);

        // No embedding provider means no semantic search. Answering with
        // keyword results under the name "semantic" would be a lie the client
        // has no way to detect.
        $this->assertSame(503, $result['status']);
        $this->assertSame('FEATURE_DISABLED', $result['body']['error']['code']);
        $this->assertSame('semantic_search', $result['body']['error']['details']['feature']);
    }

    public function testSemanticSearchSaysWhenItFellBackToKeyword(): void
    {
        $note = $this->note($this->alice, 'GST return', 'The gst refund is due next week.');

        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        // A configured but unreachable provider: the port nothing listens on.
        putenv('PULSE_API_URL=http://127.0.0.1:9');
        try {
            $result = $this->alice->post('/search/semantic', ['query' => 'gst refund']);
        } finally {
            putenv('NOTES_SEMANTIC_SEARCH_ENABLED');
            putenv('PULSE_API_URL');
        }

        $this->assertSame(200, $result['status']);
        $this->assertSame('keyword_fallback', $result['body']['meta']['mode'], 'the engine that answered is named');
        $this->assertSame([$note['id']], $this->ids($result));
    }

    public function testSemanticSearchNeedsSomethingToSearchFor(): void
    {
        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=http://127.0.0.1:9');
        try {
            $result = $this->alice->post('/search/semantic', ['query' => '  ']);
        } finally {
            putenv('NOTES_SEMANTIC_SEARCH_ENABLED');
            putenv('PULSE_API_URL');
        }

        $this->assertSame(422, $result['status']);
    }

    // -- Cost ---------------------------------------------------------------

    public function testSearchesAreRateLimited(): void
    {
        $this->search($this->alice, 'gst');
        $this->alice->get('/search/suggest', ['q' => 'gst']);

        $row = Connection::selectOne("SELECT hits FROM api_rate_limits WHERE bucket_key LIKE 'search:%'");
        $this->assertSame(2, (int) ($row['hits'] ?? 0), 'both cheap surfaces share the search budget');

        putenv('NOTES_SEMANTIC_SEARCH_ENABLED=true');
        putenv('PULSE_API_URL=http://127.0.0.1:9');
        try {
            $this->alice->post('/search/semantic', ['query' => 'gst']);
        } finally {
            putenv('NOTES_SEMANTIC_SEARCH_ENABLED');
            putenv('PULSE_API_URL');
        }

        // The expensive one has its own, much smaller, budget.
        $semantic = Connection::selectOne("SELECT hits FROM api_rate_limits WHERE bucket_key LIKE 'semantic:%'");
        $this->assertSame(1, (int) ($semantic['hits'] ?? 0));
    }
}
