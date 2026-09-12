/**
 * The composer.
 *
 * Capture is the one thing this product cannot make people wait for, so this is
 * one line until it is touched: "Take a note…" expands into a title, a body and
 * the handful of shortcuts that start a different kind of note. Saving does not
 * wait for the network — {@link useCreateNote} writes the note with a
 * client-generated id and the editor opens on it, online or not.
 *
 * Two rules about the shortcuts, both of them about honesty:
 *
 *   - Shortcuts for capabilities this deployment does not have are absent, not
 *     greyed out: a scan button that answers 503 is worse than no scan button.
 *   - A shortcut does what its label says. "Record a voice note" opens the
 *     recorder and "Scan a document" opens the scanner; neither drops the user
 *     into an empty text note and hopes they work out the rest.
 *
 * Files are stored *before* the note is opened. Navigating first would unmount
 * this component, and with it the only place a failed upload could be reported.
 */

import { useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent, RefObject } from 'react'
import { useNavigate } from 'react-router-dom'

import { Button, Dialog, LiveStatus } from '../../../shared/ui/primitives'
import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { ApiError } from '../../../shared/api/client'
import { useAppConfig, useFeature } from '../../../app/AppConfigProvider'
import { uploadFile } from '../../attachments/hooks/useAttachments'
import { DocumentScanner } from '../../capture/components/DocumentScanner'
import { VoiceRecorder, isVoiceRecordingSupported } from '../../capture/components/VoiceRecorder'
import { useCreateNote } from '../hooks/useNotes'
import type { CreateNoteInput } from '../hooks/useNotes'
import type { NoteDocument, NoteType } from '../../../shared/api/types'

/** Plain text → a paragraph per line. The server mints the block ids. */
function documentFromText(text: string): NoteDocument {
  const lines = text.split(/\r?\n/)

  return {
    type: 'doc',
    content: lines.map((line) =>
      line.trim() === ''
        ? { type: 'paragraph' }
        : { type: 'paragraph', content: [{ type: 'text', text: line }] },
    ),
  }
}

/** Plain text → one unticked item per line, so a typed list stays a list. */
function checklistFromText(text: string): NoteDocument {
  const items = text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '')

  return {
    type: 'doc',
    content: [
      {
        type: 'taskList',
        content: (items.length > 0 ? items : ['']).map((item) => ({
          type: 'taskItem',
          attrs: { checked: false },
          content: [
            item === ''
              ? { type: 'paragraph' }
              : { type: 'paragraph', content: [{ type: 'text', text: item }] },
          ],
        })),
      },
    ],
  }
}

interface Shortcut {
  key: string
  label: string
  icon: IconName
  available: boolean
  run: () => void
}

function describeError(error: unknown): string {
  if (error instanceof ApiError) return error.message
  if (error instanceof Error && error.message !== '') return error.message
  return 'That did not save. Please try again.'
}

function formatBytes(bytes: number): string {
  const mb = bytes / (1024 * 1024)
  return mb >= 1 ? `${Math.round(mb)} MB` : `${Math.round(bytes / 1024)} KB`
}

export interface QuickCaptureProps {
  /** Notes captured inside a notebook stay in it. */
  notebookId?: string | null
  /** Lets a page put the cursor here — the empty state's "Take a note" does. */
  inputRef?: RefObject<HTMLTextAreaElement | null>
}

