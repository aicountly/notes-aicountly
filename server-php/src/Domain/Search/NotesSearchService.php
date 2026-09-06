<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Search;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Database\Connection;
use Aicountly\Api\Domain\Collaboration\NoteAccess;
use Aicountly\Api\Domain\Collaboration\NotePermissionService;
use Aicountly\Api\Domain\Notes\NoteQuery;
use Aicountly\Api\Domain\Notes\NoteRepository;
use Aicountly\Api\Env;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Finding a note again.
 *
 * Search in a notes product is not a feature, it is the retrieval half of the
 * product: notes are worth writing only if they can be found. Three shapes of
 * the same question live here, and they share their rules rather than their
 * results:
 *
 *   - `keyword()`      — what the search box calls, ranked by `ts_rank_cd`.
 *   - `retrieveForAi()` — the same corpus, chunked, as context for an answer.
 *   - `semantic()`     — nearest-neighbour over embeddings, honest about which
 *                        engine actually answered.
 *
 * Two invariants hold across all three, and neither is negotiable:
 *
 *   1. **Access is filtered before ranking.** Every query composes
 *      {@see NoteAccess::cte()}, so a note the caller cannot open never enters
 *      the candidate set — it is not ranked and then hidden, it is never
 *      considered. Ranking a note you may not read is how a snippet of it ends
 *      up in someone else's results.
 *   2. **A private note is never searchable.** `privacy_mode = 'private'` means
 *      the server holds ciphertext it cannot read. Its `search_vector` is
 *      meaningless, its snippet would be gibberish, and indexing it at all
 *      would contradict the promise the mode makes. It is excluded here, in the
 *      SQL, next to the trash and archive filters — not left to a caller.
 *
 * Full-text matching is always `websearch_to_tsquery` against the generated
 * `notes.search_vector` (A = title, B = body, C = OCR/transcript). There is no
 * `LIKE '%…%'` over a body anywhere in this class: it cannot use the GIN index,
 * it cannot rank, and it finds "cat" inside "certificate".
 */
final class NotesSearchService
{
    /**
     * Card columns, matching {@see NoteRepository}'s so `hydrate()` can present
     * a search hit and a list row identically. `document_json` is not among
     * them: a page of results must not ship twenty ProseMirror trees.
     */
    private const SUMMARY_COLUMNS = 'n.id, n.note_type, n.title, n.notebook_id, n.color,
        n.is_pinned, n.is_favourite, n.is_archived, n.is_locked, n.privacy_mode,
        n.version, n.word_count, n.char_count, n.owner_user_id,
        n.created_at, n.updated_at, n.deleted_at,
        left(n.extracted_text, 400) AS extracted_text';

    /** Everything the corpus filters agree on, before the caller's own filters. */
    private const VISIBLE = "n.deleted_at IS NULL
            AND n.privacy_mode <> 'private'
            AND (NOT n.is_archived OR :include_archived::boolean)";

    private const MAX_QUERY_CHARS = 300;
    private const MAX_LIMIT = 50;

    /** How much note text retrieval reads per candidate before chunking. */
    private const RETRIEVAL_BODY_CHARS = 20000;

    private ?bool $vectorColumn = null;

