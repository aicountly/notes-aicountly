<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth\Identity;
use Aicountly\Api\Domain\Notes\NoteQuery;
use Aicountly\Api\Domain\Search\NotesSearchService;
use Aicountly\Api\Domain\Search\SearchSnippet;
use Aicountly\Api\Http\RateLimiter;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;

/**
 * HTTP for search.
 *
 * Thin on purpose: it turns a query string into a {@see NoteQuery} and hands
 * over. Which notes may be searched, what a snippet may contain and which
 * engine answered a semantic request are all decided in
 * {@see NotesSearchService} — a search endpoint that made any of those
 * decisions for itself would be a second, unreviewed access-control path.
 *
 * The rate-limit buckets differ because the costs do. Keyword search and
 * suggestions are index lookups and sit in the generous `search` bucket;
 * `semantic` calls an embedding provider per request and sits in its own.
 */
final class SearchController
{
    public function __construct(private readonly NotesSearchService $search = new NotesSearchService())
    {
    }

    /**
     * `GET /search/notes?q=…`
     *
     * Filters compose with the query, so "invoices in Clients tagged gst,
     * with an attachment" is one request rather than a search the client then
     * has to filter itself.
     */
    public function notes(Request $request, Identity $identity): Response
    {
        RateLimiter::hit('search', $identity->userId);

        $query = $request->queryString('q');
        $limit = $request->queryInt('limit', 20, 1, 50);
        $offset = $request->queryInt('offset', 0, 0, 1000);

        $result = $this->search->keyword($identity, $query, $this->filtersFromQuery($request), [
            'limit' => $limit,
            'offset' => $offset,
            'include_archived' => $request->queryBool('include_archived') === true,
            'sort' => $request->queryString('sort', 'relevance'),
        ]);

        return Response::ok($result['results'], [
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $result['has_more'],
            // The snippet delimiters, so the client has one source of truth for
            // them and never has to hard-code a marker the server might change.
            // They are delimiters, not markup: split on them and render each
            // piece as text.
            'highlight' => [
                'open' => SearchSnippet::HIGHLIGHT_OPEN,
                'close' => SearchSnippet::HIGHLIGHT_CLOSE,
            ],
        ]);
    }

    /** `GET /search/suggest?q=…` — type-ahead for the search box. */
    public function suggest(Request $request, Identity $identity): Response
    {
        RateLimiter::hit('search', $identity->userId);

        return Response::ok($this->search->suggest(
            $identity,
            $request->queryString('q'),
            $request->queryInt('limit', 5, 1, 10),
        ));
    }

    /**
     * `POST /search/semantic`
     *
     * A POST because the query is the payload of an AI-shaped request, and
     * because it must not land in a browser history or an access log next to
     * the note ids it returns.
     */
    public function semantic(Request $request, Identity $identity): Response
    {
        RateLimiter::hit('semantic', $identity->userId);

        $limit = $request->input('limit', 20);
        $result = $this->search->semantic(
            $identity,
            $request->string('query'),
            is_numeric($limit) ? (int) $limit : 20,
        );

        return Response::ok($result['results'], [
            // Which engine actually answered. A client that shows "semantic
            // results" while `mode` says otherwise is misreporting, so this
            // travels with every response rather than only with the failures.
            'mode' => $result['mode'],
        ]);
    }

    // -----------------------------------------------------------------------

    /** Translate `?notebook_id=&tags=&note_type=…` into the shared filter builder. */
    private function filtersFromQuery(Request $request): NoteQuery
    {
        $filters = NoteQuery::make();

        if (($notebook = $request->queryString('notebook_id')) !== '') {
            $filters->where('notebook', 'is', $notebook);
        }
        foreach ($request->queryList('tags') as $tag) {
            $filters->where('tag', 'is', $tag);
        }
        if (($type = $request->queryString('note_type')) !== '') {
            $filters->where('note_type', 'is', $type);
        }
        if (($color = $request->queryString('color')) !== '') {
            $filters->where('color', 'is', $color);
        }
        if (($owner = $request->queryString('owner')) !== '') {
            $filters->where('owner', 'is', $owner);
        }
        if ($request->queryBool('favourite') !== null) {
            $filters->where('is_favourite', 'is', $request->queryBool('favourite'));
        }
        if ($request->queryBool('has_attachment') !== null) {
            $filters->where('has_attachment', 'is', $request->queryBool('has_attachment'));
        }
        if ($request->queryBool('has_reminder') !== null) {
            $filters->where('has_reminder', 'is', $request->queryBool('has_reminder'));
        }
        if (($created = $request->queryString('created_after')) !== '') {
            $filters->where('created_at', 'after', $created);
        }
        if (($updated = $request->queryString('updated_after')) !== '') {
            $filters->where('updated_at', 'after', $updated);
        }
        if (($days = $request->queryInt('within_days', 0, 0, 3650)) > 0) {
            $filters->where('updated_at', 'within_days', $days);
        }

        return $filters;
    }
}
