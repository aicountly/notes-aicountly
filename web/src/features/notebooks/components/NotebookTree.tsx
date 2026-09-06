/**
 * The notebook tree in the sidebar.
 *
 * A notebook is a *place*, so this is the one part of the navigation that is
 * genuinely hierarchical, and the tree is rendered from `Notebook.children`
 * rather than re-derived from `parent_id` — the server has already done that
 * work and doing it twice is how the two disagree.
 *
 * What is expanded is remembered per notebook on this device, because a tree
 * that collapses on every reload is a tree nobody nests anything in. It also
 * opens itself onto the notebook you are looking at: a row three levels down
 * is not "collapsed" to a user who followed a link to it, it is missing.
 *
 * Every action in the "…" menu is checked against the notebook's own
 * capabilities and shown *disabled with the reason* rather than hidden, so a
 * viewer learns why they cannot rename something instead of wondering where
 * the option went.
 */

import { useEffect, useId, useMemo, useRef, useState } from 'react'
import type { CSSProperties } from 'react'
import { NavLink, useLocation } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, Dialog, EmptyState, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { RowMenu } from '../../organise/RowMenu'
import type { RowMenuHandle, RowMenuItem } from '../../organise/RowMenu'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { colorDotClass, iconOrDefault } from '../../organise/appearance'
import {
  NOTEBOOK_MAX_LEVEL,
  findNotebook,
  notebookAncestors,
  notebookCapabilities,
  subtreeHeight,
  subtreeIds,
  useDeleteNotebook,
  useMoveNotebook,
  useNotebooks,
  useUpdateNotebook,
} from '../hooks/useNotebooks'
import type { NotebookNode } from '../hooks/useNotebooks'
import { MoveToNotebookDialog } from './MoveToNotebookDialog'
import { NotebookDialog } from './NotebookDialog'
import { NotebookShareDialog } from './NotebookShareDialog'
import '../notebooks.css'

const EXPANDED_KEY = 'notes:notebooks:expanded'
const ARCHIVED_KEY = 'notes:notebooks:archived'

/**
 * Storage that is allowed to be unavailable.
 *
 * A private-mode browser throws on both reads and writes, and which branches
 * are open is never worth failing a render over.
 */
function readExpanded(): Set<string> {
  try {
    const stored: unknown = JSON.parse(window.localStorage.getItem(EXPANDED_KEY) ?? '[]')
    return new Set(Array.isArray(stored) ? stored.filter((id): id is string => typeof id === 'string') : [])
  } catch {
    return new Set()
  }
}

function writeExpanded(ids: Set<string>): void {
  try {
    window.localStorage.setItem(EXPANDED_KEY, JSON.stringify([...ids]))
  } catch {
    /* No preference is worth an exception. */
  }
}

function readFlag(key: string): boolean {
  try {
    return window.localStorage.getItem(key) === 'true'
  } catch {
    return false
  }
}

function writeFlag(key: string, value: boolean): void {
  try {
    window.localStorage.setItem(key, String(value))
  } catch {
    /* As above. */
  }
}

// ---------------------------------------------------------------------------
// What the menu can open
// ---------------------------------------------------------------------------

type TreeDialog =
  | { kind: 'create'; parent: NotebookNode | null }
  | { kind: 'edit'; notebook: NotebookNode; focus: 'name' | 'appearance' }
  | { kind: 'move'; notebook: NotebookNode }
  | { kind: 'share'; notebook: NotebookNode }
  | { kind: 'delete'; notebook: NotebookNode }

