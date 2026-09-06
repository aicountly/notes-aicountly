/**
 * Search.
 *
 * The whole thing is driven from one text field: typing filters, the arrow keys
 * move, Enter opens and Escape closes, and the pointer is never required. That
 * is the combobox pattern — focus stays in the input and the highlighted row is
 * named by `aria-activedescendant`, because moving DOM focus into a list would
 * take the caret out of the box the user is still typing into.
 *
 * Notes come from the ranked full-text index; Notebooks and Tags come from
 * `/search/suggest`, which matches names. They are separate tabs rather than
 * one merged list because they answer different questions — "which note said
 * this" and "where do I keep that" — and merging them buries the notes.
 */

import { Fragment, useEffect, useId, useMemo, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useNavigate } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { Badge, Button, Dialog, EmptyState, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import type { ApiError } from '../../../shared/api/client'
import { useFeature } from '../../../app/AppConfigProvider'
import { useCreateNote } from '../../notes/hooks/useNotes'
import type { NoteType } from '../../../shared/api/types'
import { Highlight } from './Highlight'
import type { HighlightMarkers } from './Highlight'
import { SearchFilters } from './SearchFilters'
import {
  NO_FILTERS,
  SEARCH_MAX_PAGE,
  SEARCH_PAGE_SIZE,
  activeFilterCount,
  applyRefinements,
  useDebounced,
  useNoteSearch,
  useNotebookOptions,
  useRecentSearches,
  useSearchSuggestions,
  useTagOptions,
} from '../hooks/useSearch'
import type { NoteResult, SearchFilterState, SearchMode } from '../hooks/useSearch'
import '../search.css'

const TYPE_ICON: Record<NoteType, IconName> = {
  document: 'note',
  checklist: 'checklist',
  voice: 'mic',
  meeting: 'meeting',
  drawing: 'draw',
  canvas: 'draw',
  scan: 'scan',
}

const DATE = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' })

function formatDate(iso: string | null): string {
  if (iso === null) return ''
  const value = Date.parse(iso)
  return Number.isNaN(value) ? '' : DATE.format(value)
}

type ResultTab = 'all' | 'notes' | 'notebooks' | 'tags'

const TABS: ReadonlyArray<{ value: ResultTab; label: string }> = [
  { value: 'all', label: 'All' },
  { value: 'notes', label: 'Notes' },
  { value: 'notebooks', label: 'Notebooks' },
  { value: 'tags', label: 'Tags' },
]

type Row =
  | { key: string; kind: 'note'; group: string; result: NoteResult }
  | { key: string; kind: 'notebook'; group: string; id: string; name: string }
  | { key: string; kind: 'tag'; group: string; id: string; name: string; slug: string }

/**
 * What the server says it did, in the user's words.
 *
 * `mode` is only ever reported by the semantic endpoint, and only a `vector`
 * answer is the thing the toggle promised. Anything else is said out loud.
 */
const MODE_NOTICE: Record<SearchMode, string | null> = {
  vector: null,
  json_fallback:
    'Semantic results, compared without a vector index — slower and less precise on a large library.',
  keyword_fallback: 'Keyword results — semantic search could not answer this query.',
  keyword: 'Keyword results — semantic search is not available on this deployment.',
}

export interface SearchDialogProps {
  open: boolean
  onClose: () => void
}

export function SearchDialog({ open, onClose }: SearchDialogProps) {
  const navigate = useNavigate()
  const create = useCreateNote()
  const semanticAvailable = useFeature('semantic_search')

  const [query, setQuery] = useState('')
  const [tab, setTab] = useState<ResultTab>('all')
  const [filters, setFilters] = useState<SearchFilterState>(NO_FILTERS)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [semantic, setSemantic] = useState(false)
  const [limit, setLimit] = useState(SEARCH_PAGE_SIZE)
  const [selected, setSelected] = useState(0)
  const [createError, setCreateError] = useState<string | null>(null)

  const inputId = useId()
  const listId = useId()
  const filtersId = useId()
  const optionRefs = useRef<Array<HTMLLIElement | null>>([])

  const term = useDebounced(query).trim()
  const { recent, remember, forget, clear } = useRecentSearches()

  // Every dialog opens on the same blank slate. Reopening onto a stale query,
  // or onto filters set for a different search and now hidden behind a closed
  // panel, is how a search box starts lying about what it is showing.
  useEffect(() => {
    if (!open) return
    setQuery('')
    setTab('all')
    setFilters(NO_FILTERS)
    setFiltersOpen(false)
    setSemantic(false)
    setLimit(SEARCH_PAGE_SIZE)
    setSelected(0)
    setCreateError(null)
  }, [open])

  const search = useNoteSearch({ query: term, filters, semantic, limit, enabled: open })
  const suggestions = useSearchSuggestions(term, open)
  const notebookOptions = useNotebookOptions(open && filtersOpen)
  const tagOptions = useTagOptions(open && filtersOpen)

  const notes = useMemo(
    () => applyRefinements(search.data?.notes ?? [], filters),
    [search.data, filters],
  )
  const notebooks = suggestions.data?.notebooks ?? []
  const tags = suggestions.data?.tags ?? []

  const rows = useMemo<Row[]>(() => {
    const noteRows: Row[] = notes.map((result) => ({
      key: `note:${result.noteId}`,
      kind: 'note',
      group: 'Notes',
      result,
    }))
    const notebookRows: Row[] = notebooks.map((notebook) => ({
      key: `notebook:${notebook.id}`,
      kind: 'notebook',
      group: 'Notebooks',
      id: notebook.id,
      name: notebook.name,
    }))
    const tagRows: Row[] = tags.map((tag) => ({
      key: `tag:${tag.id}`,
      kind: 'tag',
      group: 'Tags',
      id: tag.id,
      name: tag.name,
      slug: tag.slug,
    }))

    if (tab === 'notes') return noteRows
    if (tab === 'notebooks') return notebookRows
    if (tab === 'tags') return tagRows
    return [...noteRows, ...notebookRows, ...tagRows]
  }, [notes, notebooks, tags, tab])

  // A new query, a new tab or a new filter is a new list; keeping the old
  // highlight would open whatever happens to sit at that index now.
  useEffect(() => {
    setSelected(0)
    setLimit(SEARCH_PAGE_SIZE)
  }, [term, tab, semantic, filters])

  const index = rows.length === 0 ? -1 : Math.min(selected, rows.length - 1)

  useEffect(() => {
    if (index >= 0) optionRefs.current[index]?.scrollIntoView({ block: 'nearest' })
  }, [index, rows.length])

  const move = (delta: number) => {
    if (rows.length === 0) return
    setSelected((current) => {
      const from = Math.min(current, rows.length - 1)
      return (from + delta + rows.length) % rows.length
    })
  }

  const openRow = (row: Row) => {
    remember(term)
    onClose()

    if (row.kind === 'note') navigate(`/notes/${row.result.noteId}`)
    else if (row.kind === 'notebook') navigate(`/notebooks/${row.id}`)
    else navigate(`/tags/${row.slug}`)
  }

  const onKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      move(1)
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      move(-1)
    } else if (event.key === 'Enter') {
      const row = index >= 0 ? rows[index] : undefined
      if (row) {
        event.preventDefault()
        openRow(row)
      }
    }
  }

  const createFromQuery = async () => {
    setCreateError(null)
    try {
      const note = await create.mutateAsync({ title: term, note_type: 'document', source: 'search' })
      onClose()
      navigate(`/notes/${note.id}`)
    } catch (error) {
      setCreateError(error instanceof Error ? error.message : 'That note could not be created.')
    }
  }

  const activeError: ApiError | null =
    tab === 'notebooks' || tab === 'tags'
      ? (suggestions.error ?? null)
      : (search.error ?? suggestions.error ?? null)

  const busy = search.isFetching || suggestions.isFetching
  const firstLoad = busy && search.data === undefined && suggestions.data === undefined
  const counts: Record<ResultTab, number> = {
    all: notes.length + notebooks.length + tags.length,
    notes: notes.length,
    notebooks: notebooks.length,
    tags: tags.length,
  }
  const filterCount = activeFilterCount(filters)
  const notice = semantic && search.data?.mode ? MODE_NOTICE[search.data.mode] : null

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Search"
      width={760}
      footer={
        <p className="search__keys">
          <kbd>↑</kbd>
          <kbd>↓</kbd> to move <kbd>↵</kbd> to open <kbd>Esc</kbd> to close
        </p>
      }
    >
      <div className="search">
        <div className="search__field">
          <label className="sr-only" htmlFor={inputId}>
            Search notes, notebooks and tags
          </label>
          <Icon name="search" size={17} />
          <input
            id={inputId}
            data-autofocus
            className="search__input"
            type="text"
            role="combobox"
            autoComplete="off"
            placeholder="Search notes, notebooks and tags…"
            value={query}
            aria-expanded={rows.length > 0}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={index >= 0 ? `${listId}-option-${index}` : undefined}
            onChange={(event) => setQuery(event.target.value)}
            onKeyDown={onKeyDown}
          />
          {query !== '' ? (
            <Button
              icon="close"
              iconOnly
              variant="ghost"
              size="sm"
              aria-label="Clear the search"
              onClick={() => setQuery('')}
            />
          ) : null}
          <Button
            icon="settings"
            variant={filtersOpen ? 'secondary' : 'ghost'}
            size="sm"
            aria-expanded={filtersOpen}
            aria-controls={filtersId}
            // Semantic search takes a query and nothing else, so the panel would
            // set filters the request cannot carry.
            disabled={semantic}
            title={semantic ? 'Filters apply to keyword search only' : undefined}
            onClick={() => setFiltersOpen((current) => !current)}
          >
            {filterCount > 0 ? `Filters (${filterCount})` : 'Filters'}
          </Button>
        </div>

        {semanticAvailable ? (
          <label className="search-check search__semantic">
            <input
              type="checkbox"
              checked={semantic}
              onChange={(event) => {
                setSemantic(event.target.checked)
                if (event.target.checked) setFiltersOpen(false)
              }}
            />
            <span>Search by meaning</span>
          </label>
        ) : null}

        {filtersOpen && !semantic ? (
          <SearchFilters
            id={filtersId}
            value={filters}
            onChange={setFilters}
            notebooks={notebookOptions.data ?? []}
            tags={tagOptions.data ?? []}
            loading={notebookOptions.isPending || tagOptions.isPending}
            lookupError={notebookOptions.error ?? tagOptions.error ?? null}
          />
        ) : null}

        {notice ? (
          <p className="search__notice" role="status">
            <Icon name="info" size={15} />
            {notice}
          </p>
        ) : null}

        <div className="search__tabs" role="group" aria-label="Result type">
          {TABS.map((entry) => (
            <button
              key={entry.value}
              type="button"
              aria-pressed={tab === entry.value}
              className={`search__tab ${tab === entry.value ? 'search__tab--active' : ''}`}
              onClick={() => setTab(entry.value)}
            >
              {entry.label}
              {term !== '' ? <span className="search__tab-count">{counts[entry.value]}</span> : null}
            </button>
          ))}
        </div>

        <div className="search__body">
          {term === '' ? (
            <RecentSearches
              recent={recent}
              onPick={setQuery}
              onForget={forget}
              onClear={clear}
            />
          ) : firstLoad ? (
            <ResultsSkeleton />
          ) : activeError ? (
            <EmptyState
              icon={activeError.isOffline ? 'cloud-off' : 'alert'}
              title={activeError.isOffline ? 'Search needs a connection' : 'That search failed'}
              description={activeError.message}
              action={
                <Button
                  variant="secondary"
                  icon="refresh"
                  onClick={() => {
                    void search.refetch()
                    void suggestions.refetch()
                  }}
                >
                  Try again
                </Button>
              }
            />
          ) : rows.length === 0 ? (
            <EmptyState
              icon="search"
              title={`Nothing matches “${term}”`}
              description={
                filterCount > 0
                  ? 'No note matches with these filters applied.'
                  : 'Try fewer words, or start a note with this title.'
              }
              action={
                filterCount > 0 ? (
                  <Button variant="secondary" icon="undo" onClick={() => setFilters(NO_FILTERS)}>
                    Clear filters
                  </Button>
                ) : (
                  <Button
                    variant="primary"
                    icon="plus"
                    loading={create.isPending}
                    onClick={() => void createFromQuery()}
                  >
                    New note “{term}”
                  </Button>
                )
              }
            />
          ) : (
            <>
              <ul className="search__results" role="listbox" id={listId} aria-label="Search results">
                {rows.map((row, position) => {
                  const showGroup =
                    tab === 'all' && (position === 0 || rows[position - 1].group !== row.group)

                  return (
                    <Fragment key={row.key}>
                      {showGroup ? (
                        <li role="presentation" className="search__group">
                          {row.group}
                        </li>
                      ) : null}
                      <li
                        ref={(element) => {
                          optionRefs.current[position] = element
                        }}
                        id={`${listId}-option-${position}`}
                        role="option"
                        aria-selected={position === index}
                        className={`search__row ${position === index ? 'search__row--active' : ''}`}
                        onMouseMove={() => setSelected(position)}
                        onClick={() => openRow(row)}
                      >
                        <ResultRow row={row} markers={search.data?.markers} />
                      </li>
                    </Fragment>
                  )
                })}
              </ul>

              {search.data?.hasMore && (tab === 'all' || tab === 'notes') ? (
                limit < SEARCH_MAX_PAGE ? (
                  <div className="search__more">
                    <Button
                      variant="secondary"
                      size="sm"
                      icon="chevron-down"
                      loading={search.isFetching}
                      onClick={() => setLimit(SEARCH_MAX_PAGE)}
                    >
                      Show more results
                    </Button>
                  </div>
                ) : (
                  <p className="search__more-note">
                    More notes match than can be listed. Add a word, or narrow it with a filter.
                  </p>
                )
              ) : null}
            </>
          )}

          {createError ? (
            <p className="search__error" role="alert">
              {createError}
            </p>
          ) : null}
        </div>

        <LiveStatus>
          {term === ''
            ? ''
            : busy
              ? 'Searching'
              : `${rows.length} ${rows.length === 1 ? 'result' : 'results'} for ${term}`}
        </LiveStatus>
      </div>
    </Dialog>
  )
}

