/**
 * The list column and the editor beside it.
 *
 * One component serves every list route — My Notes, a notebook, a tag, a smart
 * folder, Shared, Archive and Trash — because they differ only in the filter
 * they send and the words at the top. Seven near-identical pages would be seven
 * places to fix the next paging bug.
 *
 * Two things are worth knowing about how the list is fetched:
 *
 *   - **Pinned notes are a separate query.** The API can sort pinned-first, but
 *     that ordering has no keyset cursor, so paging it silently repeats rows.
 *     Asking for the pinned notes and the rest separately keeps both correct
 *     and puts pinned in its own section, which is what pinning is for.
 *   - **Pages are cached under the same key shape as `useNoteList`.** That is
 *     what lets `useNoteFlag` patch a pin optimistically on page three instead
 *     of waiting for a refetch.
 */

import { useId, useState } from 'react'
import { useMatch, useNavigate, useParams } from 'react-router-dom'
import { useQueries, useQuery } from '@tanstack/react-query'

import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { Button, EmptyState, Skeleton } from '../../../shared/ui/primitives'
import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import { localNoteStore } from '../../../shared/offline/localNoteStore'
import { useAppConfig } from '../../../app/AppConfigProvider'
import { NotesGrid } from '../components/NotesGrid'
import { NotesList } from '../components/NotesList'
import { NoteEditorPane } from '../components/NoteEditorPane'
import type { NoteSummary, Notebook, SmartFolder } from '../../../shared/api/types'
import '../notes.css'

export type NotesScope = 'active' | 'notebook' | 'tag' | 'smart-folder' | 'shared' | 'archive' | 'trash'

/** Where the open note sits in each scope's URL. */
const NOTE_ROUTE: Record<NotesScope, string> = {
  active: '/notes/:noteId',
  notebook: '/notebooks/:notebookId/notes/:noteId',
  tag: '/tags/:tagSlug/notes/:noteId',
  'smart-folder': '/smart-folders/:folderId/notes/:noteId',
  shared: '/shared/:noteId',
  archive: '/archive/:noteId',
  trash: '/trash/:noteId',
}

type ViewMode = 'grid' | 'list' | 'compact'

const VIEW_MODES: readonly ViewMode[] = ['grid', 'list', 'compact']
const VIEW_ICON: Record<ViewMode, 'grid' | 'rows' | 'list'> = { grid: 'grid', list: 'rows', compact: 'list' }
const VIEW_LABEL: Record<ViewMode, string> = { grid: 'Cards', list: 'List', compact: 'Compact' }

const SORTS = [
  { value: 'updated_desc', label: 'Recently updated' },
  { value: 'created_desc', label: 'Recently created' },
  { value: 'title_asc', label: 'Title A–Z' },
  { value: 'updated_asc', label: 'Oldest first' },
] as const

type SortValue = (typeof SORTS)[number]['value']

const PAGE_SIZE = 30
/** The server's own ceiling on `limit`. */
const MAX_PAGE = 100

const VIEW_KEY = 'notes:view'
const SORT_KEY = 'notes:sort'

/**
 * Storage that is allowed to be unavailable.
 *
 * Private-mode browsers throw on both reads and writes, and a layout
 * preference is never worth failing a render over.
 */
function readPreference<T extends string>(key: string, allowed: readonly T[], fallback: T): T {
  try {
    const stored = window.localStorage.getItem(key) as T | null
    return stored !== null && allowed.includes(stored) ? stored : fallback
  } catch {
    return fallback
  }
}

function writePreference(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value)
  } catch {
    /* No preference is worth an exception. */
  }
}

// ---------------------------------------------------------------------------
// Paging
// ---------------------------------------------------------------------------

type ListQuery = Record<string, string | number | boolean | undefined>

interface FeedPage {
  notes: NoteSummary[]
  hasMore: boolean
  nextCursor: string | null
  /** True when these rows came off the device because the server was unreachable. */
  fromCache: boolean
}

async function fetchPage(
  path: string,
  query: ListQuery,
  cursor: string | null,
  offlineFallback: boolean,
): Promise<FeedPage> {
  try {
    const { data, meta } = await api.getWithMeta<NoteSummary[]>(path, {
      query: { ...query, cursor: cursor ?? undefined },
    })

    return {
      notes: data,
      hasMore: Boolean(meta.has_more),
      nextCursor: (meta.next_cursor as string | null) ?? null,
      fromCache: false,
    }
  } catch (error) {
    // Only the plain first page has a device copy to fall back to; an archive
    // filter answered from the offline cache would be a different list.
    if (offlineFallback && error instanceof ApiError && error.isOffline) {
      return { notes: await localNoteStore.list(), hasMore: false, nextCursor: null, fromCache: true }
    }
    throw error
  }
}

