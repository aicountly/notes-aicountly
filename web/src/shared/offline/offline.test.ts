/**
 * The offline layer, against a real IndexedDB implementation.
 *
 * `fake-indexeddb/auto` is imported **first**, before anything that touches the
 * database: `openDatabase()` memoises its connection at module scope, so a
 * later import would already have resolved against the failing stub the global
 * setup installs (which is itself deliberate — it is how the "no local cache"
 * path is exercised elsewhere).
 *
 * These cover the properties that decide whether someone loses work: a replayed
 * queue must not duplicate a note, a background refresh must not clobber an
 * unsent edit, and a conflict must never be resolved by guessing.
 */

import 'fake-indexeddb/auto'

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { idb, STORE_NOTES, STORE_QUEUE } from './db'
import { localNoteStore } from './localNoteStore'
import type { LocalNote } from './localNoteStore'
import { syncQueue } from './syncQueue'
import {
  __resetSyncEngineForTests,
  dismissConflict,
  drainQueue,
  getSyncStatus,
  subscribeToSync,
} from './syncEngine'
import { ApiError } from '../api/client'
import type { Note, NoteSummary } from '../api/types'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      post: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
      get: vi.fn(),
      getWithMeta: vi.fn(),
      upload: vi.fn(),
      request: vi.fn(),
    },
  }
})

const { api } = await import('../api/client')
const mockedApi = api as unknown as Record<string, ReturnType<typeof vi.fn>>

function summary(id: string, overrides: Partial<NoteSummary> = {}): NoteSummary {
  const now = new Date().toISOString()
  return {
    id,
    note_type: 'document',
    title: `Note ${id}`,
    display_title: `Note ${id}`,
    excerpt: '',
    notebook_id: null,
    color: null,
    is_pinned: false,
    is_favourite: false,
    is_archived: false,
    is_locked: false,
    privacy_mode: 'standard',
    version: 1,
    word_count: 0,
    char_count: 0,
    owner_user_id: 'user-a',
    created_at: now,
    updated_at: now,
    deleted_at: null,
    role: 'owner',
    is_shared: false,
    attachment_count: 0,
    has_reminder: false,
    checklist: null,
    tags: [],
    ...overrides,
  }
}

function detail(id: string, overrides: Partial<Note> = {}): Note {
  return {
    ...summary(id),
    document: { type: 'doc', content: [] },
    document_schema_version: 1,
    content_hash: '',
    source: 'web',
    language: null,
    template_key: null,
    capabilities: {
      view: true, comment: true, edit: true,
      share: true, delete: true, restore: true, manage_members: true,
    },
    ...overrides,
  }
}

beforeEach(async () => {
  await idb.clear(STORE_NOTES)
  await idb.clear(STORE_QUEUE)
  __resetSyncEngineForTests()
  vi.clearAllMocks()
})

afterEach(() => {
  __resetSyncEngineForTests()
})

describe('the local note store', () => {
  it('keeps notes and returns them newest first', async () => {
    await localNoteStore.save({ ...summary('a'), updated_at: '2026-01-01T00:00:00Z' })
    await localNoteStore.save({ ...summary('b'), updated_at: '2026-06-01T00:00:00Z' })

    const all = await localNoteStore.all()
    expect(all.map((note) => note.id)).toEqual(['b', 'a'])
  })

  it('does not overwrite an unsent edit with a server copy', async () => {
    await localNoteStore.save({ ...summary('a'), title: 'from the server' })
    await localNoteStore.markDirty('a', { title: 'what I typed on the train' })

    await localNoteStore.mergeFromServer([summary('a', { title: 'from the server' })])

    const kept = await localNoteStore.get('a')
    // The server's copy is older than what the user has typed; taking it would
    // be exactly the silent data loss the design exists to prevent.
    expect(kept?.title).toBe('what I typed on the train')
    expect(kept?.dirty).toBe(1)
  })

  it('accepts the server copy once the local edit has been sent', async () => {
    await localNoteStore.save({ ...summary('a'), title: 'old' })
    await localNoteStore.markDirty('a', { title: 'mine' })
    await localNoteStore.markClean('a')

    await localNoteStore.mergeFromServer([summary('a', { title: 'from the server' })])

    expect((await localNoteStore.get('a'))?.title).toBe('from the server')
  })

  it('hides archived and trashed notes from the offline list', async () => {
    await localNoteStore.save(summary('live'))
    await localNoteStore.save(summary('archived', { is_archived: true }))
    await localNoteStore.save(summary('trashed', { deleted_at: new Date().toISOString() }))

    expect((await localNoteStore.list()).map((n) => n.id)).toEqual(['live'])
  })

  it('clears the cache when a different user signs in on the device', async () => {
    await localNoteStore.ensureOwner('user-a')
    await localNoteStore.save(summary('secret'))

    await localNoteStore.ensureOwner('user-b')

    // One person's notes must not be readable offline by the next person to use
    // the same browser profile.
    expect(await localNoteStore.all()).toEqual([])
  })

  it('leaves the cache alone when the same user returns', async () => {
    await localNoteStore.ensureOwner('user-a')
    await localNoteStore.save(summary('mine'))

    await localNoteStore.ensureOwner('user-a')

    expect((await localNoteStore.all()).map((n) => n.id)).toEqual(['mine'])
  })
})

