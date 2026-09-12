/**
 * Saving / Saved / Offline, in the header.
 *
 * A notes app must never ask the user to press Save, which means it owes them
 * a truthful, glanceable answer to "did that stick?". This is deliberately
 * quiet: no toast per save — a toast every four seconds while someone types is
 * an app arguing with its user — and it says nothing at all in the steady
 * state once a save has settled.
 */

import { useEffect, useState } from 'react'
import { Icon } from '../../../shared/ui/Icon'
import { getSyncStatus, subscribeToSync, drainQueue } from '../../../shared/offline/syncEngine'
import type { SyncStatus } from '../../../shared/offline/syncEngine'

/** Set by the editor while a save is in flight. */
let editorSaving = false
const editorListeners = new Set<(saving: boolean) => void>()

export function setEditorSaving(saving: boolean): void {
  editorSaving = saving
  for (const listener of editorListeners) listener(saving)
}

export function SyncStatusIndicator() {
  const [status, setStatus] = useState<SyncStatus>(getSyncStatus)
  const [saving, setSaving] = useState(editorSaving)
  const [showSaved, setShowSaved] = useState(false)

  useEffect(() => subscribeToSync(setStatus), [])

  useEffect(() => {
    const listener = (value: boolean) => {
      setSaving(value)
      // "Saved" is worth a moment of reassurance after a save, then it goes
      // away. A permanent "Saved" badge is decoration, not information.
      if (!value) {
        setShowSaved(true)
        window.setTimeout(() => setShowSaved(false), 2000)
      }
    }
    editorListeners.add(listener)
    return () => {
      editorListeners.delete(listener)
    }
  }, [])

  if (status.state === 'offline' || status.pending > 0) {
    return (
      <button
        type="button"
        className="sync-status sync-status--offline"
        onClick={() => void drainQueue()}
        title={
          status.state === 'offline'
            ? 'You are offline. Changes are saved on this device and will sync when you reconnect.'
            : 'Some changes are waiting to sync. Click to retry now.'
        }
      >
        <Icon name={status.state === 'offline' ? 'cloud-off' : 'refresh'} size={15} />
        <span className="sync-status__label">
          {status.state === 'offline' ? 'Offline' : `${status.pending} to sync`}
        </span>
      </button>
    )
  }

  if (status.state === 'conflict') {
    return (
      <span className="sync-status sync-status--conflict" role="status">
        <Icon name="alert" size={15} />
        <span className="sync-status__label">
          {status.conflicts.length} conflict{status.conflicts.length === 1 ? '' : 's'}
        </span>
      </span>
    )
  }

  if (status.state === 'error') {
    return (
      <button type="button" className="sync-status sync-status--error" onClick={() => void drainQueue()} title={status.error ?? ''}>
        <Icon name="alert" size={15} />
        <span className="sync-status__label">Retry</span>
      </button>
    )
  }

  if (saving) {
    return (
      <span className="sync-status" role="status" aria-live="polite">
        <Icon name="refresh" size={15} className="sync-status__spin" />
        <span className="sync-status__label">Saving…</span>
      </span>
    )
  }

  if (showSaved) {
    return (
      <span className="sync-status sync-status--saved" role="status" aria-live="polite">
        <Icon name="cloud-check" size={15} />
        <span className="sync-status__label">Saved</span>
      </span>
    )
  }

  return null
}