export function QuickCapture({ notebookId = null, inputRef }: QuickCaptureProps) {
  const navigate = useNavigate()
  const create = useCreateNote()
  const config = useAppConfig()

  // Read once, at the top: a capability this deployment lacks removes the
  // shortcut entirely.
  const canTranscribe = useFeature('transcription')
  const canScan = useFeature('ocr')

  const [expanded, setExpanded] = useState(false)
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [error, setError] = useState<string | null>(null)
  /** A note that was created before its files failed to store. */
  const [strandedNoteId, setStrandedNoteId] = useState<string | null>(null)
  const [capture, setCapture] = useState<'voice' | 'scan' | null>(null)
  const [status, setStatus] = useState('')

  const titleId = useId()
  const bodyId = useId()
  const localRef = useRef<HTMLTextAreaElement>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const field = inputRef ?? localRef
  /** The note this attempt already created, so a retry does not make a second. */
  const pendingNoteId = useRef<string | null>(null)

  // The body grows with what is typed rather than scrolling inside four rows.
  useEffect(() => {
    const element = field.current
    if (!element) return

    if (!expanded) {
      // Collapsing has to hand the inline height back, or the one-line
      // composer stays as tall as the draft that was just closed.
      element.style.height = ''
      return
    }

    element.style.height = 'auto'
    element.style.height = `${element.scrollHeight}px`
  }, [body, expanded, field])

  const hasContent = title.trim() !== '' || body.trim() !== ''

  const clearDraft = () => {
    setTitle('')
    setBody('')
    setExpanded(false)
    setError(null)
    setStrandedNoteId(null)
    pendingNoteId.current = null
  }

  const collapse = () => {
    setExpanded(false)
    setError(null)
  }

  /**
   * Editing after a half-finished save abandons the note that save produced.
   *
   * It still holds what had been typed at the time, so it is not lost — but
   * the next save has to be a new note rather than an old one missing the
   * words that were added since.
   */
  const onDraftEdited = () => {
    if (pendingNoteId.current === null) return
    pendingNoteId.current = null
    setStrandedNoteId(null)
    setError(null)
  }

  const draft = (noteType: NoteType, asChecklist = false): CreateNoteInput => ({
    title: title.trim() === '' ? null : title.trim(),
    document: asChecklist ? checklistFromText(body) : documentFromText(body),
    note_type: noteType,
    notebook_id: notebookId,
    source: 'quick-capture',
  })

  /**
   * Create the note, store whatever came with it, then open it.
   *
   * Rejects rather than swallowing: the recorder and the scanner both keep what
   * they captured when this fails, and show the reason themselves.
   */
  const saveNote = async (input: CreateNoteInput, files: File[], announcement: string) => {
    const noteId = pendingNoteId.current ?? (await create.mutateAsync(input)).id
    pendingNoteId.current = noteId

    for (const file of files) await uploadFile(noteId, file)

    setStatus(announcement)
    setCapture(null)
    clearDraft()
    navigate(`/notes/${noteId}`)
  }

  /** The composer's own failures, which have nowhere else to appear. */
  const report = (reason: unknown) => {
    const created = pendingNoteId.current
    setStrandedNoteId(created)
    setError(
      created === null
        ? describeError(reason)
        : `The note was created but the file did not attach. ${describeError(reason)}`,
    )
  }

  const save = () => {
    if (!hasContent || create.isPending) return
    setError(null)
    void saveNote(draft('document'), [], 'Note saved').catch(report)
  }

  const closeCapture = () => {
    setCapture(null)
    // Whatever an abandoned capture created keeps the text that was typed into
    // it; the next capture starts a note of its own.
    pendingNoteId.current = null
    setStrandedNoteId(null)
  }

  const shortcuts: Shortcut[] = [
    {
      key: 'checklist',
      label: 'New checklist',
      icon: 'checklist',
      available: true,
      run: () => {
        setError(null)
        void saveNote(draft('checklist', true), [], 'Checklist created').catch(report)
      },
    },
    {
      key: 'voice',
      label: 'Record a voice note',
      icon: 'mic',
      // Only the browser's capability decides. Transcription is what happens
      // to a recording *after* it is stored, and the server never required its
      // flag to accept one — `POST /notes/{id}/attachments` asks for no
      // feature at all, and the transcription job reports `skipped` where
      // there is no engine. Gating the button on it removed a working way to
      // capture a thought, and left a phone with a microphone showing nothing
      // to press.
      available: isVoiceRecordingSupported(),
      run: () => {
        setError(null)
        setCapture('voice')
      },
    },
    {
      key: 'scan',
      label: 'Scan a document',
      icon: 'scan',
      // Likewise: scanning stores images. OCR is what reads them afterwards.
      available: true,
      run: () => {
        setError(null)
        setCapture('scan')
      },
    },
  ]

  const onPickImage = async (file: File | undefined) => {
    if (!file) return

    const limit = config.limits.max_attachment_bytes
    if (file.size > limit) {
      setError(`That image is larger than the ${formatBytes(limit)} this deployment accepts.`)
      setStrandedNoteId(null)
      return
    }

    setError(null)
    try {
      await saveNote(draft('document'), [file], 'Note saved with the image attached')
    } catch (reason) {
      report(reason)
    }
  }

  const onFieldKeyDown = (event: ReactKeyboardEvent<HTMLElement>) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
      event.preventDefault()
      save()
      return
    }
    if (event.key === 'Escape') {
      event.stopPropagation()
      collapse()
    }
  }

  return (
    <section
      className={`quick-capture ${expanded ? 'quick-capture--active' : ''}`.trim()}
      aria-label="Quick capture"
    >
      {expanded ? (
        <>
          <label className="sr-only" htmlFor={titleId}>
            Title
          </label>
          <input
            id={titleId}
            className="quick-capture__title"
            placeholder="Title"
            value={title}
            onChange={(event) => {
              setTitle(event.target.value)
              onDraftEdited()
            }}
            onKeyDown={onFieldKeyDown}
          />
        </>
      ) : null}

      <label className="sr-only" htmlFor={bodyId}>
        Note
      </label>
      <textarea
        id={bodyId}
        ref={field}
        className="quick-capture__field"
        rows={expanded ? 3 : 1}
        placeholder="Take a note…"
        value={body}
        onFocus={() => setExpanded(true)}
        onChange={(event) => {
          setBody(event.target.value)
          // Escape collapses without moving focus, so typing has to bring the
          // controls back — otherwise Save is unreachable from the keyboard.
          setExpanded(true)
          onDraftEdited()
        }}
        onKeyDown={onFieldKeyDown}
      />

      {error ? (
        <div className="notes-notice notes-notice--danger" role="alert">
          <Icon name="alert" size={14} />
          <span className="quick-capture__error">{error}</span>
          {strandedNoteId ? (
            <Button size="sm" variant="ghost" onClick={() => navigate(`/notes/${strandedNoteId}`)}>
              Open the note
            </Button>
          ) : null}
        </div>
      ) : null}

      {expanded ? (
        <div className="quick-capture__bar">
          {shortcuts
            .filter((shortcut) => shortcut.available)
            .map((shortcut) => (
              <Button
                key={shortcut.key}
                icon={shortcut.icon}
                iconOnly
                variant="ghost"
                size="sm"
                aria-label={shortcut.label}
                title={shortcut.label}
                disabled={create.isPending}
                onClick={shortcut.run}
              />
            ))}

          <Button
            icon="image"
            iconOnly
            variant="ghost"
            size="sm"
            aria-label="Add an image"
            title="Add an image"
            disabled={create.isPending}
            onClick={() => fileRef.current?.click()}
          />
          <input
            ref={fileRef}
            type="file"
            accept="image/*"
            className="sr-only"
            tabIndex={-1}
            aria-hidden
            aria-label="Choose an image"
            onChange={(event) => {
              void onPickImage(event.target.files?.[0])
              event.target.value = ''
            }}
          />

          <span className="quick-capture__spacer" />

          <span className="quick-capture__hint" aria-hidden>
            ⌘/Ctrl + Enter
          </span>
          <Button size="sm" variant="ghost" onClick={collapse}>
            Close
          </Button>
          <Button
            size="sm"
            variant="primary"
            icon="check"
            disabled={!hasContent}
            loading={create.isPending}
            onClick={save}
          >
            Save
          </Button>
        </div>
      ) : null}

      {/* Mounted only while open, so the microphone and the camera are released
          the moment the dialog closes rather than when this panel unmounts. */}
      <Dialog
        open={capture === 'voice'}
        onClose={closeCapture}
        title="Record a voice note"
        description={
          canTranscribe
            ? 'The recording is saved as a new note when you finish, and transcribed.'
            : 'The recording is saved as a new note when you finish. This deployment has no transcription engine, so it is kept as audio and not turned into text.'
        }
        width={560}
      >
        <VoiceRecorder
          onCancel={closeCapture}
          onSave={(file) => saveNote(draft('voice'), [file], 'Voice note saved')}
        />
      </Dialog>

      <Dialog
        open={capture === 'scan'}
        onClose={closeCapture}
        title="Scan a document"
        description={
          canScan
            ? 'Capture each page, straighten it, then save them all as one note. The text is read so you can search it.'
            : 'Capture each page, straighten it, then save them all as one note. This deployment has no text-recognition engine, so the pages are kept as images and their text is not searchable.'
        }
        width={720}
      >
        <DocumentScanner
          onCancel={closeCapture}
          onSave={(files) => saveNote(draft('scan'), files, 'Scan saved')}
        />
      </Dialog>

      <LiveStatus>{status}</LiveStatus>
    </section>
  )
}
