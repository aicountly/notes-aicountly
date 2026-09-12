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

/**
 * How many times a change is retried before it is set aside.
 *
 * Set aside, not thrown away. Deleting it was worse than the problem it
 * solved: the status line says the change is "still on this device", and after
 * the fifth failure that was a lie — the operation was gone and the note was
 * left dirty for ever, so nothing would ever retry or surface it. A set-aside
 * operation stays in the queue, is stepped over so it cannot block the ones
 * behind it, and is reported.
 */
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

/** One entry in `POST /sync/push`'s per-operation answer. */
interface PushResult {
  operation_id: string
  entity_type: string
  entity_id: string | null
  operation: string
  status: 'applied' | 'conflict' | 'rejected'
  result: Record<string, unknown>
  replayed: boolean
}

/**
 * Send one queued operation through the idempotency ledger.
 *
 * `POST /sync/push` and not the REST endpoint the same change would use
 * online, and that difference is the whole point. The ledger records the
 * `operation_id` against its outcome, so a reconnect that sends an operation
 * whose answer was lost gets the *first* answer back (`replayed: true`) rather
 * than applying it twice. Without it, a create that timed out after the server
 * had already made the note was safe only because the note id comes from the
 * device — and a replayed update was not safe at all: it re-sent a version the
 * server had already moved past and came back as a conflict the user had to
 * resolve, over a save that had in fact succeeded.
 *
 * One operation per call rather than a batch. The queue is short by
 * construction (updates coalesce), the operations are ordered and must not
 * race, and a per-call answer keeps the failure handling in the drain loop
 * where it already lives. `MAX_BATCH` on the server is the ceiling if this
 * ever needs to send more.
 */
async function applyOperation(operation: SyncOperation): Promise<PushResult> {
  const results = await api.post<PushResult[]>('/sync/push', {
    operations: [
      {
        operation_id: operation.operation_id,
        entity_type: operation.entity_type,
        entity_id: operation.entity_id,
        operation: operation.operation,
        payload: operation.payload,
        client_stamp: operation.client_stamp,
      },
    ],
  })

  const result = results?.[0]
  if (!result) {
    // The server answered the push but said nothing about the operation in it.
    // Treated as a transport failure so the entry stays queued: dropping it
    // would throw away a change on the strength of a malformed reply.
    throw new ApiError('SYNC_NO_RESULT', 'The server did not say what happened to that change.', 0)
  }

  if (result.status === 'applied') {
    const note = result.result?.note as Note | undefined
    if (operation.entity_type === 'note' && note?.id) {
      await localNoteStore.save({ ...note, dirty: 0 })
    } else if (operation.entity_type === 'note') {
      // A trash has no note to write back, but the row must stop claiming it
      // has unsent work.
      await localNoteStore.markClean(operation.entity_id)
    }
  }

  return result
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
  /** Operations past MAX_ATTEMPTS: stepped over, not sent, never dropped. */
  let setAside = 0

  for (const operation of pending) {
    if (operation.attempts >= MAX_ATTEMPTS) {
      setAside += 1
      continue
    }

    try {
      const result = await applyOperation(operation)

      if (result.status === 'conflict') {
        const serverNote = result.result?.note as Note | undefined
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

      if (result.status === 'rejected') {
        // The server refuses this operation and always will — a deleted note,
        // revoked access, a validation failure. Keeping it would block every
        // operation behind it forever.
        await syncQueue.remove(operation.operation_id)
        await localNoteStore.markClean(operation.entity_id)
        continue
      }

      await syncQueue.remove(operation.operation_id)
    } catch (error) {
      if (error instanceof ApiError && error.isOffline) {
        // Still offline. Stop; the queue survives for the next attempt.
        //
        // `conflicts` goes out with it. It is seeded from the published status
        // and added to as the loop runs, so leaving it off this exit published
        // a status whose conflict list was older than the one just built — and
        // since the conflicting operation has already left the queue, nothing
        // would ever rediscover it. The banner stayed empty and the note
        // stayed dirty for ever.
        draining = false
        publish({ state: 'offline', pending: await syncQueue.count(), conflicts })
        return status
      }

      // A 4xx that reaches here is the push itself being refused — a malformed
      // batch, a rate limit — not one operation's verdict, which arrives as a
      // `rejected` result above.
      await syncQueue.recordFailure(operation, (error as Error).message)

      draining = false
      publish({
        state: 'error',
        pending: await syncQueue.count(),
        conflicts,
        error:
          operation.attempts + 1 >= MAX_ATTEMPTS
            ? 'A change could not be saved after several attempts. It is still on this device — Settings shows what is waiting.'
            : 'Some changes could not be saved. They are still on this device.',
      })
      return status
    }
  }

  draining = false
  publish({
    state: conflicts.length > 0 ? 'conflict' : setAside > 0 ? 'error' : 'idle',
    pending: await syncQueue.count(),
    lastSyncedAt: new Date().toISOString(),
    conflicts,
    error:
      setAside > 0
        ? `${setAside === 1 ? 'A change' : `${setAside} changes`} could not be saved after several attempts. ` +
          'They are still on this device — Settings shows what is waiting.'
        : null,
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