describe('the sync queue', () => {
  it('gives every operation an id, so a replay cannot duplicate it', async () => {
    const first = await syncQueue.enqueue('note.create', 'note', 'n1', {})
    const second = await syncQueue.enqueue('note.create', 'note', 'n2', {})

    expect(first.operation_id).not.toBe(second.operation_id)
    expect(first.operation_id).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('collapses successive edits to one note into the latest', async () => {
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'draft 1' })
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'draft 2' })
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'draft 3' })

    const pending = await syncQueue.all()
    // Replaying forty autosaves of one paragraph achieves nothing the last one
    // does not, and turns a reconnect into a stampede.
    expect(pending).toHaveLength(1)
    expect(pending[0].payload).toEqual({ title: 'draft 3' })
  })

  it('keeps edits to different notes apart', async () => {
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'a' })
    await syncQueue.enqueue('note.update', 'note', 'n2', { title: 'b' })

    expect(await syncQueue.all()).toHaveLength(2)
  })

  it('does not collapse a create into an update', async () => {
    await syncQueue.enqueue('note.create', 'note', 'n1', { title: 'new' })
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'edited' })

    const pending = await syncQueue.all()
    expect(pending.map((op) => op.operation)).toEqual(['note.create', 'note.update'])
  })
})

describe('draining the queue', () => {
  it('sends queued work and empties the queue', async () => {
    mockedApi.post.mockResolvedValue(detail('n1'))
    await syncQueue.enqueue('note.create', 'note', 'n1', { id: 'n1', title: 'made offline' })

    const status = await drainQueue()

    expect(mockedApi.post).toHaveBeenCalledWith('/notes', { id: 'n1', title: 'made offline' })
    expect(status.state).toBe('idle')
    expect(await syncQueue.count()).toBe(0)
  })

  it('keeps the queue when the connection is still gone', async () => {
    mockedApi.patch.mockRejectedValue(new ApiError('OFFLINE', 'offline', 0))
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'x' })

    const status = await drainQueue()

    expect(status.state).toBe('offline')
    expect(await syncQueue.count()).toBe(1)
  })

  it('parks a conflict for the user instead of picking a winner', async () => {
    const server = detail('n1', { title: 'what someone else saved', version: 4 })
    mockedApi.patch.mockRejectedValue(
      new ApiError('VERSION_CONFLICT', 'changed elsewhere', 409, { note: server, server_version: 4 }),
    )
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'my offline draft' })

    const status = await drainQueue()

    expect(status.state).toBe('conflict')
    expect(status.conflicts).toHaveLength(1)
    // Both sides are preserved: the server's note to show, and the local
    // payload so "keep mine" has something to keep.
    expect(status.conflicts[0].serverNote.title).toBe('what someone else saved')
    expect(status.conflicts[0].localPayload).toEqual({ title: 'my offline draft' })
    // Retrying would only produce the same conflict, so it leaves the queue.
    expect(await syncQueue.count()).toBe(0)
  })

  it('drops an operation the server will never accept', async () => {
    mockedApi.patch.mockRejectedValue(new ApiError('NOT_FOUND', 'gone', 404))
    await syncQueue.enqueue('note.update', 'note', 'deleted-note', { title: 'x' })

    await drainQueue()

    // Keeping it would block every operation behind it forever.
    expect(await syncQueue.count()).toBe(0)
  })

  it('does not stop the whole queue on one bad operation', async () => {
    mockedApi.patch.mockRejectedValueOnce(new ApiError('NOT_FOUND', 'gone', 404))
    mockedApi.patch.mockResolvedValueOnce(detail('n2'))
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'doomed' })
    await syncQueue.enqueue('note.update', 'note', 'n2', { title: 'fine' })

    await drainQueue()

    expect(await syncQueue.count()).toBe(0)
    expect(mockedApi.patch).toHaveBeenCalledTimes(2)
  })

  it('sends a trash as a DELETE, not an archive', async () => {
    mockedApi.delete.mockResolvedValue(undefined)
    await syncQueue.enqueue('note.trash', 'note', 'n1', {})

    await drainQueue()

    expect(mockedApi.delete).toHaveBeenCalledWith('/notes/n1')
    // No ?permanent — a queued delete is always the recoverable kind.
    expect(mockedApi.post).not.toHaveBeenCalled()
  })

  it('tells subscribers when the state changes', async () => {
    const seen: string[] = []
    subscribeToSync((status) => seen.push(status.state))

    mockedApi.post.mockResolvedValue(detail('n1'))
    await syncQueue.enqueue('note.create', 'note', 'n1', {})
    await drainQueue()

    expect(seen).toContain('syncing')
    expect(seen[seen.length - 1]).toBe('idle')
  })

  it('lets a conflict be dismissed once the user has chosen', async () => {
    mockedApi.patch.mockRejectedValue(
      new ApiError('VERSION_CONFLICT', 'changed', 409, { note: detail('n1') }),
    )
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'mine' })
    await drainQueue()

    expect(getSyncStatus().conflicts).toHaveLength(1)
    dismissConflict('n1')
    expect(getSyncStatus().conflicts).toHaveLength(0)
  })

  it('writes the server copy back to the cache after a successful send', async () => {
    mockedApi.patch.mockResolvedValue(detail('n1', { title: 'saved' }))
    await localNoteStore.save({ ...summary('n1'), dirty: 1 } as LocalNote)
    await syncQueue.enqueue('note.update', 'note', 'n1', { title: 'saved' })

    await drainQueue()

    const cached = await localNoteStore.get('n1')
    expect(cached?.title).toBe('saved')
    expect(cached?.dirty).toBe(0)
  })
})
