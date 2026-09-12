/**
 * The queries behind search.
 *
 * Three endpoints answer the search dialog, and they answer different
 * questions rather than the same one three ways:
 *
 *   - `GET /search/notes` — ranked note hits. The snippet markers travel in
 *     `meta.highlight`, so this app never hard-codes a delimiter the server is
 *     free to change.
 *   - `GET /search/suggest` — the notebooks and tags whose *names* match. The
 *     Notebooks and Tags tabs are a different index, not a filtered slice of
 *     the note results.
 *   - `POST /search/semantic` — nearest-neighbour search, which reports the
 *     `mode` it actually ran in. That value is passed through untouched:
 *     keyword results presented as semantic ones would be a lie the UI tells
 *     on the server's behalf.
 *
 * Every read hands React Query's `signal` to the API client, so the request
 * for "inv" is aborted the moment "invoice" replaces it. With the debounce in
 * front of it, that is the difference between one request per search and one
 * per keystroke.
 */

import { useCallback, useEffect, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import { DEFAULT_MARKERS } from '../components/Highlight'
import type { HighlightMarkers } from '../components/Highlight'
import type {
  ApiMeta,
  Notebook,
  NoteSummary,
  NoteType,
  SearchHit,
  SearchResponse,
  Tag,
} from '../../../shared/api/types'

/** The server's own ceilings: `limit` is clamped to 50, `q` to 300 characters. */
export const SEARCH_PAGE_SIZE = 20
export const SEARCH_MAX_PAGE = 50
export const MAX_QUERY_LENGTH = 300

export type SearchMode = NonNullable<SearchResponse['mode']>

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

export interface SearchFilterState {
  /** `updated_at within_days`. Null is "any time". */
  withinDays: number | null
  notebookId: string | null
  /** A tag slug — what the server's `tags` filter matches on. */
  tagSlug: string | null
  noteType: NoteType | null
  hasAttachment: boolean
  includeArchived: boolean
  /**
   * `/search/notes` accepts neither of these, so they are applied to the rows
   * it returns rather than sent. The panel says as much next to them — a
   * refinement that silently means something different from the filters above
   * it would be worse than not offering it.
   */
  pinnedOnly: boolean
  sharedOnly: boolean
}

export const NO_FILTERS: SearchFilterState = {
  withinDays: null,
  notebookId: null,
  tagSlug: null,
  noteType: null,
  hasAttachment: false,
  includeArchived: false,
  pinnedOnly: false,
  sharedOnly: false,
}

export function activeFilterCount(filters: SearchFilterState): number {
  return [
    filters.withinDays !== null,
    filters.notebookId !== null,
    filters.tagSlug !== null,
    filters.noteType !== null,
    filters.hasAttachment,
    filters.includeArchived,
    filters.pinnedOnly,
    filters.sharedOnly,
  ].filter(Boolean).length
}

/** The refinements the endpoint cannot express, applied to what it returned. */
export function applyRefinements(notes: NoteResult[], filters: SearchFilterState): NoteResult[] {
  if (!filters.pinnedOnly && !filters.sharedOnly) return notes

  return notes.filter(
    (note) => (!filters.pinnedOnly || note.isPinned) && (!filters.sharedOnly || note.isShared),
  )
}

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------

/**
 * A hit as it arrives.
 *
 * `NotesSearchService::present()` builds a result by hydrating the row through
 * the same presenter a list row uses and appending `snippet` and `score` — so
 * a hit is a note summary keyed by `id`. `SearchHit` in `shared/api/types.ts`
 * describes a flatter, `note_id`-keyed shape; both are accepted here so the
 * dialog keeps working whichever a deployment sends, and the mismatch is
 * reported rather than papered over with a cast.
 */
export type SearchRow = SearchHit | (NoteSummary & { snippet?: string; score?: number })

/** What a result row needs, with the two wire shapes reconciled. */
export interface NoteResult {
  noteId: string
  displayTitle: string
  noteType: NoteType
  snippet: string
  updatedAt: string | null
  tags: Tag[]
  isPinned: boolean
  isShared: boolean
  attachmentCount: number
}

function toNoteResult(row: SearchRow): NoteResult {
  return {
    noteId: 'note_id' in row ? row.note_id : row.id,
    displayTitle: row.display_title,
    noteType: row.note_type,
    snippet: row.snippet ?? '',
    updatedAt: row.updated_at,
    tags: row.tags ?? [],
    isPinned: 'note_id' in row ? false : row.is_pinned,
    isShared: 'note_id' in row ? false : row.is_shared,
    attachmentCount: 'note_id' in row ? 0 : row.attachment_count,
  }
}

export interface SearchResults {
  notes: NoteResult[]
  hasMore: boolean
  markers: HighlightMarkers
  /** Only semantic search reports one; keyword search leaves it null. */
  mode: SearchMode | null
}

/** `meta.highlight`, validated — an unreadable meta falls back to the default. */
function readMarkers(meta: ApiMeta): HighlightMarkers {
  const highlight = meta.highlight
  if (highlight !== null && typeof highlight === 'object') {
    const { open, close } = highlight as { open?: unknown; close?: unknown }
    if (typeof open === 'string' && open !== '' && typeof close === 'string' && close !== '') {
      return { open, close }
    }
  }
  return DEFAULT_MARKERS
}

const MODES: readonly SearchMode[] = ['vector', 'json_fallback', 'keyword_fallback', 'keyword']

function readMode(meta: ApiMeta): SearchMode | null {
  const mode = meta.mode
  return typeof mode === 'string' && (MODES as readonly string[]).includes(mode)
    ? (mode as SearchMode)
    : null
}

function toSearchQuery(
  query: string,
  filters: SearchFilterState,
  limit: number,
): Record<string, string | number | boolean | undefined> {
  return {
    q: query,
    limit,
    notebook_id: filters.notebookId ?? undefined,
    tags: filters.tagSlug ?? undefined,
    note_type: filters.noteType ?? undefined,
    // Sent only when on: `has_attachment=false` is a filter for notes *without*
    // one, which is not what an unticked box means.
    has_attachment: filters.hasAttachment ? true : undefined,
    within_days: filters.withinDays ?? undefined,
    include_archived: filters.includeArchived ? true : undefined,
  }
}

export interface NoteSearchOptions {
  query: string
  filters: SearchFilterState
  /** Ask the embedding index instead of the full-text one. */
  semantic: boolean
  limit?: number
  enabled?: boolean
}

export function useNoteSearch({
  query,
  filters,
  semantic,
  limit = SEARCH_PAGE_SIZE,
  enabled = true,
}: NoteSearchOptions): UseQueryResult<SearchResults, ApiError> {
  const term = query.trim().slice(0, MAX_QUERY_LENGTH)

  return useQuery<SearchResults, ApiError>({
    queryKey: queryKeys.search(term, { ...filters, semantic, limit }),
    enabled: enabled && term !== '',
    // Results stay on screen while the next query is in flight; blanking the
    // list on every keystroke is what makes a search box feel like it is
    // fighting you.
    placeholderData: keepPreviousData,
    queryFn: async ({ signal }) => {
      if (semantic) {
        const { data, meta } = await api.request<SearchRow[]>('POST', '/search/semantic', {
          body: { query: term, limit },
          signal,
        })

        return {
          notes: data.map(toNoteResult),
          hasMore: false,
          markers: readMarkers(meta),
          mode: readMode(meta),
        }
      }

      const { data, meta } = await api.getWithMeta<SearchRow[]>('/search/notes', {
        query: toSearchQuery(term, filters, limit),
        signal,
      })

      return {
        notes: data.map(toNoteResult),
        hasMore: Boolean(meta.has_more),
        markers: readMarkers(meta),
        mode: null,
      }
    },
  })
}

// ---------------------------------------------------------------------------
// Name matches: the Notebooks and Tags tabs
// ---------------------------------------------------------------------------

/** `GET /search/suggest`. Its rows are narrower than the full resources. */
export interface SearchSuggestions {
  query: string
  notes: Array<{ id: string; title: string; note_type: NoteType; updated_at: string }>
  tags: Tag[]
  notebooks: Array<Pick<Notebook, 'id' | 'name' | 'icon' | 'color'>>
}

export function useSearchSuggestions(
  query: string,
  enabled = true,
): UseQueryResult<SearchSuggestions, ApiError> {
  const term = query.trim().slice(0, MAX_QUERY_LENGTH)

  return useQuery<SearchSuggestions, ApiError>({
    queryKey: queryKeys.search(term, { kind: 'suggest' }),
    enabled: enabled && term !== '',
    placeholderData: keepPreviousData,
    queryFn: ({ signal }) =>
      api.get<SearchSuggestions>('/search/suggest', { query: { q: term, limit: 10 }, signal }),
  })
}

// ---------------------------------------------------------------------------
// Lookups for the filter panel and the palette's "go to" pickers
// ---------------------------------------------------------------------------

export interface NotebookOption {
  id: string
  name: string
  /** Nesting level, so a child reads as a child in a flat <select>. */
  depth: number
}

function flatten(notebooks: Notebook[], depth = 0): NotebookOption[] {
  return notebooks.flatMap((notebook) => [
    { id: notebook.id, name: notebook.name, depth },
    ...flatten(notebook.children ?? [], depth + 1),
  ])
}

/**
 * Both lookups are fetched only once something needs them — the filter panel
 * is opened, or a "go to" picker is entered. Loading every notebook and tag to
 * render a search box that may never be filtered is a request for nothing.
 *
 * **Flattened in `select`, not in `queryFn`.** `['notebooks']` is one cache
 * entry shared with the sidebar tree and with every screen that needs a
 * notebook's name, and they all expect the nested rows the API returns.
 * Flattening before the cache stored `{id, name, depth}` objects under that
 * key instead, so whichever query ran first decided what the others read: open
 * this picker and the sidebar tree silently lost every child notebook, because
 * the flattened rows have no `children`; mount the sidebar first and the
 * picker listed only root notebooks, unindented. `select` transforms per
 * reader and leaves the cache holding what everyone else came for.
 */
export function useNotebookOptions(enabled: boolean): UseQueryResult<NotebookOption[], ApiError> {
  return useQuery<Notebook[], ApiError, NotebookOption[]>({
    queryKey: queryKeys.notebooks,
    enabled,
    staleTime: 5 * 60_000,
    queryFn: ({ signal }) => api.get<Notebook[]>('/notebooks', { signal }),
    select: flatten,
  })
}

export function useTagOptions(enabled: boolean): UseQueryResult<Tag[], ApiError> {
  return useQuery<Tag[], ApiError>({
    queryKey: queryKeys.tags,
    enabled,
    staleTime: 5 * 60_000,
    queryFn: ({ signal }) => api.get<Tag[]>('/tags', { signal }),
  })
}

// ---------------------------------------------------------------------------
// Typing
// ---------------------------------------------------------------------------

/** ~200ms: long enough to skip the middle of a word, short enough to feel live. */
export const SEARCH_DEBOUNCE_MS = 200

export function useDebounced<T>(value: T, delayMs = SEARCH_DEBOUNCE_MS): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    if (value === debounced) return undefined

    const timer = window.setTimeout(() => setDebounced(value), delayMs)
    return () => window.clearTimeout(timer)
  }, [value, debounced, delayMs])

  return debounced
}

