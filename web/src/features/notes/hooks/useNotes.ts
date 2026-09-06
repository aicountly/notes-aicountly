/**
 * Reading and writing notes.
 *
 * Two behaviours worth knowing about, because they are what make the app feel
 * immediate rather than merely fast:
 *
 *   - **Creating a note does not wait for the server.** The note is written to
 *     the cache with a client-generated UUID and the editor opens on it. The
 *     POST carries that same id, so the server stores the note the user is
 *     already typing into.
 *   - **A list read falls back to the device.** If the request fails while
 *     offline, the locally cached notes are returned instead of an error, and
 *     the shell shows the offline indicator.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import { localNoteStore } from '../../../shared/offline/localNoteStore'
import { syncQueue } from '../../../shared/offline/syncQueue'
import { refreshPendingCount } from '../../../shared/offline/syncEngine'
import { EMPTY_DOCUMENT } from '../../../shared/api/types'
import type {
  Note,
  NoteDocument,
  NoteSummary,
  NoteType,
  SidebarCounts,
} from '../../../shared/api/types'

export interface NoteListFilters {
  scope?: 'active' | 'archive' | 'trash' | 'all'
  notebook_id?: string
  tags?: string[]
  note_type?: NoteType
  color?: string
  pinned?: boolean
  favourite?: boolean
  shared_with_me?: boolean
  has_reminder?: boolean
  has_attachment?: boolean
  q?: string
  sort?: string
  limit?: number
}

function toQuery(filters: NoteListFilters): Record<string, string | number | boolean | undefined> {
  return {
    scope: filters.scope,
    notebook_id: filters.notebook_id,
    tags: filters.tags?.length ? filters.tags.join(',') : undefined,
    note_type: filters.note_type,
    color: filters.color,
    pinned: filters.pinned,
    favourite: filters.favourite,
    shared_with_me: filters.shared_with_me,
    has_reminder: filters.has_reminder,
    has_attachment: filters.has_attachment,
    q: filters.q,
    sort: filters.sort ?? 'pinned_first',
    limit: filters.limit ?? 40,
  }
}

export interface NoteListResult {
  notes: NoteSummary[]
  hasMore: boolean
  nextCursor: string | null
  /** True when these rows came from the device because the server was unreachable. */
  fromCache: boolean
}

export function useNoteList(filters: NoteListFilters = {}): UseQueryResult<NoteListResult, ApiError> {
  return useQuery<NoteListResult, ApiError>({
    queryKey: queryKeys.notes.list(filters as Record<string, unknown>),
    queryFn: async () => {
      try {
        const { data, meta } = await api.getWithMeta<NoteSummary[]>('/notes', {
          query: toQuery(filters),
        })

        // Only the plain, unfiltered list is worth caching for offline: a
        // filtered slice would overwrite the cache with a subset and make the
        // offline library look emptier than it is.
        if (!filters.q && !filters.notebook_id && (filters.scope ?? 'active') === 'active') {
          void localNoteStore.mergeFromServer(data)
        }

        return {
          notes: data,
          hasMore: Boolean(meta.has_more),
          nextCursor: (meta.next_cursor as string | null) ?? null,
          fromCache: false,
        }
      } catch (error) {
        if (error instanceof ApiError && error.isOffline) {
          const cached = await localNoteStore.list()
          return { notes: cached, hasMore: false, nextCursor: null, fromCache: true }
        }
        throw error
      }
    },
  })
}

export function useNote(noteId: string | undefined): UseQueryResult<Note, ApiError> {
  return useQuery<Note, ApiError>({
    queryKey: queryKeys.notes.detail(noteId ?? ''),
    enabled: Boolean(noteId),
    queryFn: async () => {
      try {
        const note = await api.get<Note>(`/notes/${noteId}`)
        await localNoteStore.save({ ...note, dirty: 0 })
        return note
      } catch (error) {
        if (error instanceof ApiError && error.isOffline) {
          const cached = await localNoteStore.get(noteId ?? '')
          // Only a note whose document has been cached can be opened offline;
          // a summary-only row would open an editor with no content in it.
          if (cached?.document) return cached as Note
        }
        throw error
      }
    },
  })
}

