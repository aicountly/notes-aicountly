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
  attempts: number
  last_error?: string
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
    if (operation === 'note.update') {
      const superseded = pending.find(
        (op) => op.entity_id === entityId && op.operation === 'note.update',
      )
      if (superseded) await idb.delete(STORE_QUEUE, superseded.operation_id)
    }

    const entry: SyncOperation = {
      operation_id: newOperationId(),
      entity_type: entityType,
      entity_id: entityId,
      operation,
      payload,
      client_stamp: new Date().toISOString(),
      created_at: Date.now(),
      attempts: 0,
    }

    await idb.put(STORE_QUEUE, entry)
    return entry
  },

  async all(): Promise<SyncOperation[]> {
    const operations = await idb.byIndex<SyncOperation>(STORE_QUEUE, 'created_at')
    return operations.sort((a, b) => a.created_at - b.created_at)
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
  },
}