// ---------------------------------------------------------------------------
// Recent searches
// ---------------------------------------------------------------------------

const RECENT_KEY = 'notes:recent-searches'
const RECENT_LIMIT = 8

function readRecent(): string[] {
  try {
    const raw = window.localStorage.getItem(RECENT_KEY)
    if (raw === null) return []

    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []

    return parsed.filter((entry): entry is string => typeof entry === 'string' && entry !== '')
  } catch {
    // Private mode, or something else wrote nonsense under this key. Neither is
    // worth failing a render over.
    return []
  }
}

function writeRecent(entries: string[]): void {
  try {
    window.localStorage.setItem(RECENT_KEY, JSON.stringify(entries))
  } catch {
    /* The list simply does not persist. */
  }
}

export interface RecentSearches {
  recent: string[]
  remember: (query: string) => void
  forget: (query: string) => void
  clear: () => void
  /**
   * Re-read the stored list.
   *
   * The search dialog is mounted more than once — the shell has one and the
   * command palette carries its own — and each copy holds this state. Without
   * a re-read on open, a search made in one is missing from the other's list
   * until the page is reloaded.
   */
  reload: () => void
}

export function useRecentSearches(): RecentSearches {
  const [recent, setRecent] = useState<string[]>(readRecent)

  const persist = useCallback((next: string[]) => {
    setRecent(next)
    writeRecent(next)
  }, [])

  const reload = useCallback(() => setRecent(readRecent()), [])

  const remember = useCallback(
    (query: string) => {
      const term = query.trim()
      if (term === '') return

      setRecent((current) => {
        const next = [term, ...current.filter((entry) => entry !== term)].slice(0, RECENT_LIMIT)
        writeRecent(next)
        return next
      })
    },
    [],
  )

  const forget = useCallback(
    (query: string) => persist(recent.filter((entry) => entry !== query)),
    [persist, recent],
  )

  const clear = useCallback(() => persist([]), [persist])

  return { recent, remember, forget, clear, reload }
}
