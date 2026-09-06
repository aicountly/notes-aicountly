/**
 * Autosave.
 *
 * A notes app must never ask anyone to press Save, which puts the whole burden
 * on this hook getting four things right at once:
 *
 *   1. **Quiet.** A save every keystroke is a save every keystroke; a save only
 *      on blur loses an afternoon to a crashed tab. So: 1.2s after typing
 *      stops, and — because someone drafting a long note may not stop for
 *      minutes — a hard flush at most every 8s of continuous typing.
 *   2. **Complete.** Leaving the tab, hiding it, or closing it flushes first.
 *   3. **Honest about conflicts.** Every content save carries the version the
 *      editor last saw. A 409 is surfaced and the loop *stops*: retrying with
 *      the same stale version cannot succeed, and retrying with the server's
 *      version would overwrite whatever the other person wrote. The pending
 *      draft is kept so the user can still choose.
 *   4. **Silent in the UI.** It reports through `setEditorSaving`, which the
 *      header reads. Never a toast — a toast every four seconds while someone
 *      is writing is an app arguing with its user.
 */

import { useCallback, useEffect, useRef, useState } from 'react'

import { ApiError } from '../../shared/api/client'
import { setEditorSaving } from '../notes/components/SyncStatusIndicator'
import type { Note, NoteDocument } from '../../shared/api/types'

export const AUTOSAVE_IDLE_MS = 1_200
export const AUTOSAVE_MAX_WAIT_MS = 8_000

export type AutosaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'error' | 'conflict'

export interface AutosaveDraft {
  title?: string | null
  document?: NoteDocument
}

export interface AutosaveRequest extends AutosaveDraft {
  id: string
  version: number
}

export interface AutosaveConflict {
  message: string
  /** The server's copy, when the 409 carried it. */
  serverNote: Note | null
  serverVersion: number | null
}

export interface UseAutosaveOptions {
  noteId: string
  version: number
  /** False for a note the reader may not edit: nothing is scheduled or sent. */
  enabled: boolean
  save: (request: AutosaveRequest) => Promise<Note>
  onSaved?: (note: Note) => void
  idleMs?: number
  maxWaitMs?: number
}

export interface Autosave {
  state: AutosaveState
  error: ApiError | null
  conflict: AutosaveConflict | null
  lastSavedAt: number | null
  hasPendingChanges: boolean
  schedule: (draft: AutosaveDraft) => void
  flush: () => Promise<void>
  /**
   * Resume saving at `version` once the user has resolved a conflict.
   * `discardDraft` throws away what was typed — only ever in response to the
   * user choosing the other side.
   */
  resume: (version: number, discardDraft?: boolean) => void
}

function readConflict(error: ApiError): AutosaveConflict {
  const note = error.details.note
  const serverVersion = error.details.server_version

  return {
    message: error.message,
    serverNote: note && typeof note === 'object' ? (note as Note) : null,
    serverVersion: typeof serverVersion === 'number' ? serverVersion : null,
  }
}

/**
 * Drafts that could not be sent, parked by note id.
 *
 * A conflict stops the loop and keeps what was typed — that is the promise the
 * banner makes, in those words. But the draft lives in a ref inside this hook,
 * and opening another note clears it, because carrying it forward would write
 * one note's words into another. Between those two correct rules the user's
 * paragraph fell on the floor: hit a conflict, click a different note to check
 * something, come back, and it is gone with no error and nothing to undo.
 *
 * So a blocked draft is parked here on the way out and restored, with its
 * conflict, on the way back in. Module scope rather than a ref because the
 * hook remounts when the pane switches notes; the entry is removed the moment
 * the conflict is resolved or the note saves, so this holds only drafts that
 * are genuinely still waiting on the user.
 */
interface ParkedDraft {
  draft: AutosaveDraft
  conflict: AutosaveConflict | null
  version: number
}

const parkedDrafts = new Map<string, ParkedDraft>()

/**
 * Forget every parked draft.
 *
 * These live in memory, keyed by note id, and a note id means nothing outside
 * the account that owns it. Signing a different person in on the same tab must
 * therefore drop them, for the same reason the offline queue is cleared then:
 * an unsent draft carries no identity of its own, and restoring one for
 * "note-1" under a second user would put the first user's words on a
 * stranger's note. Tests use it to get a clean module between cases.
 */
