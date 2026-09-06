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

import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, EmptyState, Skeleton } from '../../../shared/ui/primitives'
import { useAppConfig } from '../../../app/AppConfigProvider'
import { NoteEditor } from '../../editor/NoteEditor'
import { useCreateNote, useNote, useNoteFlag } from '../hooks/useNotes'
import { NoteInfoPanel } from './NoteInfoPanel'
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
}

export function NoteEditorPane({
  noteId,
  listPath,
  listLabel,
  basePath,
  notebookId = null,
}: NoteEditorPaneProps) {
  const navigate = useNavigate()
  const config = useAppConfig()
  const create = useCreateNote()
  const flag = useNoteFlag()
  const note = useNote(noteId && noteId !== 'new' ? noteId : undefined)

  const [infoOpen, setInfoOpen] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)

  // One create per visit to /new, even though StrictMode runs effects twice
  // and this effect's dependencies are not referentially stable.
  const startedCreate = useRef(false)

  useEffect(() => {
    if (noteId !== 'new') {
      startedCreate.current = false
      return
    }
    if (startedCreate.current) return
    startedCreate.current = true

    create
      .mutateAsync({ notebook_id: notebookId })
      .then((created) => navigate(`${basePath}/${created.id}`, { replace: true }))
      // Left flagged as started: retrying automatically would loop against a
      // server that is refusing the create.
      .catch((reason: unknown) =>
        setCreateError(reason instanceof Error ? reason.message : 'That note could not be created.'),
      )
  }, [noteId, notebookId, basePath, create, navigate])

  if (noteId === null) {
    return (
      <section className="note-editor-pane" aria-label="Note">
        <div className="editor__pane-empty">
          <EmptyState
            icon="note"
            title="Nothing open"
            description="Choose a note from the list, or start a new one."
            action={
              <Button variant="primary" icon="plus" onClick={() => navigate(`${basePath}/new`)}>
                New note
              </Button>
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
                onClick={() => flag.mutate({ id: open.id, action: open.is_pinned ? 'unpin' : 'pin' })}
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
                onClick={() =>
                  flag.mutate({ id: open.id, action: open.is_favourite ? 'unfavourite' : 'favourite' })
                }
              />
            </>
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
          <NoteEditor note={open} />
        </div>

        {infoOpen ? <NoteInfoPanel note={open} onClose={() => setInfoOpen(false)} /> : null}
      </div>
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