export function NotebookTree({ onNavigate }: { onNavigate?: () => void }) {
  const [showArchived, setShowArchived] = useState(() => readFlag(ARCHIVED_KEY))
  const notebooks = useNotebooks(showArchived)
  const update = useUpdateNotebook()
  const move = useMoveNotebook()
  const location = useLocation()
  const headingId = useId()

  const [expanded, setExpanded] = useState<Set<string>>(readExpanded)
  const [dialog, setDialog] = useState<TreeDialog | null>(null)
  const [status, setStatus] = useState<string | null>(null)
  const [actionError, setActionError] = useState<unknown>(null)

  const tree = useMemo(() => notebooks.data ?? [], [notebooks.data])
  const activeId = /^\/notebooks\/([^/]+)/.exec(location.pathname)?.[1] ?? null

  // Reveal the notebook the user is on, once its ancestors are known.
  useEffect(() => {
    if (activeId === null || tree.length === 0) return

    const trail = notebookAncestors(tree, activeId)
    if (trail.length === 0) return

    setExpanded((current) => {
      if (trail.every((id) => current.has(id))) return current
      const next = new Set(current)
      for (const id of trail) next.add(id)
      writeExpanded(next)
      return next
    })
  }, [activeId, tree])

  const toggle = (id: string) => {
    setExpanded((current) => {
      const next = new Set(current)
      if (!next.delete(id)) next.add(id)
      writeExpanded(next)
      return next
    })
  }

  const setArchived = (notebook: NotebookNode, archived: boolean) => {
    setActionError(null)
    update
      .mutateAsync({ id: notebook.id, is_archived: archived })
      .then(() => setStatus(archived ? `${notebook.name} archived` : `${notebook.name} restored`))
      .catch(setActionError)
  }

  const moveTarget = dialog?.kind === 'move' ? dialog.notebook : null

  return (
    <section className="nav-section" aria-labelledby={headingId}>
      <div className="nav-section__header">
        <h2 className="nav-section__title" id={headingId}>
          Notebooks
        </h2>
        <div className="org-section__actions">
          <Button
            icon="archive"
            iconOnly
            size="sm"
            variant="ghost"
            aria-pressed={showArchived}
            aria-label={showArchived ? 'Hide archived notebooks' : 'Show archived notebooks'}
            title={showArchived ? 'Hide archived notebooks' : 'Show archived notebooks'}
            onClick={() => {
              setShowArchived((current) => {
                writeFlag(ARCHIVED_KEY, !current)
                return !current
              })
            }}
          />
          <Button
            icon="plus"
            iconOnly
            size="sm"
            variant="ghost"
            aria-label="New notebook"
            title="New notebook"
            onClick={() => setDialog({ kind: 'create', parent: null })}
          />
        </div>
      </div>

      {actionError ? <ErrorNotice error={actionError} /> : null}

      {notebooks.isPending ? (
        <div className="org-skeletons" aria-hidden>
          {[0, 1, 2].map((row) => (
            <div className="org-skeleton-row" key={row}>
              <Skeleton width={16} height={16} radius={4} />
              <Skeleton width={`${68 - row * 12}%`} height={12} />
            </div>
          ))}
        </div>
      ) : notebooks.isError ? (
        <ErrorNotice error={notebooks.error} onRetry={() => void notebooks.refetch()} />
      ) : tree.length === 0 ? (
        <EmptyState
          icon="notebook"
          title="No notebooks yet"
          description="Notebooks are places for notes — Clients, Filings, Personal."
          action={
            <Button
              icon="plus"
              variant="primary"
              size="sm"
              onClick={() => setDialog({ kind: 'create', parent: null })}
            >
              New notebook
            </Button>
          }
        />
      ) : (
        <ul className="nb-tree">
          {tree.map((node) => (
            <NotebookBranch
              key={node.id}
              node={node}
              level={0}
              expanded={expanded}
              busy={update.isPending}
              onToggle={toggle}
              onNavigate={onNavigate}
              onArchive={setArchived}
              onDialog={setDialog}
            />
          ))}
        </ul>
      )}

      {dialog?.kind === 'create' || dialog?.kind === 'edit' ? (
        <NotebookDialog
          open
          notebook={dialog.kind === 'edit' ? dialog.notebook : null}
          parent={dialog.kind === 'create' ? dialog.parent : null}
          initialFocus={dialog.kind === 'edit' ? dialog.focus : 'name'}
          onClose={() => setDialog(null)}
          onSaved={(saved, wasCreated) => {
            setStatus(wasCreated ? `${saved.name} created` : `${saved.name} saved`)
            // A new sub-notebook is invisible if its parent is shut.
            if (wasCreated && saved.parent_id !== null) toggleOpen(setExpanded, saved.parent_id)
          }}
        />
      ) : null}

      {moveTarget ? (
        <MoveToNotebookDialog
          open
          title={`Move ${moveTarget.name}`}
          selectedId={moveTarget.parent_id}
          excludeIds={subtreeIds(moveTarget)}
          rootLabel="Top level"
          maxDestinationDepth={NOTEBOOK_MAX_LEVEL - 1 - subtreeHeight(moveTarget)}
          busy={move.isPending}
          error={move.error}
          onClose={() => {
            move.reset()
            setDialog(null)
          }}
          onSelect={(parentId) => {
            move
              .mutateAsync({ id: moveTarget.id, parent_id: parentId })
              .then((moved) => {
                setStatus(
                  parentId === null
                    ? `${moved.name} moved to the top level`
                    : `${moved.name} moved into ${findNotebook(tree, parentId)?.name ?? 'another notebook'}`,
                )
                setDialog(null)
              })
              // The dialog stays open and shows the refusal — a depth or cycle
              // error is something to choose differently, not to start over.
              .catch(() => undefined)
          }}
        />
      ) : null}

      {dialog?.kind === 'share' ? (
        <NotebookShareDialog open notebook={dialog.notebook} onClose={() => setDialog(null)} />
      ) : null}

      {dialog?.kind === 'delete' ? (
        <DeleteNotebookDialog
          notebook={dialog.notebook}
          parentName={findNotebook(tree, dialog.notebook.parent_id)?.name ?? null}
          onClose={() => setDialog(null)}
          onDeleted={(message) => {
            setStatus(message)
            setDialog(null)
          }}
        />
      ) : null}

      <LiveStatus>{status}</LiveStatus>
    </section>
  )
}

