/**
 * What this app is keeping on the device, and how to get rid of it.
 *
 * Two numbers matter and they answer different questions. The **counts** come
 * from the app's own stores and are exact: how many notes are readable offline,
 * and how many changes have not reached the server yet. The **size** comes from
 * `navigator.storage.estimate()`, which measures the whole origin rather than
 * this database — so it is reported as "this site" and never as "your notes",
 * because claiming a precision the browser does not offer is worse than a
 * rounder truth.
 *
 * Clearing is destructive in one specific way the UI has to say out loud: a
 * queued change that has not synced is gone with it. That is why
 * {@link readOfflineUsage} reports `pending` separately — the confirmation is
 * different when there is unsent work.
 */

import { useCallback, useEffect, useState } from 'react'

import { STORE_META, STORE_NOTES, STORE_QUEUE, idb } from '../../shared/offline/db'
import { localNoteStore } from '../../shared/offline/localNoteStore'
import { syncQueue } from '../../shared/offline/syncQueue'
import { refreshPendingCount } from '../../shared/offline/syncEngine'

export interface OfflineUsage {
  /** Notes cached on this device. */
  notes: number
  /** Of those, how many carry the full document and can be opened offline. */
  readable: number
  /** Changes waiting for a network. Clearing the cache discards these. */
  pending: number
  /** Bytes this origin is using, or null where the browser will not say. */
  bytes: number | null
  /** True when IndexedDB is unavailable — a private window, or a locked-down profile. */
  unavailable: boolean
}

const EMPTY: OfflineUsage = { notes: 0, readable: 0, pending: 0, bytes: null, unavailable: false }

export async function readOfflineUsage(): Promise<OfflineUsage> {
  const notes = await localNoteStore.all()
  const pending = await syncQueue.count()

  let bytes: number | null = null
  try {
    const estimate = await navigator.storage?.estimate?.()
    bytes = typeof estimate?.usage === 'number' ? estimate.usage : null
  } catch {
    // Firefox in a private window rejects rather than resolving. No number is
    // a fine answer; a wrong one is not.
    bytes = null
  }

  return {
    notes: notes.length,
    readable: notes.filter((note) => note.document !== undefined).length,
    pending,
    bytes,
    unavailable: typeof indexedDB === 'undefined',
  }
}

/**
 * Empty every store this app owns.
 *
 * The queue goes with the notes on purpose. Leaving it would keep operations
 * pointing at notes that are no longer on the device, which would sync a set of
 * edits the user just asked to be rid of.
 */
export async function clearOfflineData(): Promise<void> {
  await syncQueue.clear()
  await idb.clear(STORE_NOTES)
  await idb.clear(STORE_QUEUE)
  await idb.clear(STORE_META)
  // The shell's sync indicator reads this counter; without the refresh it would
  // keep showing changes that no longer exist.
  await refreshPendingCount()
}

/** Rounded the way a person reads a size, not the way a disk reports one. */
export function formatBytes(bytes: number | null): string {
  if (bytes === null) return 'not reported by this browser'
  if (bytes < 1024) return `${bytes} bytes`

  const units = ['KB', 'MB', 'GB']
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit += 1
  }

  return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`
}

export interface OfflineUsageState {
  /** null until the first read finishes. */
  usage: OfflineUsage | null
  /** A re-read asked for by the user is in flight. */
  refreshing: boolean
  refresh: () => void
}

/**
 * The usage figures, and a way to read them again after clearing.
 *
 * `refreshing` exists because the figures usually come back identical: without
 * it, "Recheck" is a button that answers by changing nothing, which is
 * indistinguishable from a button wired to nothing. The stale figures stay on
 * screen while it runs — blanking them would make a re-read look like data
 * loss.
 */
export function useOfflineUsage(): OfflineUsageState {
  const [usage, setUsage] = useState<OfflineUsage | null>(null)
  const [nonce, setNonce] = useState(0)
  const [refreshing, setRefreshing] = useState(false)

  useEffect(() => {
    let cancelled = false

    const settle = (result: OfflineUsage) => {
      // Only the newest read may answer: two quick Rechecks must not let the
      // slower one overwrite the fresher figures, or clear the busy state
      // while the newer read is still running.
      if (cancelled) return
      setUsage(result)
      setRefreshing(false)
    }

    void readOfflineUsage()
      .then(settle)
      .catch(() => {
        // A store that cannot be read is a store with nothing in it as far as
        // this screen is concerned; it must not take the page down.
        settle({ ...EMPTY, unavailable: true })
      })

    return () => {
      cancelled = true
    }
  }, [nonce])

  const refresh = useCallback(() => {
    setRefreshing(true)
    setNonce((current) => current + 1)
  }, [])

  return { usage, refreshing, refresh }
}