export function clearParkedDrafts(): void {
  parkedDrafts.clear()
}

export function useAutosave({
  noteId,
  version,
  enabled,
  save,
  onSaved,
  idleMs = AUTOSAVE_IDLE_MS,
  maxWaitMs = AUTOSAVE_MAX_WAIT_MS,
}: UseAutosaveOptions): Autosave {
  const [state, setState] = useState<AutosaveState>('idle')
  const [error, setError] = useState<ApiError | null>(null)
  const [conflict, setConflict] = useState<AutosaveConflict | null>(null)
  const [lastSavedAt, setLastSavedAt] = useState<number | null>(null)
  const [hasPendingChanges, setHasPendingChanges] = useState(false)

  // Everything the timers and the unload listeners touch lives in a ref, so
  // they can be bound once instead of re-bound on every keystroke.
  const draftRef = useRef<AutosaveDraft | null>(null)
  const noteIdRef = useRef(noteId)
  const versionRef = useRef(version)
  const enabledRef = useRef(enabled)
  const blockedRef = useRef(false)
  /** The conflict as the cleanup sees it — state is not readable from there. */
  const conflictRef = useRef<AutosaveConflict | null>(null)
  const inFlightRef = useRef(false)
  const saveAgainRef = useRef(false)
  const idleTimer = useRef<number | null>(null)
  const maxTimer = useRef<number | null>(null)

  const saveRef = useRef(save)
  const onSavedRef = useRef(onSaved)
  saveRef.current = save
  onSavedRef.current = onSaved
  enabledRef.current = enabled

  const clearTimers = useCallback(() => {
    if (idleTimer.current !== null) window.clearTimeout(idleTimer.current)
    if (maxTimer.current !== null) window.clearTimeout(maxTimer.current)
    idleTimer.current = null
    maxTimer.current = null
  }, [])

  const flush = useCallback(async (): Promise<void> => {
    clearTimers()

    const draft = draftRef.current
    if (!draft || !enabledRef.current || blockedRef.current) return

    // A save is already on the wire. Let it finish and run again with whatever
    // has been typed since, rather than sending two versions of one note.
    if (inFlightRef.current) {
      saveAgainRef.current = true
      return
    }

    draftRef.current = null
    inFlightRef.current = true
    setState('saving')
    setEditorSaving(true)

    try {
      const saved = await saveRef.current({
        id: noteIdRef.current,
        version: versionRef.current,
        ...draft,
      })

      // The offline path resolves with the locally cached note, which may not
      // carry a version; keeping the old one is right in that case.
      if (typeof saved?.version === 'number') versionRef.current = saved.version
      parkedDrafts.delete(noteIdRef.current)

      setError(null)
      setState('saved')
      setLastSavedAt(Date.now())
      setHasPendingChanges(draftRef.current !== null)
      if (saved) onSavedRef.current?.(saved)
    } catch (caught) {
      // Nothing typed is thrown away: the draft goes back so the next flush,
      // or the conflict resolution, still has it.
      draftRef.current = { ...draft, ...(draftRef.current ?? {}) }
      setHasPendingChanges(true)

      if (caught instanceof ApiError && caught.isConflict) {
        blockedRef.current = true
        conflictRef.current = readConflict(caught)
        setConflict(conflictRef.current)
        setState('conflict')
      } else if (caught instanceof ApiError) {
        setError(caught)
        setState('error')
      } else {
        setError(new ApiError('SAVE_FAILED', 'This note could not be saved.', 0))
        setState('error')
      }
    } finally {
      inFlightRef.current = false
      setEditorSaving(false)

      if (saveAgainRef.current && !blockedRef.current) {
        saveAgainRef.current = false
        void flush()
      }
    }
  }, [clearTimers])

  const schedule = useCallback(
    (draft: AutosaveDraft) => {
      if (!enabledRef.current) return

      draftRef.current = { ...draftRef.current, ...draft }
      setHasPendingChanges(true)

      // A conflict is unresolved: keep collecting edits, send nothing.
      if (blockedRef.current) return

      setState('pending')

      if (idleTimer.current !== null) window.clearTimeout(idleTimer.current)
      idleTimer.current = window.setTimeout(() => {
        idleTimer.current = null
        void flush()
      }, idleMs)

      // Started once and left to run: this is the ceiling on how long a
      // continuous typist can go unsaved, so it must not be pushed back by
      // the next keystroke the way the idle timer is.
      if (maxTimer.current === null) {
        maxTimer.current = window.setTimeout(() => {
          maxTimer.current = null
          void flush()
        }, maxWaitMs)
      }
    },
    [flush, idleMs, maxWaitMs],
  )

  const resume = useCallback((nextVersion: number, discardDraft = false) => {
    versionRef.current = nextVersion
    blockedRef.current = false
    parkedDrafts.delete(noteIdRef.current)
    if (discardDraft) {
      draftRef.current = null
      setHasPendingChanges(false)
    }
    conflictRef.current = null
    setConflict(null)
    setError(null)
    setState(draftRef.current ? 'pending' : 'idle')
  }, [])

  // The note being edited changed. The cleanup runs before the body below, so
  // the pending draft is still flushed against the note it was typed into.
  useEffect(() => {
    noteIdRef.current = noteId
    // Set, not raised. `versionRef` says which revision *this note's* editor
    // content is based on, so it has to follow the note rather than only ever
    // climb: leaving note A at version 3 for note B at version 40 and coming
    // back used to send 40 for a note that is on 3, which the server refuses
    // — a conflict nobody caused, on a note nobody else had touched, with
    // autosave stopped until the page was reloaded.
    versionRef.current = version
    setError(null)

    const parked = parkedDrafts.get(noteId)
    if (parked) {
      // Left in conflict, returned to. Restore both halves — the words and the
      // reason they are not saved — or the banner is gone and the next
      // keystroke sends a draft the user never re-approved.
      draftRef.current = parked.draft
      versionRef.current = parked.version
      blockedRef.current = true
      conflictRef.current = parked.conflict
      setConflict(parked.conflict)
      setState('conflict')
      setHasPendingChanges(true)
      return
    }

    // Whatever the flush above could not send belonged to the previous note;
    // carrying it forward would write one note's words into another.
    draftRef.current = null
    blockedRef.current = false
    conflictRef.current = null
    setConflict(null)
    setState('idle')
    setHasPendingChanges(false)

    return () => {
      // A blocked draft cannot be flushed — that is what blocked means — so it
      // is parked against the note it was typed into, and picked up again when
      // the user comes back to decide.
      if (blockedRef.current && draftRef.current !== null) {
        parkedDrafts.set(noteIdRef.current, {
          draft: draftRef.current,
          conflict: conflictRef.current,
          version: versionRef.current,
        })
      }
      void flush()
    }
  }, [noteId, version, flush])

  /**
   * A newer version arrived from the server.
   *
   * Adopted only when this editor has nothing unsent. The number is not a
   * counter to keep up with: it is the claim "my content is based on revision
   * N", and the server compares it to decide whether this save is safe. Taking
   * a version that somebody else's save produced makes that claim false — the
   * optimistic lock then passes and their paragraph is overwritten by a
   * document that never contained it, silently, which is the exact failure the
   * whole version mechanism exists to prevent.
   *
   * So when there is a draft in hand, or a save on the wire, the old number is
   * kept: the next save carries it, the server answers 409, and the user is
   * shown the conflict instead of winning a race they did not know they were
   * in.
   */
  useEffect(() => {
    if (draftRef.current !== null || inFlightRef.current || blockedRef.current) return
    if (version > versionRef.current) versionRef.current = version
  }, [version])

  useEffect(() => {
    const onVisibilityChange = () => {
      if (document.visibilityState === 'hidden') void flush()
    }

    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      if (draftRef.current === null) return
      void flush()
      // The request will usually not survive the unload, so the warning is the
      // honest thing to show rather than a save the user cannot see finish.
      event.preventDefault()
      event.returnValue = ''
    }

    document.addEventListener('visibilitychange', onVisibilityChange)
    window.addEventListener('beforeunload', onBeforeUnload)

    return () => {
      document.removeEventListener('visibilitychange', onVisibilityChange)
      window.removeEventListener('beforeunload', onBeforeUnload)
      clearTimers()
    }
  }, [flush, clearTimers])

  return { state, error, conflict, lastSavedAt, hasPendingChanges, schedule, flush, resume }
}