    public function __construct(
        private readonly NoteRepository $repository = new NoteRepository(),
        private readonly NotePermissionService $permissions = new NotePermissionService(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Keyword search
    // -----------------------------------------------------------------------

    /**
     * The search box.
     *
     * @param array{limit?: int, offset?: int, include_archived?: bool, sort?: string} $options
     * @return array{results: array<int, array<string, mixed>>, has_more: bool}
     */
    public function keyword(Identity $identity, string $query, NoteQuery $filters, array $options = []): array
    {
        $term = self::normaliseQuery($query);
        if ($term === '') {
            // A search box fires on mount and on every backspace to empty.
            // Nothing typed is an empty result, not a 422.
            return ['results' => [], 'has_more' => false];
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) ($options['limit'] ?? 20)));
        $offset = max(0, min(1000, (int) ($options['offset'] ?? 0)));
        [$innerOrder, $outerOrder] = self::orderClauses((string) ($options['sort'] ?? 'relevance'));

        // `hits` is limited before the outer query calls ts_headline, so the
        // expensive part runs for one page of rows rather than for every note
        // that matched. On a corpus of any size that is the whole difference.
        $sql = 'WITH RECURSIVE ' . NoteAccess::cte() . ",
                tsq AS (SELECT websearch_to_tsquery('english', :q::text) AS query),
                hits AS (
                    SELECT " . self::SUMMARY_COLUMNS . ', acc.role_rank,
                           ts_rank_cd(n.search_vector, tsq.query) AS rank
                    FROM notes n
                    JOIN note_access acc ON acc.note_id = n.id
                    CROSS JOIN tsq
                    WHERE ' . self::VISIBLE . '
                      AND n.search_vector @@ tsq.query' . $filters->sql() . '
                    ORDER BY ' . $innerOrder . '
                    LIMIT :limit OFFSET :offset
                )
                SELECT h.*, ts_headline(
                           \'english\',
                           ' . self::headlineSource() . ',
                           tsq.query,
                           :headline_opts::text
                       ) AS snippet
                FROM hits h
                JOIN notes n ON n.id = h.id
                CROSS JOIN tsq
                ORDER BY ' . $outerOrder;

        $rows = Connection::select($sql, [
            'auth_user' => $identity->userId,
            'auth_tenant' => $identity->tenantId,
            'q' => $term,
            'include_archived' => ($options['include_archived'] ?? false) === true,
            'headline_chars' => SearchSnippet::HEADLINE_SOURCE_CHARS,
            'hl_open' => SearchSnippet::HIGHLIGHT_OPEN,
            'hl_close' => SearchSnippet::HIGHLIGHT_CLOSE,
            'headline_opts' => SearchSnippet::headlineOptions(),
            // One row more than asked for: that row is the answer to
            // "is there another page?" without a second COUNT query.
            'limit' => $limit + 1,
            'offset' => $offset,
        ] + $filters->bindings());

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return ['results' => $this->present($rows, $identity, highlighted: true), 'has_more' => $hasMore];
    }

    // -----------------------------------------------------------------------
    // Retrieval for AI
    // -----------------------------------------------------------------------

    /**
     * Permission-filtered chunks for AI retrieval, highest relevance first.
     *
     * This is the boundary that keeps "ask my notes" from becoming a data
     * leak: the model can only ever be shown text that came back from here,
     * and what comes back from here went through the same access CTE as a
     * list. Filtering happens in the query — never after ranking, and never in
     * whatever assembles the prompt.
     *
     * @param array{notebook_id?: string, note_id?: string, include_archived?: bool} $scope
     * @return array<int, array{note_id: string, title: ?string, snippet: string, score: float, source_type: string, block_id: ?string}>
     */
    public function retrieveForAi(Identity $identity, string $query, int $limit = 8, array $scope = []): array
    {
        $term = self::normaliseQuery($query);
        if ($term === '') {
            return [];
        }

        $limit = max(1, min(40, $limit));
        $filters = NoteQuery::make();
        $scopeClause = '';
        $bindings = [];

        if (isset($scope['notebook_id']) && (string) $scope['notebook_id'] !== '') {
            // "May they read this notebook?" is answered in one place, and the
            // filter then follows its descendants the way the notes list does.
            $this->permissions->requireNotebook($identity, (string) $scope['notebook_id']);
            $filters->where('notebook', 'is', (string) $scope['notebook_id']);
        }

        if (isset($scope['note_id']) && (string) $scope['note_id'] !== '') {
            $noteId = (string) $scope['note_id'];
            if (!Uuid::isValid($noteId)) {
                throw ApiException::badRequest('That is not a note id.');
            }
            $this->permissions->requireNote($identity, $noteId, NotePermissionService::VIEW, columns: 'n.id');
            $scopeClause = ' AND n.id = :scope_note::uuid';
            $bindings['scope_note'] = strtolower($noteId);
        }

        // Scoped to one note, the note *is* the corpus: "what did we decide?"
        // asked of a note that never uses those words must still come back with
        // that note's text, so the full-text gate is dropped and the chunk
        // scoring below does the ordering on its own.
        $matchClause = $scopeClause === '' ? '
                  AND n.search_vector @@ tsq.query' : '';

        // More candidate notes than chunks asked for, so an answer can be
        // grounded in several notes rather than in the top note's first pages.
        $candidateLimit = min(60, max(4, $limit * 3));

        $sql = 'WITH RECURSIVE ' . NoteAccess::cte() . ",
                tsq AS (SELECT websearch_to_tsquery('english', :q::text) AS query)
                SELECT n.id, n.title,
                       ts_rank_cd(n.search_vector, tsq.query) AS rank,
                       left(n.extracted_text, :body_chars::int) AS extracted_text,
                       left(n.derived_text, :body_chars::int) AS derived_text
                FROM notes n
                JOIN note_access acc ON acc.note_id = n.id
                CROSS JOIN tsq
                WHERE " . self::VISIBLE . $matchClause . $scopeClause . $filters->sql() . '
                ORDER BY rank DESC, n.updated_at DESC, n.id DESC
                LIMIT :limit';

        $rows = Connection::select($sql, [
            'auth_user' => $identity->userId,
            'auth_tenant' => $identity->tenantId,
            'q' => $term,
            'include_archived' => ($scope['include_archived'] ?? false) === true,
            'body_chars' => self::RETRIEVAL_BODY_CHARS,
            'limit' => $candidateLimit,
        ] + $bindings + $filters->bindings());

        return $this->rankChunks($rows, $term, $limit);
    }

