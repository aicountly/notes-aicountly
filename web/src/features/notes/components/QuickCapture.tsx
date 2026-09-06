/**
 * The composer.
 *
 * Capture is the one thing this product cannot make people wait for, so this is
 * one line until it is touched: "Take a note…" expands into a title, a body and
 * the handful of shortcuts that start a different kind of note. Saving does not
 * wait for the network — {@link useCreateNote} writes the note with a
 * client-generated id and the editor opens on it, online or not.
 *
 * Shortcuts for capabilities this deployment does not have are absent, not
 * greyed out: a scan button that answers 503 is worse than no scan button.
 */

import { useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent, RefObject } from 'react'
import { useNavigate } from 'react-router-dom'

import { Button, LiveStatus } from '../../../shared/ui/primitives'
import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { ApiError, api } from '../../../shared/api/client'
import { useAppConfig, useFeature } from '../../../app/AppConfigProvider'
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
  noteType: NoteType
  /** Kinds whose body is a checklist rather than prose. */
  checklist?: boolean
}

function describeError(error: unknown): string {
  if (error instanceof ApiError) return error.message
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
  const canDraw = useFeature('canvas')

  const [expanded, setExpanded] = useState(false)
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [status, setStatus] = useState('')

  const titleId = useId()
  const bodyId = useId()
  const localRef = useRef<HTMLTextAreaElement>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const field = inputRef ?? localRef

  const shortcuts: Shortcut[] = [
    { key: 'checklist', label: 'New checklist', icon: 'checklist', available: true, noteType: 'checklist', checklist: true },
    { key: 'voice', label: 'New voice note', icon: 'mic', available: canTranscribe, noteType: 'voice' },
    { key: 'scan', label: 'Scan a document', icon: 'scan', available: canScan, noteType: 'scan' },
    { key: 'drawing', label: 'New drawing', icon: 'draw', available: canDraw, noteType: 'drawing' },
  ]

  // The body grows with what is typed rather than scrolling inside four rows.
  useEffect(() => {
    const element = field.current
    if (!element || !expanded) return
    element.style.height = 'auto'
    element.style.height = `${element.scrollHeight}px`
  }, [body, expanded, field])

  const hasContent = title.trim() !== '' || body.trim() !== ''

  const collapse = () => {
    setExpanded(false)
    setError(null)
  }

  const open = async (input: CreateNoteInput, announcement: string) => {
    setError(null)
    try {
      const note = await create.mutateAsync({ ...input, notebook_id: notebookId })
      setStatus(announcement)
      setTitle('')
      setBody('')
      setExpanded(false)
      navigate(`/notes/${note.id}`)
      return note
    } catch (reason) {
      setError(describeError(reason))
      return null
    }
  }

  const save = () => {
    if (!hasContent || create.isPending) return
    void open(
      {
        title: title.trim() === '' ? null : title.trim(),
        document: documentFromText(body),
        note_type: 'document',
        source: 'quick-capture',
      },
      'Note saved',
    )
  }

  const startTyped = (shortcut: Shortcut) => {
    void open(
      {
        title: title.trim() === '' ? null : title.trim(),
        document: shortcut.checklist ? checklistFromText(body) : documentFromText(body),
        note_type: shortcut.noteType,
        source: 'quick-capture',
      },
      `${shortcut.label} created`,
    )
  }

  const onPickImage = async (file: File | undefined) => {
    if (!file) return

    const limit = config.limits.max_attachment_bytes
    if (file.size > limit) {
      setError(`That image is larger than the ${formatBytes(limit)} this deployment accepts.`)
      return
    }

    const note = await open(
      {
        title: title.trim() === '' ? null : title.trim(),
        document: documentFromText(body),
        note_type: 'document',
        source: 'quick-capture',
      },
      'Note created',
    )
    if (!note) return

    // The note is already open by now; a failed upload must still be reported,
    // so it is announced rather than swallowed.
    const form = new FormData()
    form.append('file', file)
    try {
      await api.upload(`/notes/${note.id}/attachments`, form)
      setStatus('Image attached')
    } catch (reason) {
      setStatus(`The note was created but the image did not attach. ${describeError(reason)}`)
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
            onChange={(event) => setTitle(event.target.value)}
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
        onChange={(event) => setBody(event.target.value)}
        onKeyDown={onFieldKeyDown}
      />

      {error ? (
        <p className="notes-notice notes-notice--danger" role="alert">
          <Icon name="alert" size={14} />
          {error}
        </p>
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
                onClick={() => startTyped(shortcut)}
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

      <LiveStatus>{status}</LiveStatus>
    </section>
  )
}
