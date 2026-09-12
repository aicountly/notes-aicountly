/**
 * The right-hand pane: everything around the editor.
 *
 * The pane owns the note's identity — loading it, saying where it lives, and
 * the actions that apply to the whole note — and hands the document itself to
 * {@link NoteEditor}. That split is why the editor never has to know about
 * routing, capabilities or the info panel.
 *
 * `/notes/new` is a route, not a mode: Cmd+N and every "New note" button go
 * there, the pane creates the note with a client-generated id, and the URL is
 * replaced with the real one. The note exists before the network answers.
 */

import { Suspense, lazy, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, EmptyState, Skeleton } from '../../../shared/ui/primitives'
import { useAppConfig } from '../../../app/AppConfigProvider'
import { useImageUploader, useImageSrcLoader } from '../../attachments/hooks/useAttachments'

/**
 * The editor arrives after the list, not before it.
 *
 * Tiptap, ProseMirror and every extension are the heaviest thing the app
 * loads, and this pane is mounted on every list route — including the ones
 * where nothing is open and the pane renders an empty state. Importing the
 * editor eagerly put all of that in front of the first paint of a screen that
 * shows note cards.
 *
 * Deferring it alone would only move the wait to the first note the user
 * opens, so the chunk is also fetched on idle as soon as the pane mounts: the
 * cost lands in the gap between "the list appeared" and "the user picked
 * something", where there is nothing else to do. If they beat the prefetch,
 * {@link EditorSkeleton} covers the difference — the same skeleton a note that
 * is still loading shows, so the transition looks like one wait, not two.
 */
const loadEditor = () => import('../../editor/NoteEditor')
const NoteEditor = lazy(() => loadEditor().then((module) => ({ default: module.NoteEditor })))
import { useCreateNote, useNote, useNoteFlag } from '../hooks/useNotes'
import { NOTE_TYPES } from '../../../shared/api/types'
import type { NoteType } from '../../../shared/api/types'
import { NoteInfoPanel } from './NoteInfoPanel'
import { AttachmentList } from '../../attachments/components/AttachmentList'
import { ShareDialog } from '../../collaboration/components/ShareDialog'
import { NoteMenu, daysUntilPurge } from './NoteCard'

export interface NoteEditorPaneProps {
  /** The open note, `'new'` to create one, or null for "nothing open". */
  noteId: string | null
  /** The list this pane sits beside — its path and what to call it. */
  listPath: string
  listLabel: string
  /** Where a note in this scope lives, e.g. `/notebooks/{id}/notes`. */
  basePath: string
  /** Notes created from here land in this notebook. */
  notebookId?: string | null
  /**
   * False where a new note has no business being filed — Trash, Archive and
   * Shared. Creating one there would leave it at, say, `/trash/{id}`: a live
   * note wearing the URL of a list it is not in.
   */
  canCreate?: boolean
}