interface FeedOptions {
  path: string
  query: ListQuery
  /** Cursor paging is only sound for the API's own keyset order. */
  cursorPaged: boolean
  offlineFallback: boolean
}

function useNotesFeed({ path, query, cursorPaged, offlineFallback }: FeedOptions) {
  const signature = JSON.stringify([path, query])
  const [paging, setPaging] = useState({ signature, cursors: [null] as (string | null)[], limit: PAGE_SIZE })

  // Changing the filter resets the pages during the same render, so a stale
  // page two never flashes under the new list.
  if (paging.signature !== signature) {
    setPaging({ signature, cursors: [null], limit: PAGE_SIZE })
  }

  const results = useQueries({
    queries: paging.cursors.map((cursor) => ({
      queryKey: queryKeys.notes.list({ ...query, path, limit: paging.limit, cursor }),
      queryFn: () => fetchPage(path, { ...query, limit: paging.limit }, cursor, offlineFallback && cursor === null),
      // Growing the page size changes the key. Keeping the previous rows on
      // screen turns "load more" into an append rather than a full reload.
      placeholderData: (previous: FeedPage | undefined) => previous,
    })),
  })

  // A note edited between two page requests can appear on both.
  const seen = new Set<string>()
  const notes: NoteSummary[] = []
  for (const result of results) {
    for (const note of result.data?.notes ?? []) {
      if (seen.has(note.id)) continue
      seen.add(note.id)
      notes.push(note)
    }
  }

  const last = results[results.length - 1]
  const failure = results.find((result) => result.isError)?.error

  const hasMore = Boolean(last?.data?.hasMore)
  const canLoadMore = hasMore && (cursorPaged ? (last?.data?.nextCursor ?? null) !== null : paging.limit < MAX_PAGE)

  return {
    notes,
    hasMore,
    canLoadMore,
    fromCache: Boolean(results[0]?.data?.fromCache),
    isPending: results[0]?.isPending ?? true,
    isLoadingMore:
      (results.length > 1 && Boolean(last?.isPending)) || Boolean(last?.isPlaceholderData),
    error: failure instanceof ApiError ? failure : null,
    refetch: () => {
      for (const result of results) void result.refetch()
    },
    loadMore: () => {
      if (!canLoadMore) return
      setPaging((current) =>
        cursorPaged
          ? { ...current, cursors: [...current.cursors, last?.data?.nextCursor ?? null] }
          : { ...current, limit: Math.min(MAX_PAGE, current.limit + PAGE_SIZE) },
      )
    },
  }
}

// ---------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------

