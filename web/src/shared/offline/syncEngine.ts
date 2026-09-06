/**
 * Draining the offline queue.
 *
 * The engine is deliberately conservative about one thing: it never resolves a
 * conflict on its own. When the server says a note moved on while this device
 * was offline, the queued edit is parked and the user is told — because the
 * alternative, picking a winner silently, is how someone loses an afternoon's
 * writing and never finds out.
 */

import { ApiError, api } from '../api/client'
import { localNoteStore } from './localNoteStore'
import { syncQueue } from './syncQueue'
import type { SyncOperation } from './syncQueue'
import type { Note } from '../api/types'

export type SyncState = 'idle' | 'syncing' | 'offline' | 'error' | 'conflict'

export interface SyncStatus {
  state: SyncState
  pending: number
  lastSyncedAt: string | null
  /** Notes whose queued edit could not be applied because the server is ahead. */
  conflicts: SyncConflict[]
  error: string | null
}

export interface SyncConflict {
  noteId: string
  title: string
  serverNote: Note
  localPayload: Record<string, unknown>
}

/** Give up on an operation the server keeps rejecting, rather than looping forever. */
const MAX_ATTEMPTS = 5

type Listener = (status: SyncStatus) => void

let status: SyncStatus = {
  state: 'idle',
  pending: 0,
  lastSyncedAt: null,
  conflicts: [],
  error: null,
}

const listeners = new Set<Listener>()
let draining = false

function publish(patch: Partial<SyncStatus>): void {
  status = { ...status, ...patch }
  for (const listener of listeners) listener(status)
}

export function subscribeToSync(listener: Listener): () => void {
  listeners.add(listener)
  listener(status)
  return () => listeners.delete(listener)
}

export function getSyncStatus(): SyncStatus {
  return status
}

export function dismissConflict(noteId: string): void {
  publish({
    conflicts: status.conflicts.filter((conflict) => conflict.noteId !== noteId),
    state: status.conflicts.length <= 1 ? 'idle' : status.state,
  })
}

/** Endpoint for one queued operation. */
function endpointFor(operation: SyncOperation): { method: 'POST' | 'PATCH' | 'DELETE'; path: string } {
  const id = operation.entity_id

  switch (operation.operation) {
    case 'note.create':
      return { method: 'POST', path: '/notes' }
    case 'note.update':
      return { method: 'PATCH', path: `/notes/${id}` }
    case 'note.trash':
      // Soft delete. DELETE without ?permanent is what moves a note to Trash;
      // the permanent form is never queued, because a destructive action taken
      // offline and applied minutes later is not one the user can take back.
      return { method: 'DELETE', path: `/notes/${id}` }
    case 'note.restore':
      return { method: 'POST', path: `/notes/${id}/restore` }
    case 'note.archive':
      return { method: 'POST', path: `/notes/${id}/archive` }
    case 'note.unarchive':
      return { method: 'POST', path: `/notes/${id}/unarchive` }
    case 'note.pin':
      return { method: 'POST', path: `/notes/${id}/pin` }
    case 'note.unpin':
      return { method: 'POST', path: `/notes/${id}/unpin` }
    case 'note.favourite':
      return { method: 'POST', path: `/notes/${id}/favourite` }
    case 'note.unfavourite':
      return { method: 'POST', path: `/notes/${id}/unfavourite` }
    case 'action.complete':
      return { method: 'PATCH', path: `/actions/${id}` }
  }
}

async function applyOperation(operation: SyncOperation): Promise<void> {
  const { method, path } = endpointFor(operation)

  if (method === 'DELETE') {
    await api.delete(path)
    await syncQueue.remove(operation.operation_id)
    await localNoteStore.markClean(operation.entity_id)
    return
  }

  const note =
    method === 'POST'
      ? await api.post<Note>(path, operation.payload)
      : await api.patch<Note>(path, operation.payload)

  if (operation.entity_type === 'note' && note?.id) {
    await localNoteStore.save({ ...note, dirty: 0 })
  }
  await syncQueue.remove(operation.operation_id)
}

/**
 * Send everything queued, oldest first.
 *
 * Sequential rather than parallel: two operations on the same note must not
 * race, and the queue is short by construction because updates coalesce.
 */
export async function drainQueue(): Promise<SyncStatus> {
  if (draining) return status
  draining = true

  const pending = await syncQueue.all()
  if (pending.length === 0) {
    draining = false
    publish({ pending: 0, state: status.conflicts.length > 0 ? 'conflict' : 'idle', error: null })
    return status
  }

  publish({ state: 'syncing', pending: pending.length, error: null })

  const conflicts: SyncConflict[] = [...status.conflicts]

  for (const operation of pending) {
    try {
      await applyOperation(operation)
    } catch (error) {
      if (error instanceof ApiError && error.isOffline) {
        // Still offline. Stop; the queue survives for the next attempt.
        draining = false
        publish({ state: 'offline', pending: await syncQueue.count() })
        return status
      }

      if (error instanceof ApiError && error.isConflict) {
        const serverNote = error.details.note as Note | undefined
        if (serverNote) {
          conflicts.push({
            noteId: operation.entity_id,
            title: serverNote.display_title,
            serverNote,
            localPayload: operation.payload,
          })
        }
        // The operation leaves the queue: retrying it would only produce the
        // same conflict. The user's copy is preserved in `localPayload` and
        // the note stays flagged dirty until they choose.
        await syncQueue.remove(operation.operation_id)
        continue
      }

      if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
        // The server refuses this operation and always will — a deleted note,
        // revoked access, a validation failure. Keeping it would block every
        // operation behind it forever.
        await syncQueue.remove(operation.operation_id)
        await localNoteStore.markClean(operation.entity_id)
        continue
      }

      await syncQueue.recordFailure(operation, (error as Error).message)
      if (operation.attempts + 1 >= MAX_ATTEMPTS) {
        await syncQueue.remove(operation.operation_id)
      }

      draining = false
      publish({
        state: 'error',
        pending: await syncQueue.count(),
        error: 'Some changes could not be saved. They are still on this device.',
      })
      return status
    }
  }

  draining = false
  publish({
    state: conflicts.length > 0 ? 'conflict' : 'idle',
    pending: await syncQueue.count(),
    lastSyncedAt: new Date().toISOString(),
    conflicts,
    error: null,
  })

  return status
}

/** Refresh the pending count without sending anything. */
export async function refreshPendingCount(): Promise<void> {
  publish({ pending: await syncQueue.count() })
}

let started = false

/**
 * Drain on reconnect, on tab focus, and on a slow timer.
 *
 * The timer is the backstop: `online` does not fire when a captive portal stops
 * intercepting traffic, and a laptop waking from sleep often reports itself as
 * having been online the whole time.
 */
export function startSyncEngine(): () => void {
  if (started) return () => undefined
  started = true

  const attempt = () => {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      publish({ state: 'offline' })
      return
    }
    void drainQueue()
  }

  const onOnline = () => attempt()
  const onOffline = () => publish({ state: 'offline' })
  const onVisibility = () => {
    if (document.visibilityState === 'visible') attempt()
  }

  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
  document.addEventListener('visibilitychange', onVisibility)
  const timer = window.setInterval(attempt, 60_000)

  void refreshPendingCount()
  attempt()

  return () => {
    started = false
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
    document.removeEventListener('visibilitychange', onVisibility)
    window.clearInterval(timer)
  }
}

/** Test seam: forget listeners and state between cases. */
export function __resetSyncEngineForTests(): void {
  listeners.clear()
  draining = false
  started = false
  status = { state: 'idle', pending: 0, lastSyncedAt: null, conflicts: [], error: null }
}