    /**
     * Cut the candidate notes into chunks and order the chunks themselves.
     *
     * A note's `ts_rank_cd` says the note is relevant; it does not say which
     * 1 200 characters of it are. So the note's own rank is a small share of a
     * chunk's score, and the rest is how much of the query that particular
     * chunk actually contains.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{note_id: string, title: ?string, snippet: string, score: float, source_type: string, block_id: ?string}>
     */
    private function rankChunks(array $rows, string $term, int $limit): array
    {
        if ($rows === []) {
            return [];
        }

        $terms = self::terms($term);
        $maxRank = 0.0;
        foreach ($rows as $row) {
            $maxRank = max($maxRank, (float) $row['rank']);
        }
        $maxRank = $maxRank > 0.0 ? $maxRank : 1.0;

        $chunks = [];
        foreach ($rows as $row) {
            $noteRank = (float) $row['rank'] / $maxRank;
            $title = $row['title'] === null ? null : (string) $row['title'];

            // `note` is the document the user wrote; `derived` is OCR and
            // transcript text the pipeline rolled into notes.derived_text.
            foreach (['note' => (string) $row['extracted_text'], 'derived' => (string) $row['derived_text']] as $sourceType => $text) {
                foreach (SearchSnippet::chunk($text) as $index => $chunk) {
                    $score = self::chunkScore($chunk, $terms, $noteRank);

                    // A note that matched on its title alone has no chunk
                    // containing the query; its opening still belongs in the
                    // context, so it is kept once and ranked last.
                    if ($score <= 0.0 && !($sourceType === 'note' && $index === 0)) {
                        continue;
                    }

                    $chunks[] = [
                        'note_id' => (string) $row['id'],
                        'title' => $title,
                        // The chunk is verbatim note text: markers a user typed
                        // are stripped so nothing here can pose as a highlight.
                        'snippet' => SearchSnippet::stripMarkers($chunk),
                        'score' => round($score, 6),
                        'source_type' => $sourceType,
                        // Null by construction: `extracted_text` is the
                        // flattened document and has no block boundaries left.
                        // The field exists for attachment and transcript chunks,
                        // which carry the block they were rendered in.
                        'block_id' => null,
                    ];
                }
            }
        }

        usort($chunks, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($chunks, 0, $limit);
    }

    // -----------------------------------------------------------------------
    // Semantic search
    // -----------------------------------------------------------------------

    /**
     * Nearest-neighbour search over stored embeddings.
     *
     * `mode` is part of the answer, not diagnostics. Three things can be true
     * on a given deployment — pgvector is installed, only the JSON fallback
     * exists, or the embedding provider did not answer — and a client that was
     * told "semantic" while it received keyword results would draw exactly the
     * wrong conclusion about why a note is missing. So the engine that actually
     * ran is named:
     *
     *   vector           — pgvector, cosine distance over the ivfflat index.
     *   json_fallback    — the same cosine, computed in SQL from embedding_json.
     *   keyword_fallback — no usable embedding, so `ts_rank_cd` answered.
     *
     * @return array{results: array<int, array<string, mixed>>, mode: string}
     */
    public function semantic(Identity $identity, string $query, int $limit = 20): array
    {
        // Semantic search needs an embedding provider. Without one configured
        // the honest answer is 503, not a keyword search wearing its name.
        Features::require(Features::SEMANTIC_SEARCH);

        $term = self::normaliseQuery($query);
        if ($term === '') {
            throw ApiException::validation(['query' => 'Type something to search for.']);
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $embedded = $this->embedQuery($term);
        if ($embedded === null) {
            return $this->keywordFallback($identity, $term, $limit);
        }

        $usesVector = $this->vectorColumnAvailable();
        $rows = $usesVector
            ? $this->nearestByVector($identity, $embedded, $limit)
            : $this->nearestByJson($identity, $embedded, $limit);

        if ($rows === []) {
            // No embedded chunk the caller may read — an index that has not
            // been built yet, not "there is nothing like this". Keyword results
            // are more useful than an empty page, as long as we say so.
            return $this->keywordFallback($identity, $term, $limit);
        }

        return [
            'results' => $this->present($rows, $identity, highlighted: false),
            'mode' => $usesVector ? 'vector' : 'json_fallback',
        ];
    }

    /** @return array{results: array<int, array<string, mixed>>, mode: string} */
    private function keywordFallback(Identity $identity, string $term, int $limit): array
    {
        return [
            'results' => $this->keyword($identity, $term, NoteQuery::make(), ['limit' => $limit])['results'],
            'mode' => 'keyword_fallback',
        ];
    }

    /**
     * pgvector: cosine distance, index-assisted.
     *
     * @param array{vector: array<int, float>, model: string} $embedded
     * @return array<int, array<string, mixed>>
     */
    private function nearestByVector(Identity $identity, array $embedded, int $limit): array
    {
        $sql = 'WITH RECURSIVE ' . NoteAccess::cte() . ',
                scored AS (
                    SELECT e.note_id, e.chunk_text,
                           1 - (e.embedding <=> :query_vector::vector) AS score
                    FROM note_embeddings e
                    JOIN notes n ON n.id = e.note_id
                    JOIN note_access acc ON acc.note_id = e.note_id
                    WHERE ' . self::VISIBLE . '
                      AND e.embedding IS NOT NULL
                      AND e.model = :model::text
                ),
                best AS (
                    SELECT DISTINCT ON (s.note_id) s.note_id, s.chunk_text, s.score
                    FROM scored s
                    ORDER BY s.note_id, s.score DESC
                )
                SELECT ' . self::SUMMARY_COLUMNS . ', acc.role_rank,
                       b.score AS rank, left(b.chunk_text, 400) AS snippet
                FROM best b
                JOIN notes n ON n.id = b.note_id
                JOIN note_access acc ON acc.note_id = n.id
                ORDER BY b.score DESC NULLS LAST
                LIMIT :limit';

        return Connection::select($sql, [
            'auth_user' => $identity->userId,
            'auth_tenant' => $identity->tenantId,
            'include_archived' => false,
            'query_vector' => '[' . implode(',', $embedded['vector']) . ']',
            'model' => $embedded['model'],
            'limit' => $limit,
        ]);
    }

    /**
     * The portable fallback: cosine similarity computed in SQL from the JSON
     * array every deployment stores, pgvector or not.
     *
     * Only chunks of the same model *and* the same dimensionality are scored.
     * Comparing an embedding to one from another model is not a weaker answer,
     * it is a meaningless one, and mismatched lengths would silently score on
     * whatever prefix happened to line up.
     *
     * @param array{vector: array<int, float>, model: string} $embedded
     * @return array<int, array<string, mixed>>
     */
    private function nearestByJson(Identity $identity, array $embedded, int $limit): array
    {
        $sql = 'WITH RECURSIVE ' . NoteAccess::cte() . ",
                candidates AS (
                    SELECT e.id, e.note_id, e.chunk_text, e.embedding_json
                    FROM note_embeddings e
                    JOIN notes n ON n.id = e.note_id
                    JOIN note_access acc ON acc.note_id = e.note_id
                    WHERE " . self::VISIBLE . "
                      AND e.embedding_json IS NOT NULL
                      AND jsonb_typeof(e.embedding_json) = 'array'
                      AND jsonb_array_length(e.embedding_json) = :dimensions::int
                      AND e.model = :model::text
                ),
                scored AS (
                    SELECT c.note_id, c.chunk_text,
                           sum(stored.val * probe.val)
                             / nullif(sqrt(sum(stored.val * stored.val)) * sqrt(sum(probe.val * probe.val)), 0) AS score
                    FROM candidates c
                    CROSS JOIN LATERAL (
                        SELECT t.ord, t.value::float8 AS val
                        FROM jsonb_array_elements_text(c.embedding_json) WITH ORDINALITY AS t(value, ord)
                    ) stored
                    JOIN LATERAL (
                        SELECT t.ord, t.value::float8 AS val
                        FROM jsonb_array_elements_text(:query_vector::jsonb) WITH ORDINALITY AS t(value, ord)
                    ) probe ON probe.ord = stored.ord
                    GROUP BY c.id, c.note_id, c.chunk_text
                ),
                best AS (
                    SELECT DISTINCT ON (s.note_id) s.note_id, s.chunk_text, s.score
                    FROM scored s
                    ORDER BY s.note_id, s.score DESC
                )
                SELECT " . self::SUMMARY_COLUMNS . ', acc.role_rank,
                       b.score AS rank, left(b.chunk_text, 400) AS snippet
                FROM best b
                JOIN notes n ON n.id = b.note_id
                JOIN note_access acc ON acc.note_id = n.id
                ORDER BY b.score DESC NULLS LAST
                LIMIT :limit';

        return Connection::select($sql, [
            'auth_user' => $identity->userId,
            'auth_tenant' => $identity->tenantId,
            'include_archived' => false,
            'query_vector' => (string) json_encode($embedded['vector']),
            'dimensions' => count($embedded['vector']),
            'model' => $embedded['model'],
            'limit' => $limit,
        ]);
    }

    /** Does this database have the pgvector column migration 0008 adds? */
    private function vectorColumnAvailable(): bool
    {
        // `to_regclass` resolves through the connection's search_path, so this
        // answers for the schema the API is actually reading.
        return $this->vectorColumn ??= Connection::selectOne(
            "SELECT 1 AS present FROM pg_attribute
             WHERE attrelid = to_regclass('note_embeddings')
               AND attname = 'embedding' AND NOT attisdropped",
        ) !== null;
    }

    /**
     * Turn the query into a vector, using the configured embedding provider.
     *
     * Deliberately fails soft: a provider that is slow, down or misconfigured
     * degrades the request to keyword search — which the caller is told about —
     * rather than turning the search box into a 502. Nothing about the query
     * text reaches the log.
     *
     * @return array{vector: array<int, float>, model: string}|null
     */
    private function embedQuery(string $query): ?array
    {
        $base = rtrim(Env::get('PULSE_API_URL'), '/');
        if ($base === '') {
            return null;
        }

        $model = Env::get('PULSE_EMBEDDING_MODEL', 'text-embedding-3-small');
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (Env::get('PULSE_API_KEY') !== '') {
            $headers[] = 'Authorization: Bearer ' . Env::get('PULSE_API_KEY');
        }

        $handle = curl_init($base . '/embeddings');
        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => (string) json_encode(['model' => $model, 'input' => $query]),
            // A search box cannot wait: an embedding that has not arrived in a
            // few seconds is worth less than keyword results returned now.
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            Logger::warn('search.embedding_unavailable', ['status' => $status]);

            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::warn('search.embedding_unreadable', ['status' => $status]);

            return null;
        }

        $raw = $decoded['data'][0]['embedding'] ?? $decoded['embedding'] ?? null;
        if (!is_array($raw) || count($raw) < 8 || count($raw) > 4096) {
            Logger::warn('search.embedding_unreadable', ['status' => $status]);

            return null;
        }

        $vector = [];
        foreach ($raw as $value) {
            if (!is_numeric($value)) {
                Logger::warn('search.embedding_unreadable', ['status' => $status]);

                return null;
            }
            $vector[] = (float) $value;
        }

        return [
            'vector' => $vector,
            // The provider's own name for what it returned, so the stored
            // chunks filtered on `model` are the ones this vector belongs with.
            'model' => Str::limit((string) ($decoded['model'] ?? $model), 120),
        ];
    }

    // -----------------------------------------------------------------------
    // Suggestions
    // -----------------------------------------------------------------------

    /**
     * Type-ahead for the search box.
     *
     * Three cheap prefix lookups over things that are already short — note
     * titles, tag slugs, notebook names. Bodies are not touched: this runs on
     * every keystroke, and full-text ranking a partial word on each one is how
     * a search box starts costing more than the search it precedes.
     *
     * @return array{query: string, notes: array<int, array<string, mixed>>, tags: array<int, array<string, mixed>>, notebooks: array<int, array<string, mixed>>}
     */
    public function suggest(Identity $identity, string $query, int $limit = 5): array
    {
        $term = self::normaliseQuery($query);
        $limit = max(1, min(10, $limit));
        $empty = ['query' => $term, 'notes' => [], 'tags' => [], 'notebooks' => []];
        if ($term === '') {
            return $empty;
        }

        $scope = ['auth_user' => $identity->userId, 'auth_tenant' => $identity->tenantId];
        // Matches at the start of the title or at the start of any word in it,
        // which is what "gst" finding "Draft GST return" requires.
        $like = ['prefix' => self::likePattern($term), 'word' => '% ' . self::likePattern($term)];

        $notes = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::cte() . "
             SELECT n.id, n.title, n.note_type, n.updated_at
             FROM notes n
             JOIN note_access acc ON acc.note_id = n.id
             WHERE n.deleted_at IS NULL AND NOT n.is_archived
               AND n.privacy_mode <> 'private'
               AND n.title IS NOT NULL
               AND (lower(n.title) LIKE :prefix::text OR lower(n.title) LIKE :word::text)
             ORDER BY n.updated_at DESC
             LIMIT :limit",
            $scope + $like + ['limit' => $limit],
        );

        // Tags carry no note content and belong to exactly one user, so
        // ownership is the whole gate here — the access CTE has nothing to add
        // that `owner_user_id` does not already say.
        $tags = Connection::select(
            'SELECT id, name, slug, color FROM tags
             WHERE owner_user_id = :auth_user AND slug LIKE :prefix::text
             ORDER BY slug
             LIMIT :limit',
            ['auth_user' => $identity->userId, 'prefix' => self::likePattern(Str::tagSlug($term)), 'limit' => $limit],
        );

        $notebooks = Connection::select(
            'WITH RECURSIVE ' . NoteAccess::notebookCte() . '
             SELECT nb.id, nb.name, nb.icon, nb.color
             FROM notebooks nb
             JOIN notebook_access acc ON acc.notebook_id = nb.id
             WHERE nb.deleted_at IS NULL AND NOT nb.is_archived
               AND (lower(nb.name) LIKE :prefix::text OR lower(nb.name) LIKE :word::text)
             ORDER BY lower(nb.name)
             LIMIT :limit',
            $scope + $like + ['limit' => $limit],
        );

        return [
            'query' => $term,
            'notes' => array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'title' => (string) $row['title'],
                'note_type' => (string) $row['note_type'],
                'updated_at' => (string) $row['updated_at'],
            ], $notes),
            'tags' => array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'color' => $row['color'] === null ? null : (string) $row['color'],
            ], $tags),
            'notebooks' => array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'icon' => $row['icon'] === null ? null : (string) $row['icon'],
                'color' => $row['color'] === null ? null : (string) $row['color'],
            ], $notebooks),
        ];
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * Rows → API resources, with the snippet and score attached.
     *
     * Presentation goes through {@see NoteRepository::hydrate()} so a search
     * result carries the same tags, counts and role a list row does; a result
     * card and a note card are then the same component.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function present(array $rows, Identity $identity, bool $highlighted): array
    {
        if ($rows === []) {
            return [];
        }

        $extras = [];
        foreach ($rows as $row) {
            $snippet = (string) ($row['snippet'] ?? '');
            $extras[(string) $row['id']] = [
                // Only a snippet this server highlighted may carry markers.
                'snippet' => $highlighted ? $snippet : SearchSnippet::preview($snippet),
                'score' => round((float) ($row['rank'] ?? 0), 6),
            ];
        }

        $notes = $this->repository->hydrate($rows, $identity);
        foreach ($notes as $index => $note) {
            $notes[$index] = array_merge($note, $extras[(string) $note['id']] ?? ['snippet' => '', 'score' => 0.0]);
        }

        return $notes;
    }

    /**
     * The `ts_headline` input.
     *
     * The two `replace()` calls remove any highlight marker the note's author
     * typed, before the highlighter adds its own. Without them a note could
     * ship the client a highlight that no search term produced — harmless to
     * render, but a lie about where the match was.
     */
    private static function headlineSource(): string
    {
        return 'replace(replace(left(n.extracted_text, :headline_chars::int), :hl_open::text, \'\'), :hl_close::text, \'\')';
    }

    /**
     * Ordering, whitelisted.
     *
     * The inner clause orders candidates before the page is cut; the outer one
     * re-states it after the join that adds the snippet, because a join does
     * not preserve a subquery's order.
     *
     * @return array{0: string, 1: string}
     */
    private static function orderClauses(string $sort): array
    {
        return match ($sort) {
            'updated_desc' => ['n.updated_at DESC, n.id DESC', 'h.updated_at DESC, h.id DESC'],
            'created_desc' => ['n.created_at DESC, n.id DESC', 'h.created_at DESC, h.id DESC'],
            default => [
                'rank DESC, n.updated_at DESC, n.id DESC',
                'h.rank DESC, h.updated_at DESC, h.id DESC',
            ],
        };
    }

    private static function normaliseQuery(string $query): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $query) ?? $query), self::MAX_QUERY_CHARS);
    }

    /**
     * Distinct query words, for scoring a chunk.
     *
     * @return array<int, string>
     */
    private static function terms(string $query): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token, 'UTF-8') >= 2,
        )));

        return array_slice($terms, 0, 12);
    }

    /**
     * How well one chunk answers the query.
     *
     * Coverage dominates — a chunk containing four of the five words asked
     * about is the one worth showing a model — with a small allowance for
     * repetition and a smaller one for the note's own rank. Matching is on
     * substrings so "invoice" still scores a chunk that says "invoices";
     * `ts_rank_cd` did the stemmed work of choosing the note.
     *
     * @param array<int, string> $terms
     */
    private static function chunkScore(string $chunk, array $terms, float $noteRank): float
    {
        if ($terms === []) {
            return $noteRank;
        }

        $haystack = mb_strtolower($chunk, 'UTF-8');
        $matched = 0;
        $occurrences = 0;
        foreach ($terms as $term) {
            $count = substr_count($haystack, $term);
            if ($count > 0) {
                $matched++;
                $occurrences += $count;
            }
        }

        if ($matched === 0) {
            return 0.0;
        }

        $coverage = $matched / count($terms);
        $density = min(1.0, $occurrences / (count($terms) * 3));

        return 0.65 * $coverage + 0.20 * $density + 0.15 * $noteRank;
    }

    /**
     * A LIKE prefix pattern built from user input.
     *
     * `%` and `_` are wildcards and a backslash is LIKE's escape character, so
     * all three are escaped: otherwise typing `%` in the search box turns a
     * bounded prefix lookup into a full scan that matches everything.
     */
    private static function likePattern(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($value, 'UTF-8'));

        return $escaped . '%';
    }
}