export function useSidebarCounts(): UseQueryResult<SidebarCounts, ApiError> {
  return useQuery<SidebarCounts, ApiError>({
    queryKey: queryKeys.notes.counts,
    queryFn: () => api.get<SidebarCounts>('/notes/counts'),
    staleTime: 60_000,
  })
}

export interface CreateNoteInput {
  id?: string
  title?: string | null
  document?: NoteDocument
  note_type?: NoteType
  notebook_id?: string | null
  tags?: string[]
  color?: string | null
  source?: string
  template_key?: string
}

/** A client-generated UUID, so a note exists before the network does. */
export function newNoteId(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

function optimisticNote(input: CreateNoteInput, id: string): NoteSummary {
  const now = new Date().toISOString()
  return {
    id,
    note_type: input.note_type ?? 'document',
    title: input.title ?? null,
    display_title: input.title?.trim() || 'Untitled note',
    excerpt: '',
    notebook_id: input.notebook_id ?? null,
    color: (input.color as NoteSummary['color']) ?? null,
    is_pinned: false,
    is_favourite: false,
    is_archived: false,
    is_locked: false,
    privacy_mode: 'standard',
    version: 1,
    word_count: 0,
    char_count: 0,
    owner_user_id: '',
    created_at: now,
    updated_at: now,
    deleted_at: null,
    role: 'owner',
    is_shared: false,
    attachment_count: 0,
    has_reminder: false,
    checklist: null,
    tags: [],
  }
}

export function useCreateNote() {
  const client = useQueryClient()

  return useMutation<Note, ApiError, CreateNoteInput>({
    mutationFn: async (input) => {
      const id = input.id ?? newNoteId()
      const payload: CreateNoteInput = { ...input, id, document: input.document ?? EMPTY_DOCUMENT }

      try {
        return await api.post<Note>('/notes', payload)
      } catch (error) {
        if (error instanceof ApiError && error.isOffline) {
          // Queue it and hand back a local note so the editor opens anyway.
          // The queued create carries the same id, so applying it later does
          // not produce a second note.
          await syncQueue.enqueue('note.create', 'note', id, payload as Record<string, unknown>)
          await refreshPendingCount()

          const local: Note = {
            ...optimisticNote(payload, id),
            document: payload.document ?? EMPTY_DOCUMENT,
            document_schema_version: 1,
            content_hash: '',
            source: payload.source ?? 'web',
            language: null,
            template_key: payload.template_key ?? null,
            capabilities: {
              view: true, comment: true, edit: true,
              share: false, delete: true, restore: true, manage_members: false,
            },
          }
          await localNoteStore.save({ ...local, dirty: 1 })
          return local
        }
        throw error
      }
    },
    onSuccess: (note) => {
      client.setQueryData(queryKeys.notes.detail(note.id), note)
      void client.invalidateQueries({ queryKey: queryKeys.notes.all })
      void client.invalidateQueries({ queryKey: queryKeys.notes.counts })
    },
  })
}

export interface UpdateNoteInput {
  id: string
  title?: string | null
  document?: NoteDocument
  version?: number
  notebook_id?: string | null
  color?: string | null
  tags?: string[]
  note_type?: NoteType
  is_pinned?: boolean
  is_favourite?: boolean
  is_archived?: boolean
  revision_reason?: string
}

export function useUpdateNote() {
  const client = useQueryClient()

  return useMutation<Note, ApiError, UpdateNoteInput>({
    mutationFn: async ({ id, ...patch }) => {
      try {
        return await api.patch<Note>(`/notes/${id}`, patch)
      } catch (error) {
        if (error instanceof ApiError && error.isOffline) {
          await syncQueue.enqueue('note.update', 'note', id, patch as Record<string, unknown>)
          await refreshPendingCount()

          const local = await localNoteStore.markDirty(id, patch as never)
          if (local?.document) return local as Note
        }
        throw error
      }
    },
    onSuccess: (note) => {
      client.setQueryData(queryKeys.notes.detail(note.id), note)
      void client.invalidateQueries({ queryKey: queryKeys.notes.all })
    },
  })
}

type FlagAction =
  | 'pin' | 'unpin'
  | 'favourite' | 'unfavourite'
  | 'archive' | 'unarchive'
  | 'restore'

/**
 * The one-click state changes, applied optimistically.
 *
 * Pinning a note from a list of forty must not wait for a round trip and must
 * not re-render the whole list; the cache is patched in place and rolled back
 * if the server disagrees.
 */
export function useNoteFlag() {
  const client = useQueryClient()

  return useMutation<Note, ApiError, { id: string; action: FlagAction }>({
    mutationFn: async ({ id, action }) => {
      try {
        return await api.post<Note>(`/notes/${id}/${action}`)
      } catch (error) {
        if (error instanceof ApiError && error.isOffline && action !== 'restore') {
          await syncQueue.enqueue(`note.${action}` as never, 'note', id, {})
          await refreshPendingCount()
          const local = await localNoteStore.get(id)
          if (local) return local as Note
        }
        throw error
      }
    },
    onMutate: async ({ id, action }) => {
      await client.cancelQueries({ queryKey: queryKeys.notes.detail(id) })
      const previous = client.getQueryData<Note>(queryKeys.notes.detail(id))

      const patch: Partial<NoteSummary> = {
        pin: { is_pinned: true },
        unpin: { is_pinned: false },
        favourite: { is_favourite: true },
        unfavourite: { is_favourite: false },
        archive: { is_archived: true },
        unarchive: { is_archived: false },
        restore: { deleted_at: null },
      }[action]

      if (previous) {
        client.setQueryData<Note>(queryKeys.notes.detail(id), { ...previous, ...patch })
      }
      patchListCaches(client, id, patch)

      return { previous }
    },
    onError: (_error, { id }, context) => {
      const previous = (context as { previous?: Note } | undefined)?.previous
      if (previous) client.setQueryData(queryKeys.notes.detail(id), previous)
      void client.invalidateQueries({ queryKey: queryKeys.notes.all })
    },
    onSettled: () => {
      void client.invalidateQueries({ queryKey: queryKeys.notes.all })
      void client.invalidateQueries({ queryKey: queryKeys.notes.counts })
    },
  })
}

export function useTrashNote() {
  const client = useQueryClient()

  return useMutation<void, ApiError, { id: string; permanent?: boolean }>({
    mutationFn: async ({ id, permanent }) => {
      try {
        await api.delete(`/notes/${id}`, { query: { permanent: permanent ? 'true' : undefined } })
      } catch (error) {
        // A permanent delete is never queued: taken offline and applied ten
        // minutes later, it is a destruction the user cannot take back.
        if (error instanceof ApiError && error.isOffline && !permanent) {
          await syncQueue.enqueue('note.trash', 'note', id, {})
          await refreshPendingCount()
          await localNoteStore.markDirty(id, { deleted_at: new Date().toISOString() })
          return
        }
        throw error
      }
      if (permanent) await localNoteStore.remove(id)
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.notes.all })
      void client.invalidateQueries({ queryKey: queryKeys.notes.counts })
    },
  })
}

export function useDuplicateNote() {
  const client = useQueryClient()

  return useMutation<Note, ApiError, string>({
    mutationFn: (id) => api.post<Note>(`/notes/${id}/duplicate`),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.notes.all })
    },
  })
}

/** Patch every cached list that happens to contain this note. */
function patchListCaches(
  client: ReturnType<typeof useQueryClient>,
  noteId: string,
  patch: Partial<NoteSummary>,
): void {
  const caches = client.getQueriesData<NoteListResult>({ queryKey: ['notes', 'list'] })

  for (const [key, value] of caches) {
    if (!value?.notes) continue
    client.setQueryData<NoteListResult>(key, {
      ...value,
      notes: value.notes.map((note) => (note.id === noteId ? { ...note, ...patch } : note)),
    })
  }
}