// ---------------------------------------------------------------------------

function ResultRow({ row, markers }: { row: Row; markers: HighlightMarkers | undefined }) {
  if (row.kind === 'notebook') {
    return (
      <>
        <span className="search__row-icon">
          <Icon name="notebook" size={17} />
        </span>
        <span className="search__row-body">
          <span className="search__row-title">{row.name}</span>
          <span className="search__row-meta">Notebook</span>
        </span>
      </>
    )
  }

  if (row.kind === 'tag') {
    return (
      <>
        <span className="search__row-icon">
          <Icon name="tag" size={17} />
        </span>
        <span className="search__row-body">
          <span className="search__row-title">{row.name}</span>
          <span className="search__row-meta">#{row.slug}</span>
        </span>
      </>
    )
  }

  const { result } = row
  const updated = formatDate(result.updatedAt)

  return (
    <>
      <span className="search__row-icon">
        <Icon name={TYPE_ICON[result.noteType]} size={17} />
      </span>
      <span className="search__row-body">
        <span className="search__row-title">
          {result.displayTitle}
          {result.isPinned ? <Badge tone="neutral">Pinned</Badge> : null}
          {result.isShared ? <Badge tone="primary">Shared</Badge> : null}
        </span>
        {result.snippet !== '' ? (
          <Highlight text={result.snippet} markers={markers} className="search__row-snippet" />
        ) : null}
        <span className="search__row-meta">
          {updated !== '' ? <span>{updated}</span> : null}
          {result.tags.slice(0, 3).map((tag) => (
            <span key={tag.id} className="search__row-tag">
              #{tag.slug}
            </span>
          ))}
          {result.attachmentCount > 0 ? (
            <span className="search__row-attachments">
              <Icon name="attach" size={13} />
              {result.attachmentCount}
            </span>
          ) : null}
        </span>
      </span>
    </>
  )
}

