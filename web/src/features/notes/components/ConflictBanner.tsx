/**
 * "This note changed elsewhere while you were offline."
 *
 * The sync engine never picks a winner — see `shared/offline/syncEngine` — so
 * something has to ask, and this is it. Both answers are real writes:
 *
 *   Keep mine — the edit that could not be sent becomes a new note, and the
 *               original goes back to the server's copy. Nothing is discarded.
 *   Use theirs — the local edit is dropped and the device takes the server's
 *               note. Said plainly, because it is the destructive one.
 *
 * The banner lives in the shell rather than in the editor: a conflict is about
 * a note the user may not currently be looking at.
 */

import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'

import { Button, LiveStatus } from '../../../shared/ui/primitives'
import { Icon } from '../../../shared/ui/Icon'
import { ApiError } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import { localNoteStore } from '../../../shared/offline/localNoteStore'
import { dismissConflict, subscribeToSync } from '../../../shared/offline/syncEngine'
import type { SyncConflict } from '../../../shared/offline/syncEngine'
import { useCreateNote } from '../hooks/useNotes'
import type { NoteDocument } from '../../../shared/api/types'
import '../notes.css'

function isNoteDocument(value: unknown): value is NoteDocument {
  return typeof value === 'object' && value !== null && (value as { type?: unknown }).type === 'doc'
}

function describeError(error: unknown): string {
  if (error instanceof ApiError) return error.message
  return 'That did not work. Please try again.'
}

export function ConflictBanner() {
  const [conflicts, setConflicts] = useState<SyncConflict[]>([])

  useEffect(() => subscribeToSync((status) => setConflicts(status.conflicts)), [])

  if (conflicts.length === 0) return null

  return (
    <section className="conflict-banner" aria-label="Notes that changed in two places">
      <LiveStatus>
        {conflicts.length} note{conflicts.length === 1 ? '' : 's'} changed elsewhere while you were offline.
      </LiveStatus>

      {conflicts.map((conflict) => (
        <ConflictRow key={conflict.noteId} conflict={conflict} />
      ))}
    </section>
  )
}

function ConflictRow({ conflict }: { conflict: SyncConflict }) {
  const client = useQueryClient()
  const navigate = useNavigate()
  const create = useCreateNote()

  const [busy, setBusy] = useState<'mine' | 'theirs' | null>(null)
  const [error, setError] = useState<string | null>(null)

  /** Put the server's note back on this device and forget the local edit. */
  const takeServerCopy = async () => {
    await localNoteStore.save({ ...conflict.serverNote, dirty: 0 })
    client.setQueryData(queryKeys.notes.detail(conflict.noteId), conflict.serverNote)
    await client.invalidateQueries({ queryKey: queryKeys.notes.all })
  }

  const keepMine = async () => {
    setBusy('mine')
    setError(null)
    try {
      const payload = conflict.localPayload
      const title = typeof payload.title === 'string' ? payload.title : conflict.serverNote.title

      const copy = await create.mutateAsync({
        title: `${title ?? conflict.serverNote.display_title} (your version)`,
        document: isNoteDocument(payload.document) ? payload.document : conflict.serverNote.document,
        note_type: conflict.serverNote.note_type,
        notebook_id: conflict.serverNote.notebook_id,
      })

      await takeServerCopy()
      dismissConflict(conflict.noteId)
      navigate(`/notes/${copy.id}`)
    } catch (reason) {
      setError(describeError(reason))
      setBusy(null)
    }
  }

  const useTheirs = async () => {
    setBusy('theirs')
    setError(null)
    try {
      await takeServerCopy()
      dismissConflict(conflict.noteId)
    } catch (reason) {
      setError(describeError(reason))
      setBusy(null)
    }
  }

  return (
    <div className="conflict-banner__row">
      <span className="conflict-banner__icon" aria-hidden>
        <Icon name="alert" size={18} />
      </span>

      <div className="conflict-banner__text">
        <p className="conflict-banner__title">
          “{conflict.title}” changed elsewhere while you were offline.
        </p>
        <p className="conflict-banner__detail">
          Your edit was not sent. Keep it as a separate note, or drop it and take the version that is
          on the server.
        </p>

        <div className="conflict-banner__actions">
          <Button
            variant="primary"
            size="sm"
            icon="copy"
            loading={busy === 'mine'}
            disabled={busy !== null}
            onClick={() => void keepMine()}
          >
            Keep mine (save as a copy)
          </Button>
          <Button
            size="sm"
            icon="cloud-check"
            loading={busy === 'theirs'}
            disabled={busy !== null}
            onClick={() => void useTheirs()}
          >
            Use theirs
          </Button>
        </div>

        {error ? (
          <p className="conflict-banner__error" role="alert">
            {error}
          </p>
        ) : null}
      </div>
    </div>
  )
}
