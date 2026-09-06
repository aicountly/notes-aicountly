/**
 * What this note used to say.
 *
 * The panel exists for one moment: somebody has lost a paragraph and wants it
 * back. So the list is checkpoints in plain words — who, when, and why there is
 * a checkpoint at all — and selecting one shows what the note said then,
 * read-only, before anything is changed.
 *
 * The confirmation says the thing that decides whether a person dares click it:
 * restoring is an ordinary edit, so the current version is checkpointed first
 * and appears in this same list. Restoring the wrong version costs one more
 * click, not a morning's writing. That is the server's behaviour
 * (`NotesController::restoreVersion`), not a reassurance invented here.
 *
 * The preview is a reading of the document, not the editor: headings, lists and
 * quotes keep their shape, everything else is its text. A version nobody is
 * editing does not need an editor, and mounting one to look at eleven revisions
 * would cost eleven editors.
 */

import { useState } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import { Button, Dialog, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { formatAbsoluteTime, formatRelativeTime } from '../../notes/components/NoteCard'
import { useMemberNames } from '../hooks/useMembers'
import {
  REVISION_REASON,
  useNoteVersion,
  useNoteVersions,
  useRestoreVersion,
} from '../hooks/useVersions'
import type { DocNode, Note, NoteDocument } from '../../../shared/api/types'
import '../../organise/organise.css'
import '../collaboration.css'

export interface VersionHistoryDrawerProps {
  note: Note
  onClose: () => void
  /** Told when the note has been rolled back, so the editor can reload it. */
  onRestored?: (note: Note) => void
}

export function VersionHistoryDrawer({ note, onClose, onRestored }: VersionHistoryDrawerProps) {
  const versions = useNoteVersions(note.id)
  const nameFor = useMemberNames(note.id)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [status, setStatus] = useState('')

  const selected = useNoteVersion(note.id, selectedId)
  const restore = useRestoreVersion()

  const entries = versions.data?.entries ?? []
  const selectedEntry = entries.find((entry) => entry.id === selectedId) ?? null
  const canRestore = note.capabilities.edit

  const confirmRestore = () => {
    if (selectedId === null) return

    setError(null)
    restore
      .mutateAsync({ noteId: note.id, versionId: selectedId })
      .then((restored) => {
        setStatus(`Version ${selectedEntry?.revision_number ?? ''} restored`.trim())
        setConfirming(false)
        setSelectedId(null)
        onRestored?.(restored)
      })
      .catch((reason: unknown) => {
        setError(reason)
        setConfirming(false)
      })
  }

  return (
    <aside className="side-panel" aria-label="Version history">
      <header className="side-panel__header">
        <Icon name="history" size={16} />
        <h2 className="side-panel__title">Version history</h2>
        <Button icon="close" iconOnly variant="ghost" size="sm" aria-label="Close version history" onClick={onClose} />
      </header>

      <div className="side-panel__body">
        {/* A refused restore is dismissible — the version is still selected and
            the button is still there to try again with. */}
        {error ? <ErrorNotice error={error} onDismiss={() => setError(null)} /> : null}

        {selectedEntry === null ? (
          <>
            <p className="collab-note collab-note--muted">
              A checkpoint is kept as the note is written, and every time it is restored. Current
              version: edited {formatRelativeTime(note.updated_at) || 'just now'}.
            </p>

            {versions.isPending ? (
              <div className="collab-skeletons" aria-hidden>
                {[0, 1, 2, 3].map((row) => (
                  <div className="collab-skeleton-row" key={row}>
                    <Skeleton width="55%" height={13} />
                    <Skeleton width="35%" height={11} />
                  </div>
                ))}
              </div>
            ) : versions.isError ? (
              <ErrorNotice error={versions.error} onRetry={() => void versions.refetch()} />
            ) : entries.length === 0 ? (
              <p className="org-empty">
                No earlier versions yet. One is kept the next time this note is saved.
              </p>
            ) : (
              <ul className="collab-versions">
                {entries.map((entry) => (
                  <li key={entry.id}>
                    {/* No aria-current here: this list is replaced by the
                        preview when a version is picked, so "the current one"
                        is never one of these rows — the attribute would be
                        false on every render and say nothing. */}
                    <button
                      type="button"
                      className="collab-version"
                      onClick={() => setSelectedId(entry.id)}
                    >
                      <span className="collab-version__label">
                        Version {entry.revision_number}
                        {entry.title ? ` · ${entry.title}` : ''}
                      </span>
                      <span className="collab-version__meta">
                        {nameFor(entry.created_by)} ·{' '}
                        <time dateTime={entry.created_at} title={formatAbsoluteTime(entry.created_at)}>
                          {formatRelativeTime(entry.created_at)}
                        </time>{' '}
                        · {REVISION_REASON[entry.reason] ?? entry.reason}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}

            {versions.data && versions.data.total > entries.length ? (
              <p className="collab-note collab-note--muted">
                Showing the {entries.length} most recent of {versions.data.total} checkpoints.
              </p>
            ) : null}
          </>
        ) : (
          <>
            <div className="collab-actions">
              <Button size="sm" icon="chevron-left" onClick={() => setSelectedId(null)}>
                All versions
              </Button>
            </div>

            <p className="collab-note collab-note--muted">
              Version {selectedEntry.revision_number} · {nameFor(selectedEntry.created_by)} ·{' '}
              <time dateTime={selectedEntry.created_at}>
                {formatAbsoluteTime(selectedEntry.created_at)}
              </time>
            </p>

            {selected.isPending ? (
              <div className="collab-skeletons" aria-hidden>
                {[0, 1, 2].map((row) => (
                  <Skeleton key={row} width="100%" height={14} />
                ))}
              </div>
            ) : selected.isError ? (
              <ErrorNotice error={selected.error} onRetry={() => void selected.refetch()} />
            ) : (
              <VersionPreview title={selected.data.title} doc={selected.data.document} />
            )}

            {canRestore ? (
              <div className="collab-actions">
                <Button
                  variant="primary"
                  icon="undo"
                  disabled={selected.isPending || selected.isError}
                  onClick={() => setConfirming(true)}
                >
                  Restore this version
                </Button>
              </div>
            ) : (
              <p className="collab-note">
                You have view-only access to this note, so you can read earlier versions but not
                restore one.
              </p>
            )}
          </>
        )}
      </div>

      {selectedEntry ? (
        <Dialog
          open={confirming}
          onClose={() => setConfirming(false)}
          title={`Restore version ${selectedEntry.revision_number}?`}
          description="The note goes back to what it said at that point."
          width={440}
          footer={
            <>
              <Button onClick={() => setConfirming(false)}>Cancel</Button>
              <Button variant="primary" icon="undo" loading={restore.isPending} onClick={confirmRestore}>
                Restore
              </Button>
            </>
          }
        >
          <p className="collab-note">
            What the note says now is checkpointed first, so nothing is lost: it appears in this
            list as the newest version and can be restored back.
          </p>
        </Dialog>
      ) : null}

      <LiveStatus>{status}</LiveStatus>
    </aside>
  )
}

// ---------------------------------------------------------------------------
// Reading a stored document
// ---------------------------------------------------------------------------

export interface PreviewBlock {
  kind: 'heading' | 'quote' | 'code' | 'list' | 'placeholder' | 'paragraph'
  text: string
}

/** Enough of a long note to recognise it, without rendering a book into a panel. */
const MAX_PREVIEW_BLOCKS = 200

/** Nodes that hold other blocks rather than text of their own. */
const CONTAINERS = new Set([
  'bulletList', 'orderedList', 'taskList', 'listItem', 'taskItem',
  'table', 'tableRow', 'tableCell', 'tableHeader',
])

const STANDALONE: Record<string, string> = {
  horizontalRule: '———',
  image: '[Image]',
  drawing: '[Drawing]',
}

function textOf(node: DocNode): string {
  if (typeof node.text === 'string') return node.text
  return (node.content ?? []).map(textOf).join('')
}

/**
 * A stored document, flattened into readable lines.
 *
 * Structure is kept only where it changes the meaning of the words — a heading,
 * a list item, a quotation, a code block. Marks are dropped: this is a preview
 * of what a version *said*, and bold text that cannot be edited is not worth a
 * second renderer to reproduce.
 *
 * At most `MAX_PREVIEW_BLOCKS + 1` lines come back: the extra one is how the
 * caller knows there is more, without it having to walk the document twice.
 */
export function previewBlocks(doc: NoteDocument): PreviewBlock[] {
  const blocks: PreviewBlock[] = []

  // One past the cap, so the caller can tell a version that ends exactly at
  // the cap from one that is cut off — and does not tell somebody the rest is
  // hidden when there is no rest.
  const walk = (node: DocNode, inList: boolean, inQuote: boolean): void => {
    if (blocks.length > MAX_PREVIEW_BLOCKS) return

    const standalone = STANDALONE[node.type]
    if (standalone !== undefined) {
      blocks.push({ kind: 'placeholder', text: standalone })
      return
    }

    if (CONTAINERS.has(node.type)) {
      const list = inList || node.type.endsWith('List')
      for (const child of node.content ?? []) walk(child, list, inQuote)
      return
    }

    if (node.type === 'blockquote' || node.type === 'callout') {
      for (const child of node.content ?? []) walk(child, inList, true)
      return
    }

    const text = textOf(node).trim()
    if (text === '') return

    blocks.push({
      kind:
        node.type === 'heading' ? 'heading'
        : node.type === 'codeBlock' ? 'code'
        : inQuote ? 'quote'
        : inList ? 'list'
        : 'paragraph',
      text: inList ? `• ${text}` : text,
    })
  }

  for (const node of doc.content ?? []) walk(node, false, false)

  return blocks
}

function VersionPreview({ title, doc }: { title: string | null; doc: NoteDocument }) {
  const collected = previewBlocks(doc)
  const blocks = collected.slice(0, MAX_PREVIEW_BLOCKS)
  const truncated = collected.length > MAX_PREVIEW_BLOCKS

  return (
    <div className="collab-preview">
      {title ? <p className="collab-preview__title">{title}</p> : null}

      {blocks.length === 0 ? (
        <p className="collab-preview__block collab-preview__block--placeholder">
          This version of the note was empty.
        </p>
      ) : (
        blocks.map((block, index) => (
          <p
            key={`${index}-${block.kind}`}
            className={`collab-preview__block collab-preview__block--${block.kind}`}
          >
            {block.text}
          </p>
        ))
      )}

      {truncated ? (
        <p className="collab-note collab-note--muted">
          The rest of this version is not shown. Restoring brings all of it back.
        </p>
      ) : null}
    </div>
  )
}