/** Open a branch from outside the row that owns it. */
function toggleOpen(setExpanded: (updater: (current: Set<string>) => Set<string>) => void, id: string): void {
  setExpanded((current) => {
    if (current.has(id)) return current
    const next = new Set(current)
    next.add(id)
    writeExpanded(next)
    return next
  })
}

// ---------------------------------------------------------------------------
// One notebook, and everything under it
// ---------------------------------------------------------------------------

interface BranchProps {
  node: NotebookNode
  level: number
  expanded: Set<string>
  busy: boolean
  onToggle: (id: string) => void
  onNavigate?: () => void
  onArchive: (notebook: NotebookNode, archived: boolean) => void
  onDialog: (dialog: TreeDialog) => void
}

function NotebookBranch({ node, level, expanded, busy, onToggle, onNavigate, onArchive, onDialog }: BranchProps) {
  const menuRef = useRef<RowMenuHandle>(null)
  const childrenId = useId()

  const children = node.children ?? []
  const hasChildren = children.length > 0
  const isOpen = expanded.has(node.id)
  const can = notebookCapabilities(node)

  const nestingFull = node.depth >= NOTEBOOK_MAX_LEVEL
  const items: RowMenuItem[] = [
    {
      key: 'new-child',
      label: 'New sub-notebook',
      icon: 'plus',
      disabledReason: !can.add_notes ? 'View only' : nestingFull ? 'Nesting limit' : undefined,
      onSelect: () => onDialog({ kind: 'create', parent: node }),
    },
    {
      key: 'rename',
      label: 'Rename…',
      icon: 'note',
      disabledReason: can.edit ? undefined : 'View only',
      onSelect: () => onDialog({ kind: 'edit', notebook: node, focus: 'name' }),
    },
    {
      key: 'appearance',
      label: 'Colour and icon…',
      icon: 'palette',
      disabledReason: can.edit ? undefined : 'View only',
      onSelect: () => onDialog({ kind: 'edit', notebook: node, focus: 'appearance' }),
    },
    {
      key: 'move',
      label: 'Move…',
      icon: 'notebook',
      disabledReason: can.move ? undefined : 'Owner only',
      onSelect: () => onDialog({ kind: 'move', notebook: node }),
    },
    {
      key: 'share',
      label: 'Share…',
      icon: 'share',
      separated: true,
      disabledReason: can.manage_members ? undefined : 'Owner only',
      onSelect: () => onDialog({ kind: 'share', notebook: node }),
    },
    {
      key: 'archive',
      label: node.is_archived ? 'Move out of archive' : 'Archive',
      icon: 'archive',
      disabledReason: can.manage_members ? undefined : 'Owner only',
      onSelect: () => onArchive(node, !node.is_archived),
    },
    {
      key: 'delete',
      label: 'Delete notebook…',
      icon: 'trash',
      danger: true,
      separated: true,
      disabledReason: can.delete ? undefined : 'Owner only',
      onSelect: () => onDialog({ kind: 'delete', notebook: node }),
    },
  ]

  return (
    <li>
      <div
        className={`org-row nb-row ${node.is_archived ? 'nb-row--archived' : ''}`.trim()}
        style={{ '--nb-level': level } as CSSProperties}
        onContextMenu={(event) => {
          event.preventDefault()
          menuRef.current?.open()
        }}
      >
        <NavLink
          to={`/notebooks/${node.id}`}
          onClick={onNavigate}
          className={({ isActive }) => `nav-item ${isActive ? 'nav-item--active' : ''}`}
        >
          <span className="nb-row__name">
            <span className={`org-dot ${colorDotClass(node.color)}`.trim()} aria-hidden />
            <Icon name={iconOrDefault(node.icon, 'notebook')} size={16} />
            <span className="nb-row__label">{node.name}</span>
            {node.is_archived ? <Badge>Archived</Badge> : null}
          </span>
          <span className="nav-item__count">{node.note_count > 999 ? '999+' : node.note_count}</span>
        </NavLink>

        {hasChildren ? (
          <button
            type="button"
            className="nb-twisty"
            aria-expanded={isOpen}
            aria-controls={childrenId}
            aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${node.name}`}
            onClick={() => onToggle(node.id)}
          >
            <Icon name="chevron-right" size={14} />
          </button>
        ) : null}

        <span className="org-row__menu">
          <RowMenu ref={menuRef} label={`Actions for ${node.name}`} items={items} busy={busy} />
        </span>
      </div>

      {hasChildren && isOpen ? (
        <ul className="nb-tree nb-tree--nested" id={childrenId}>
          {children.map((child) => (
            <NotebookBranch
              key={child.id}
              node={child}
              level={level + 1}
              expanded={expanded}
              busy={busy}
              onToggle={onToggle}
              onNavigate={onNavigate}
              onArchive={onArchive}
              onDialog={onDialog}
            />
          ))}
        </ul>
      ) : null}
    </li>
  )
}

// ---------------------------------------------------------------------------
// Deleting
// ---------------------------------------------------------------------------

/**
 * Deleting a notebook deletes the container and nothing else.
 *
 * The notes inside move up to the parent — or out to no notebook at all when
 * it was a root — and the sub-notebooks move by the same rule. The dialog says
 * so before the click and repeats the server's own count afterwards, because
 * "where did my 200 notes go" is not a question to leave a user holding.
 */
function DeleteNotebookDialog({
  notebook,
  parentName,
  onClose,
  onDeleted,
}: {
  notebook: NotebookNode
  parentName: string | null
  onClose: () => void
  onDeleted: (message: string) => void
}) {
  const remove = useDeleteNotebook()
  const [error, setError] = useState<unknown>(null)

  const destination = parentName === null ? 'no notebook, becoming unfiled' : parentName
  const children = notebook.children ?? []

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Delete “${notebook.name}”?`}
      description="Deleting a notebook removes the container. Everything inside it is kept."
      footer={
        <>
          <Button onClick={onClose} disabled={remove.isPending}>
            Cancel
          </Button>
          <Button
            variant="danger"
            icon="trash"
            loading={remove.isPending}
            onClick={() => {
              setError(null)
              remove
                .mutateAsync(notebook.id)
                .then((result) => onDeleted(result.message))
                .catch(setError)
            }}
          >
            Delete notebook
          </Button>
        </>
      }
    >
      {error ? <ErrorNotice error={error} /> : null}

      <ul className="nb-delete__list">
        <li>
          {notebook.note_count === 0
            ? 'It holds no notes of its own.'
            : `${notebook.note_count} ${notebook.note_count === 1 ? 'note moves' : 'notes move'} to ${destination}.`}
        </li>
        {children.length > 0 ? (
          <li>
            {children.length === 1 ? 'Its sub-notebook moves' : `Its ${children.length} sub-notebooks move`} up one
            level, keeping everything inside them.
          </li>
        ) : null}
        <li>Anyone this notebook was shared with loses the access it gave them.</li>
      </ul>
    </Dialog>
  )
}