export function NoteEditorPane({
  noteId,
  listPath,
  listLabel,
  basePath,
  notebookId = null,
  canCreate = true,
}: NoteEditorPaneProps) {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const config = useAppConfig()
  const create = useCreateNote()
  const flag = useNoteFlag()
  const note = useNote(noteId && noteId !== 'new' ? noteId : undefined)

  // Pasting or dropping an image into the editor stores it as an attachment.
  // The uploader is resolved here rather than inside the editor because the
  // pane is what knows which note is open; the editor stays a document
  // component that neither fetches nor uploads.
  const uploadImage = useImageUploader(note.data?.id)
  // The document stores the canonical attachment URL, which no `<img>` can
  // load on its own: it is behind the session's Bearer token. This fetches the
  // bytes with the session and hands back an object URL.
  const loadImageSrc = useImageSrcLoader()

  const [infoOpen, setInfoOpen] = useState(false)
  const [shareOpen, setShareOpen] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  // Pin and favourite are optimistic; without this the only sign of a refused
  // change is the icon quietly flipping back.
  const [flagError, setFlagError] = useState<string | null>(null)
  /** Bumped by "Try again", so the retry does not depend on `create`'s identity. */
  const [createAttempt, setCreateAttempt] = useState(0)

  // A refusal belongs to the note it was about; opening another one clears it.
  useEffect(() => setFlagError(null), [noteId])

  // Warm the editor chunk while the browser has nothing better to do. Idle
  // rather than immediate: the list this pane sits beside is still fetching
  // and rendering, and competing with it would trade one wait for another.
  useEffect(() => {
    // Swallowed on purpose. This is an opportunistic warm-up, and the one
    // thing guaranteed to make it fail is the case the app is built for:
    // offline, where the chunk has not been cached yet and `import()` rejects.
    // Unhandled, that rejection surfaces as an error in the console of a
    // working app — and the real attempt still happens later, through
    // Suspense, when the reader actually opens a note.
    const start = () => void loadEditor().catch(() => undefined)

    if (typeof window.requestIdleCallback === 'function') {
      const handle = window.requestIdleCallback(start, { timeout: 3_000 })
      return () => window.cancelIdleCallback(handle)
    }

    const handle = window.setTimeout(start, 400)
    return () => window.clearTimeout(handle)
  }, [])

  // One create per visit to /new, even though StrictMode runs effects twice
  // and this effect's dependencies are not referentially stable.
  const startedCreate = useRef(false)

  useEffect(() => {
    if (noteId !== 'new') {
      startedCreate.current = false
      return
    }
    // `/trash/new` typed by hand, or arrived at from a stale link: send it to
    // the one list a new note does belong in rather than creating it here.
    if (!canCreate) {
      navigate('/notes/new', { replace: true })
      return
    }
    if (startedCreate.current) return
    startedCreate.current = true

    // `/notes/new?type=checklist` is the PWA's "New checklist" shortcut
    // (manifest.webmanifest). The parameter was read by nothing, so the
    // shortcut made an ordinary note and the difference existed only in the
    // launcher menu.
    const requested = searchParams.get('type')
    const noteType = requested !== null && NOTE_TYPES.includes(requested as NoteType)
      ? (requested as NoteType)
      : undefined

    create
      .mutateAsync({ notebook_id: notebookId, note_type: noteType })
      .then((created) => navigate(`${basePath}/${created.id}`, { replace: true }))
      // Left flagged as started: retrying automatically would loop against a
      // server that is refusing the create.
      .catch((reason: unknown) =>
        setCreateError(reason instanceof Error ? reason.message : 'That note could not be created.'),
      )
  }, [noteId, notebookId, basePath, canCreate, createAttempt, create, navigate, searchParams])

  if (noteId === null) {
    return (
      <section className="note-editor-pane" aria-label="Note">
        <div className="editor__pane-empty">
          <EmptyState
            icon="note"
            title="Nothing open"
            description={
              canCreate
                ? 'Choose a note from the list, or start a new one.'
                : `Choose a note from ${listLabel} to read it here.`
            }
            action={
              canCreate ? (
                <Button variant="primary" icon="plus" onClick={() => navigate(`${basePath}/new`)}>
                  New note
                </Button>
              ) : (
                <Button icon="note" onClick={() => navigate('/notes')}>
                  Go to My Notes
                </Button>
              )
            }
          />
        </div>
      </section>
    )
  }

  if (noteId === 'new') {
    return (
      <section className="note-editor-pane" aria-label="Note">
        {createError ? (
          <div className="editor__pane-empty">
            <EmptyState
              icon="alert"
              title="That note could not be created"
              description={createError}
              action={
                <Button
                  variant="primary"
                  icon="refresh"
                  onClick={() => {
                    startedCreate.current = false
                    setCreateError(null)
                    setCreateAttempt((attempt) => attempt + 1)
                  }}
                >
                  Try again
                </Button>
              }
            />
          </div>
        ) : (
          <EditorSkeleton />
        )}
      </section>
    )
  }

  if (note.isPending) {
    return (
      <section className="note-editor-pane" aria-label="Note">
        <EditorSkeleton />
      </section>
    )
  }

  if (note.isError) {
    const offline = note.error.isOffline

    return (
      <section className="note-editor-pane" aria-label="Note">
        <div className="editor__pane-empty">
          <EmptyState
            icon={offline ? 'cloud-off' : 'alert'}
            title={offline ? 'This note is not on this device' : 'This note could not be opened'}
            description={note.error.message}
            action={
              <Button icon="refresh" onClick={() => void note.refetch()}>
                Try again
              </Button>
            }
          />
        </div>
      </section>
    )
  }

  const open = note.data
  const capabilities = open.capabilities
  const trashedDays = daysUntilPurge(open.deleted_at, config.limits.trash_retention_days)

  return (
    <section className="note-editor-pane" aria-label={open.display_title}>
      <header className="editor__header">
        <Button
          className="editor__back"
          icon="chevron-left"
          iconOnly
          variant="ghost"
          size="sm"
          aria-label={`Back to ${listLabel}`}
          onClick={() => navigate(listPath)}
        />

        <nav className="editor__breadcrumb" aria-label="Breadcrumb">
          <Link className="editor__crumb-link" to={listPath}>
            {listLabel}
          </Link>
          <Icon name="chevron-right" size={12} />
          <span>{open.display_title}</span>
        </nav>

        {!capabilities.edit ? <Badge>View only</Badge> : null}
        {open.privacy_mode === 'private' ? <Badge tone="warning">Private</Badge> : null}

        <div className="editor__actions">
          {capabilities.edit && open.deleted_at === null ? (
            <>
              <Button
                icon={open.is_pinned ? 'pin-filled' : 'pin'}
                iconOnly
                variant="ghost"
                size="sm"
                aria-pressed={open.is_pinned}
                aria-label={open.is_pinned ? 'Unpin this note' : 'Pin this note'}
                onClick={() => {
                  setFlagError(null)
                  flag.mutate(
                    { id: open.id, action: open.is_pinned ? 'unpin' : 'pin' },
                    { onError: (reason) => setFlagError(reason.message) },
                  )
                }}
              />
              <Button
                icon={open.is_favourite ? 'star-filled' : 'star'}
                iconOnly
                variant="ghost"
                size="sm"
                aria-pressed={open.is_favourite}
                aria-label={
                  open.is_favourite ? 'Remove from favourites' : 'Add to favourites'
                }
                onClick={() => {
                  setFlagError(null)
                  flag.mutate(
                    { id: open.id, action: open.is_favourite ? 'unfavourite' : 'favourite' },
                    { onError: (reason) => setFlagError(reason.message) },
                  )
                }}
              />
            </>
          ) : null}

          {/* Sharing had no way in at all: the dialog was written, styled and
              tested, and the only control that mentioned sharing opened the
              operating system's share sheet — which passes a URL to somebody
              who cannot open the note. */}
          {capabilities.share && open.privacy_mode === 'standard' ? (
            <Button
              icon="share"
              iconOnly
              variant="ghost"
              size="sm"
              aria-label="Share this note"
              onClick={() => setShareOpen(true)}
            />
          ) : null}

          <Button
            icon="info"
            iconOnly
            variant="ghost"
            size="sm"
            aria-pressed={infoOpen}
            aria-label={infoOpen ? 'Hide note info' : 'Show note info'}
            onClick={() => setInfoOpen((current) => !current)}
          />

          <NoteMenu note={open} to={`${basePath}/${open.id}`} size="md" onRemoved={() => navigate(listPath)} />
        </div>
      </header>

      {flagError ? (
        <div className="editor__pane-notice">
          <p className="notes-notice notes-notice--danger" role="alert">
            <Icon name="alert" size={14} />
            {flagError}
          </p>
        </div>
      ) : null}

      {open.deleted_at ? (
        <div className="editor__pane-notice">
          <p className="notes-notice notes-notice--warning">
            <Icon name="trash" size={14} />
            This note is in Trash. It will be deleted for good in {trashedDays}{' '}
            {trashedDays === 1 ? 'day' : 'days'} unless you restore it.
          </p>
        </div>
      ) : null}

      <div className="editor__pane-body">
        <div className="editor__pane-main">
          <Suspense fallback={<EditorSkeleton />}>
            <NoteEditor note={open} uploadImage={uploadImage} loadImageSrc={loadImageSrc} />
          </Suspense>

          {/* Under the document, where an attachment belongs: this is the
              surface that adds, previews and removes them. The info panel's
              list is a glance and a download, and reads the same query. */}
          <AttachmentList noteId={open.id} capabilities={capabilities} />
        </div>

        {infoOpen ? <NoteInfoPanel note={open} onClose={() => setInfoOpen(false)} /> : null}
      </div>

      <ShareDialog note={open} open={shareOpen} onClose={() => setShareOpen(false)} />
    </section>
  )
}

/** Shaped like a note, so nothing moves when the real one arrives. */
function EditorSkeleton() {
  return (
    <div className="editor__scroll" aria-busy>
      <div className="editor__sheet editor__skeleton">
        <Skeleton width="60%" height={34} radius={8} />
        <Skeleton width="92%" height={15} />
        <Skeleton width="86%" height={15} />
        <Skeleton width="94%" height={15} />
        <Skeleton width="48%" height={15} />
      </div>
    </div>
  )
}