export function NotesPage({ scope }: { scope: NotesScope }) {
  const navigate = useNavigate()
  const config = useAppConfig()
  const params = useParams<{ notebookId?: string; tagSlug?: string; folderId?: string }>()
  const noteMatch = useMatch(NOTE_ROUTE[scope])
  const sortId = useId()

  const [view, setView] = useState<ViewMode>(() => readPreference(VIEW_KEY, VIEW_MODES, 'grid'))
  const [sort, setSort] = useState<SortValue>(() =>
    readPreference(
      SORT_KEY,
      SORTS.map((option) => option.value),
      'updated_desc',
    ),
  )

  const noteId = noteMatch?.params.noteId ?? null
  const notebookId = params.notebookId ?? null
  const tagSlug = params.tagSlug ?? null
  const folderId = params.folderId ?? null

  const listPath = {
    active: '/notes',
    notebook: `/notebooks/${notebookId ?? ''}`,
    tag: `/tags/${tagSlug ?? ''}`,
    'smart-folder': `/smart-folders/${folderId ?? ''}`,
    shared: '/shared',
    archive: '/archive',
    trash: '/trash',
  }[scope]

  const basePath = scope === 'notebook' || scope === 'tag' || scope === 'smart-folder' ? `${listPath}/notes` : listPath

  // Notebook and smart-folder names come from lists the sidebar has usually
  // already fetched, so this is a cache read far more often than a request.
  const notebooks = useQuery<Notebook[], ApiError>({
    queryKey: queryKeys.notebooks,
    queryFn: () => api.get<Notebook[]>('/notebooks'),
    enabled: scope === 'notebook',
    staleTime: 60_000,
  })
  const folders = useQuery<SmartFolder[], ApiError>({
    queryKey: queryKeys.smartFolders,
    queryFn: () => api.get<SmartFolder[]>('/smart-folders'),
    enabled: scope === 'smart-folder',
    staleTime: 60_000,
  })

  const title = {
    active: 'My Notes',
    notebook: findNotebook(notebooks.data ?? [], notebookId)?.name ?? 'Notebook',
    tag: `#${tagSlug ?? ''}`,
    'smart-folder': folders.data?.find((folder) => folder.id === folderId)?.name ?? 'Smart folder',
    shared: 'Shared with me',
    archive: 'Archive',
    trash: 'Trash',
  }[scope]

  const listScope = scope === 'archive' ? 'archive' : scope === 'trash' ? 'trash' : 'active'
  const isSmartFolder = scope === 'smart-folder'
  // Pinning only means something where the list is the user's working set.
  const splitPinned = scope === 'active' || scope === 'notebook' || scope === 'tag' || scope === 'shared'

  const baseQuery: ListQuery = {
    scope: listScope,
    notebook_id: scope === 'notebook' ? (notebookId ?? undefined) : undefined,
    tags: scope === 'tag' ? (tagSlug ?? undefined) : undefined,
    shared_with_me: scope === 'shared' ? true : undefined,
    sort,
  }

  const feedPath = isSmartFolder ? `/smart-folders/${folderId ?? ''}/notes` : '/notes'

  const feed = useNotesFeed({
    path: feedPath,
    query: splitPinned ? { ...baseQuery, pinned: false } : baseQuery,
    cursorPaged: sort === 'updated_desc',
    offlineFallback: scope === 'active',
  })

  const pinnedQuery: ListQuery = { ...baseQuery, pinned: true, sort: 'updated_desc', limit: 20 }
  const pinned = useQuery<FeedPage, ApiError>({
    queryKey: queryKeys.notes.list({ ...pinnedQuery, path: '/notes' }),
    queryFn: () => fetchPage('/notes', pinnedQuery, null, false),
    enabled: splitPinned,
  })

  const pinnedNotes = splitPinned ? (pinned.data?.notes ?? []) : []
  const total = pinnedNotes.length + feed.notes.length
  const hrefFor = (note: NoteSummary) => `${basePath}/${note.id}`
  const onRemoved = (removedId: string) => {
    if (removedId === noteId) navigate(listPath)
  }

  const renderNotes = (notes: NoteSummary[], label: string) =>
    view === 'grid' ? (
      <NotesGrid notes={notes} hrefFor={hrefFor} label={label} selectedId={noteId} onRemoved={onRemoved} />
    ) : (
      <NotesList
        notes={notes}
        hrefFor={hrefFor}
        label={label}
        selectedId={noteId}
        compact={view === 'compact'}
        onRemoved={onRemoved}
      />
    )

  return (
    <div className="notes-layout">
      <section className="notes-column" aria-label={`${title}, note list`}>
        <header className="notes-column__header">
          <h1 className="notes-column__title">{title}</h1>
          <span className="notes-column__count">
            {feed.isPending ? '' : `${total}${feed.hasMore ? '+' : ''} ${total === 1 ? 'note' : 'notes'}`}
          </span>
          {scope === 'active' || scope === 'notebook' ? (
            <Button
              icon="plus"
              iconOnly
              size="sm"
              variant="ghost"
              aria-label="New note"
              onClick={() => navigate(`${basePath}/new`)}
            />
          ) : null}
        </header>

        <div className="notes-toolbar">
          <label className="sr-only" htmlFor={sortId}>
            Sort notes
          </label>
          <select
            id={sortId}
            className="notes-toolbar__sort"
            value={sort}
            onChange={(event) => {
              const next = event.target.value as SortValue
              setSort(next)
              writePreference(SORT_KEY, next)
            }}
          >
            {SORTS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>

          <div className="view-toggle" role="group" aria-label="Note layout">
            {VIEW_MODES.map((mode) => (
              <button
                key={mode}
                type="button"
                className="view-toggle__button"
                aria-pressed={view === mode}
                aria-label={VIEW_LABEL[mode]}
                title={VIEW_LABEL[mode]}
                onClick={() => {
                  setView(mode)
                  writePreference(VIEW_KEY, mode)
                }}
              >
                <Icon name={VIEW_ICON[mode]} size={15} />
              </button>
            ))}
          </div>
        </div>

        <div className="notes-column__scroll">
          {scope === 'trash' ? (
            <p className="notes-notice notes-notice--warning">
              <Icon name="trash" size={14} />
              Notes in Trash are deleted for good {config.limits.trash_retention_days} days after you
              put them here.
            </p>
          ) : null}

          {feed.fromCache ? (
            <p className="notes-notice">
              <Icon name="cloud-off" size={14} />
              You are offline. These are the notes saved on this device.
            </p>
          ) : null}

          {feed.isPending ? (
            <NotesSkeleton view={view} />
          ) : feed.error ? (
            <div className="notes-error" role="alert">
              <Icon name={feed.error.isOffline ? 'cloud-off' : 'alert'} size={24} />
              <p className="notes-error__message">{feed.error.message}</p>
              <Button icon="refresh" onClick={feed.refetch}>
                Try again
              </Button>
            </div>
          ) : total === 0 ? (
            <EmptyState
              icon={EMPTY[scope].icon}
              title={EMPTY[scope].title}
              description={EMPTY[scope].description}
              action={
                scope === 'active' || scope === 'notebook' ? (
                  <Button variant="primary" icon="plus" onClick={() => navigate(`${basePath}/new`)}>
                    New note
                  </Button>
                ) : (
                  <Button icon="note" onClick={() => navigate('/notes')}>
                    Go to My Notes
                  </Button>
                )
              }
            />
          ) : (
            <>
              {pinnedNotes.length > 0 ? (
                <>
                  <h2 className="notes-column__section-label">Pinned</h2>
                  {renderNotes(pinnedNotes, 'Pinned notes')}
                  {feed.notes.length > 0 ? <h2 className="notes-column__section-label">Others</h2> : null}
                </>
              ) : null}

              {feed.notes.length > 0 ? renderNotes(feed.notes, `${title} notes`) : null}

              {feed.canLoadMore ? (
                <div className="notes-column__more">
                  <Button icon="chevron-down" loading={feed.isLoadingMore} onClick={feed.loadMore}>
                    Load more
                  </Button>
                </div>
              ) : feed.hasMore ? (
                <p className="notes-notice">
                  <Icon name="info" size={14} />
                  Showing the first {feed.notes.length} notes in this order. Sort by recently updated,
                  or search, to reach the rest.
                </p>
              ) : null}
            </>
          )}
        </div>
      </section>

      <NoteEditorPane
        noteId={noteId}
        listPath={listPath}
        listLabel={title}
        basePath={basePath}
        notebookId={scope === 'notebook' ? notebookId : null}
      />
    </div>
  )
}

// ---------------------------------------------------------------------------

function findNotebook(notebooks: Notebook[], id: string | null): Notebook | undefined {
  if (id === null) return undefined
  for (const notebook of notebooks) {
    if (notebook.id === id) return notebook
    const child = findNotebook(notebook.children ?? [], id)
    if (child) return child
  }
  return undefined
}

const EMPTY: Record<NotesScope, { icon: IconName; title: string; description: string }> = {
  active: { icon: 'note', title: 'No notes yet', description: 'Everything you capture lands here.' },
  notebook: { icon: 'notebook', title: 'This notebook is empty', description: 'Notes you file here will show up in this list.' },
  tag: { icon: 'tag', title: 'Nothing with this tag', description: 'Tag a note and it will appear here.' },
  'smart-folder': { icon: 'sparkle-folder', title: 'Nothing matches yet', description: 'This folder fills itself as notes start matching its rules.' },
  shared: { icon: 'shared', title: 'Nothing shared with you', description: 'Notes other people share with you collect here.' },
  archive: { icon: 'archive', title: 'Nothing archived', description: 'Archiving gets a note out of the way without deleting it.' },
  trash: { icon: 'trash', title: 'Trash is empty', description: 'Deleted notes wait here before they are removed for good.' },
}

/** Shaped like the view it is standing in for, so nothing jumps on arrival. */
function NotesSkeleton({ view }: { view: ViewMode }) {
  const rows = Array.from({ length: 6 }, (_, index) => index)

  if (view === 'grid') {
    return (
      <div className="notes-grid" aria-busy>
        {rows.map((row) => (
          <div key={row} className="note-skeleton">
            <Skeleton width="70%" height={15} />
            <Skeleton width="100%" height={12} />
            <Skeleton width="88%" height={12} />
            <Skeleton width="40%" height={10} />
          </div>
        ))}
      </div>
    )
  }

  return (
    <div className="notes-list" aria-busy>
      {rows.map((row) => (
        <div key={row} className="note-skeleton">
          <Skeleton width="55%" height={13} />
          {view === 'list' ? <Skeleton width="80%" height={11} /> : null}
        </div>
      ))}
    </div>
  )
}