function RecentSearches({
  recent,
  onPick,
  onForget,
  onClear,
}: {
  recent: string[]
  onPick: (query: string) => void
  onForget: (query: string) => void
  onClear: () => void
}) {
  if (recent.length === 0) {
    return (
      <EmptyState
        icon="search"
        title="Search your notes"
        description="Type a word from a note, a notebook name, or a tag. Results appear as you type."
      />
    )
  }

  return (
    <section className="search__recent" aria-label="Recent searches">
      <header className="search__recent-header">
        <h3 className="search__recent-title">Recent searches</h3>
        <Button variant="ghost" size="sm" icon="trash" onClick={onClear}>
          Clear
        </Button>
      </header>
      <ul className="search__recent-list">
        {recent.map((entry) => (
          <li key={entry} className="search__recent-item">
            <button type="button" className="search__recent-button" onClick={() => onPick(entry)}>
              <Icon name="history" size={15} />
              <span>{entry}</span>
            </button>
            <Button
              icon="close"
              iconOnly
              variant="ghost"
              size="sm"
              aria-label={`Remove “${entry}” from recent searches`}
              onClick={() => onForget(entry)}
            />
          </li>
        ))}
      </ul>
    </section>
  )
}

/** Shaped like the rows it replaces, so nothing jumps when they arrive. */
function ResultsSkeleton() {
  return (
    <div className="search__skeleton" aria-hidden>
      {[0, 1, 2, 3].map((row) => (
        <div key={row} className="search__skeleton-row">
          <Skeleton width={20} height={20} radius={6} />
          <div className="search__skeleton-lines">
            <Skeleton width="42%" height={13} />
            <Skeleton width="88%" height={11} />
          </div>
        </div>
      ))}
    </div>
  )
}
