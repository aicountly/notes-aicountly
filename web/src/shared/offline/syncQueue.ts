/**
 * Mutations waiting for a network.
 *
 * Every change made offline becomes one queued operation with a UUID the client
 * generates. That id is what makes replaying the queue safe: the server records
 * applied operation ids, so a flaky reconnect that sends the same batch twice
 * cannot create the note twice.
 */

import { idb, STORE_QUEUE } from './db'

export type SyncOperationKind =
  | 'note.create'
  | 'note.update'
  | 'note.trash'
  | 'note.restore'
  | 'note.archive'
  | 'note.unarchive'
  | 'note.pin'
  | 'note.unpin'
  | 'note.favourite'
  | 'note.unfavourite'
  | 'action.complete'

export interface SyncOperation {
  operation_id: string
  entity_type: 'note' | 'action'
  entity_id: string
  operation: SyncOperationKind
  payload: Record<string, unknown>
  /** When this device made the change. The server keeps it for ordering. */
  client_stamp: string
  created_at: number
  /**
   * Strictly increasing send order.
   *
   * `created_at` cannot do this job: two operations enqueued in the same
   * millisecond tie, and IndexedDB then breaks the tie by primary key — which
   * is a random UUID. That is enough to send an update *before* the create of
   * the note it edits, which 404s and loses the edit. A counter cannot tie.
   */
  seq: number
  attempts: number
  last_error?: string
}

/**
 * The next send-order number.
 *
 * Seeded from what is already queued, so the order survives a reload with a
 * queue still in it, and held in module scope afterwards so concurrent
 * enqueues in one tick cannot read the same value.
 */
let nextSeq: number | null = null

async function takeSeq(): Promise<number> {
  if (nextSeq === null) {
    const existing = await idb.getAll<SyncOperation>(STORE_QUEUE)
    nextSeq = existing.reduce((max, op) => Math.max(max, op.seq ?? 0), 0) + 1
  }

  return nextSeq++
}

function newOperationId(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  // A v4-shaped fallback: the server validates the shape, and these ids only
  // need to be unique per device.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

export const syncQueue = {
  async enqueue(
    operation: SyncOperationKind,
    entityType: SyncOperation['entity_type'],
    entityId: string,
    payload: Record<string, unknown>,
  ): Promise<SyncOperation> {
    const pending = await this.all()

    // Successive edits to one note collapse into the latest: replaying forty
    // autosaves of the same paragraph achieves nothing the last one does not,
    // and it turns a reconnect into a stampede.
    //
    // Collapsed by MERGING the payloads, not by replacing them. A note update
    // is a patch — `PATCH /notes/{id}` with whatever changed — so two queued
    // updates are usually about different fields. Overwriting the first with
    // the second is silent data loss in the plainest form: write a paragraph
    // offline (`{document, version}`), rename the note (`{title}`), and the
    // paragraph never existed. Later fields win, earlier ones survive.
    let inheritedSeq: number | null = null
    let merged = payload
    if (operation === 'note.update') {
      const superseded = pending.find(
        (op) => op.entity_id === entityId && op.operation === 'note.update',
      )
      if (superseded) {
        merged = { ...superseded.payload, ...payload }

        // `version` is the exception to "later wins". It is not a value the
        // user edited; it says which server revision this device's content is
        // based on, and that is the revision the FIRST unsent edit diverged
        // from. Keeping the older number is what makes the server refuse the
        // merged patch — correctly — if the note moved on in the meantime.
        if (typeof superseded.payload.version === 'number') {
          merged.version = superseded.payload.version
        }

        // Keep the original position. Moving the merged edit to the back of the
        // queue would let a later operation on the same note overtake it.
        inheritedSeq = superseded.seq
        await idb.delete(STORE_QUEUE, superseded.operation_id)
      }
    }

    const entry: SyncOperation = {
      operation_id: newOperationId(),
      entity_type: entityType,
      entity_id: entityId,
      operation,
      payload: merged,
      client_stamp: new Date().toISOString(),
      created_at: Date.now(),
      seq: inheritedSeq ?? (await takeSeq()),
      attempts: 0,
    }

    await idb.put(STORE_QUEUE, entry)
    return entry
  },

  /** Oldest first, by send order rather than by clock. */
  async all(): Promise<SyncOperation[]> {
    const operations = await idb.getAll<SyncOperation>(STORE_QUEUE)
    return operations.sort((a, b) => (a.seq ?? 0) - (b.seq ?? 0))
  },

  async remove(operationId: string): Promise<void> {
    await idb.delete(STORE_QUEUE, operationId)
  },

  async recordFailure(operation: SyncOperation, error: string): Promise<void> {
    await idb.put(STORE_QUEUE, {
      ...operation,
      attempts: operation.attempts + 1,
      last_error: error,
    })
  },

  async count(): Promise<number> {
    return idb.count(STORE_QUEUE)
  },

  async clear(): Promise<void> {
    await idb.clear(STORE_QUEUE)
    nextSeq = null
  },
}
