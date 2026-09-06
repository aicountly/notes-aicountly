/**
 * One note, as a card.
 *
 * The most-repeated object in the product, so the rule here is restraint: the
 * title, a few lines of body, and only the metadata that changes a decision —
 * how much of a checklist is done, whether there is a reminder, whether anyone
 * else can see it. Everything else belongs in the note.
 *
 * The whole card opens the note, but it is one anchor stretched over the card
 * rather than a click handler on a div: that keeps middle-click, "open in new
 * tab" and screen-reader link navigation working, and it lets the pin and the
 * menu be real buttons instead of clicks the card has to guess about.
 */

import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'

import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { Button, Dialog, LiveStatus } from '../../../shared/ui/primitives'
import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import {
  useDuplicateNote,
  useNoteFlag,
  useTrashNote,
  useUpdateNote,
} from '../hooks/useNotes'
import { NoteColorPicker } from './NoteColorPicker'
import type {
  NoteCapabilities,
  NoteColor,
  NoteRole,
  NoteSummary,
  Notebook,
} from '../../../shared/api/types'

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
const ABSOLUTE = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' })

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['year', 31_536_000_000],
  ['month', 2_592_000_000],
  ['week', 604_800_000],
  ['day', 86_400_000],
  ['hour', 3_600_000],
  ['minute', 60_000],
]

/**
 * "3 days ago", in the user's locale.
 *
 * Intl does this in every language the browser knows; a date library would ship
 * a second copy of that knowledge into the bundle to do the same job.
 */
export function formatRelativeTime(iso: string | null | undefined): string {
  if (!iso) return ''
  const value = Date.parse(iso)
  if (Number.isNaN(value)) return ''

  const difference = value - Date.now()
  for (const [unit, size] of UNITS) {
    if (Math.abs(difference) >= size) return RELATIVE.format(Math.round(difference / size), unit)
  }
  return RELATIVE.format(0, 'second')
}

export function formatAbsoluteTime(iso: string | null | undefined): string {
  if (!iso) return ''
  const value = Date.parse(iso)
  return Number.isNaN(value) ? '' : ABSOLUTE.format(value)
}

const ROLE_RANK: Record<NoteRole, number> = { owner: 4, editor: 3, commenter: 2, viewer: 1 }

/**
 * What a role may do, derived the same way the server derives it.
 *
 * A list row carries a `role` but not the `capabilities` object, and hiding an
 * action the server will refuse is better than showing it and explaining the
 * 403 afterwards. Mirrors `NotePermissionService::capabilities()`.
 */
export function capabilitiesForRole(role: NoteRole): NoteCapabilities {
  const rank = ROLE_RANK[role] ?? 0
  return {
    view: rank >= ROLE_RANK.viewer,
    comment: rank >= ROLE_RANK.commenter,
    edit: rank >= ROLE_RANK.editor,
    share: rank >= ROLE_RANK.owner,
    delete: rank >= ROLE_RANK.owner,
    restore: rank >= ROLE_RANK.owner,
    manage_members: rank >= ROLE_RANK.owner,
  }
}

const TYPE_ICON: Record<string, IconName> = {
  checklist: 'checklist',
  voice: 'mic',
  meeting: 'meeting',
  drawing: 'draw',
  canvas: 'draw',
  scan: 'scan',
}

function describeError(error: unknown): string {
  if (error instanceof ApiError) return error.message
  return 'Something went wrong. Please try again.'
}

// ---------------------------------------------------------------------------
// The "…" menu
// ---------------------------------------------------------------------------

interface MenuAction {
  key: string
  label: string
  icon: IconName
  danger?: boolean
  run: () => void
}

export interface NoteMenuProps {
  note: NoteSummary
  /** In-app path to this note. Used by "Copy link" and "Share". */
  to: string
  /** Called once the note has left this view — trashed, restored or purged. */
  onRemoved?: () => void
  /** A slightly larger trigger for the editor header. */
  size?: 'sm' | 'md'
}

/**
 * Everything a user can do to a note without opening it.
 *
 * Actions the caller's role does not permit are absent rather than disabled: a
 * viewer does not need to be told, on every card, about the four things they
 * cannot do. A failure keeps the menu open and shows the server's own message,
 * because "Delete failed" without a reason is not a report.
 */
export function NoteMenu({ note, to, onRemoved, size = 'sm' }: NoteMenuProps) {
  const [open, setOpen] = useState(false)
  const [view, setView] = useState<'root' | 'colour'>('root')
  const [above, setAbove] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [movingNotebook, setMovingNotebook] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [status, setStatus] = useState('')

  const containerRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const popupRef = useRef<HTMLDivElement>(null)

  const flag = useNoteFlag()
  const update = useUpdateNote()
  const trash = useTrashNote()
  const duplicate = useDuplicateNote()

  const busy = flag.isPending || update.isPending || trash.isPending || duplicate.isPending
  const capabilities = capabilitiesForRole(note.role)
  const trashed = note.deleted_at !== null

  const close = useCallback(() => {
    setOpen(false)
    setView('root')
    setError(null)
    triggerRef.current?.focus()
  }, [])

  // Clicking anywhere else closes the menu. Capture phase, so a click on
  // another card's trigger closes this one before opening that one.
  useEffect(() => {
    if (!open) return undefined

    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false)
        setView('root')
      }
    }
    document.addEventListener('pointerdown', onPointerDown, true)
    return () => document.removeEventListener('pointerdown', onPointerDown, true)
  }, [open])

  // Open upwards when there is no room below — a menu that renders off the end
  // of a scrolling column is a menu the user cannot reach.
  useLayoutEffect(() => {
    if (!open) return
    const rect = triggerRef.current?.getBoundingClientRect()
    if (rect) setAbove(window.innerHeight - rect.bottom < 280 && rect.top > 280)
  }, [open])

  useEffect(() => {
    if (open && view === 'root') {
      popupRef.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
    }
  }, [open, view])

  const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.stopPropagation()
      close()
      return
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return

    const items = Array.from(popupRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])
    if (items.length === 0) return

    event.preventDefault()
    const index = items.indexOf(document.activeElement as HTMLElement)
    const next = event.key === 'ArrowDown' ? index + 1 : index - 1
    items[(next + items.length) % items.length].focus()
  }

  /** Run one action, announce it, and only then tell the list it changed. */
  const run = (work: Promise<unknown>, done: string, after?: () => void) => {
    setError(null)
    work
      .then(() => {
        setStatus(done)
        close()
        after?.()
      })
      .catch((reason: unknown) => setError(describeError(reason)))
  }

  const setFlag = (
    action: 'pin' | 'unpin' | 'favourite' | 'unfavourite' | 'archive' | 'unarchive' | 'restore',
    done: string,
    after?: () => void,
  ) => run(flag.mutateAsync({ id: note.id, action }), done, after)

  const copyLink = async () => {
    const url = `${window.location.origin}${to}`
    try {
      await navigator.clipboard.writeText(url)
      setStatus('Link copied')
      close()
    } catch {
      setError('This browser would not let the page copy to the clipboard.')
    }
  }

  const share = async () => {
    const url = `${window.location.origin}${to}`
    if (typeof navigator.share === 'function') {
      try {
        await navigator.share({ title: note.display_title, url })
        close()
      } catch (reason) {
        // Dismissing the system sheet is a decision, not a failure.
        if ((reason as Error)?.name !== 'AbortError') setError(describeError(reason))
      }
      return
    }
    await copyLink()
  }

  const actions: MenuAction[] = []

  if (trashed) {
    if (capabilities.restore) {
      actions.push({
        key: 'restore',
        label: 'Restore',
        icon: 'undo',
        run: () => setFlag('restore', 'Note restored', onRemoved),
      })
    }
    if (capabilities.delete) {
      actions.push({
        key: 'purge',
        label: 'Delete forever',
        icon: 'trash',
        danger: true,
        run: () => {
          setOpen(false)
          setConfirmingDelete(true)
        },
      })
    }
  } else {
    if (capabilities.edit) {
      actions.push({
        key: 'pin',
        label: note.is_pinned ? 'Unpin' : 'Pin',
        icon: note.is_pinned ? 'pin-filled' : 'pin',
        run: () => setFlag(note.is_pinned ? 'unpin' : 'pin', note.is_pinned ? 'Unpinned' : 'Pinned'),
      })
      actions.push({
        key: 'favourite',
        label: note.is_favourite ? 'Remove from favourites' : 'Add to favourites',
        icon: note.is_favourite ? 'star-filled' : 'star',
        run: () =>
          setFlag(
            note.is_favourite ? 'unfavourite' : 'favourite',
            note.is_favourite ? 'Removed from favourites' : 'Added to favourites',
          ),
      })
      actions.push({
        key: 'colour',
        label: 'Colour',
        icon: 'palette',
        run: () => setView('colour'),
      })
      actions.push({
        key: 'move',
        label: 'Move to notebook…',
        icon: 'notebook',
        run: () => {
          setOpen(false)
          setMovingNotebook(true)
        },
      })
    }

    actions.push({
      key: 'duplicate',
      label: 'Duplicate',
      icon: 'copy',
      run: () => run(duplicate.mutateAsync(note.id), 'Copy created'),
    })

    // A private note holds ciphertext the server cannot read, so there is
    // nothing useful to hand to anyone else.
    if (capabilities.share && note.privacy_mode === 'standard') {
      actions.push({ key: 'share', label: 'Share…', icon: 'share', run: () => void share() })
    }

    actions.push({ key: 'copy-link', label: 'Copy link', icon: 'link', run: () => void copyLink() })

    if (capabilities.edit) {
      actions.push({
        key: 'archive',
        label: note.is_archived ? 'Move out of archive' : 'Archive',
        icon: 'archive',
        run: () =>
          setFlag(
            note.is_archived ? 'unarchive' : 'archive',
            note.is_archived ? 'Unarchived' : 'Archived',
            onRemoved,
          ),
      })
    }

    if (capabilities.delete) {
      actions.push({
        key: 'trash',
        label: 'Move to Trash',
        icon: 'trash',
        danger: true,
        run: () => run(trash.mutateAsync({ id: note.id }), 'Moved to Trash', onRemoved),
      })
    }
  }

  return (
    <div className="note-menu" ref={containerRef}>
      <button
        ref={triggerRef}
        type="button"
        className={`note-menu__trigger ${size === 'md' ? 'note-menu__trigger--md' : ''}`.trim()}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Actions for ${note.display_title}`}
        onClick={() => {
          setOpen((current) => !current)
          setView('root')
          setError(null)
        }}
      >
        <Icon name="more" size={16} />
      </button>

      {open ? (
        <div
          ref={popupRef}
          className={`note-menu__popup ${above ? 'note-menu__popup--above' : ''}`.trim()}
          role={view === 'root' ? 'menu' : 'group'}
          aria-label={view === 'root' ? `Actions for ${note.display_title}` : 'Note colour'}
          onKeyDown={onKeyDown}
        >
          {view === 'colour' ? (
            <>
              <p className="note-menu__heading">
                <Icon name="palette" size={13} />
                Colour
              </p>
              <NoteColorPicker
                value={note.color}
                busy={busy}
                onSelect={(colour: NoteColor | null) =>
                  run(update.mutateAsync({ id: note.id, color: colour }), colour ? `Colour set to ${colour}` : 'Colour removed')
                }
              />
              <hr className="note-menu__separator" />
              <button type="button" className="note-menu__item" onClick={() => setView('root')}>
                <Icon name="chevron-left" size={15} />
                <span className="note-menu__item-label">Back</span>
              </button>
            </>
          ) : (
            actions.map((action) => (
              <button
                key={action.key}
                type="button"
                role="menuitem"
                className={`note-menu__item ${action.danger ? 'note-menu__item--danger' : ''}`.trim()}
                disabled={busy}
                onClick={action.run}
              >
                <Icon name={action.icon} size={15} />
                <span className="note-menu__item-label">{action.label}</span>
              </button>
            ))
          )}

          {error ? (
            <p className="note-menu__status" role="alert">
              {error}
            </p>
          ) : null}
        </div>
      ) : null}

      {/* Mounted only while open: a dialog per card, times forty cards, is
          forty idle mutation hooks the list does not need. */}
      {confirmingDelete ? (
        <ConfirmPurgeDialog
          note={note}
          onClose={() => {
            setConfirmingDelete(false)
            triggerRef.current?.focus()
          }}
          onDeleted={() => {
            setStatus('Note deleted')
            setConfirmingDelete(false)
            onRemoved?.()
          }}
        />
      ) : null}

      {movingNotebook ? (
        <MoveToNotebookDialog
          note={note}
          onClose={() => {
            setMovingNotebook(false)
            triggerRef.current?.focus()
          }}
          onMoved={() => {
            setStatus('Note moved')
            setMovingNotebook(false)
            triggerRef.current?.focus()
          }}
        />
      ) : null}

      <LiveStatus>{status}</LiveStatus>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Dialogs the menu opens
// ---------------------------------------------------------------------------

function ConfirmPurgeDialog({
  note,
  onClose,
  onDeleted,
}: {
  note: NoteSummary
  onClose: () => void
  onDeleted: () => void
}) {
  const trash = useTrashNote()
  const [error, setError] = useState<string | null>(null)

  return (
    <Dialog
      open
      onClose={onClose}
      title="Delete this note forever?"
      description={`“${note.display_title}” will be removed from every device. This cannot be undone.`}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            variant="danger"
            icon="trash"
            loading={trash.isPending}
            onClick={() => {
              setError(null)
              trash
                .mutateAsync({ id: note.id, permanent: true })
                .then(onDeleted)
                .catch((reason: unknown) => setError(describeError(reason)))
            }}
          >
            Delete forever
          </Button>
        </>
      }
    >
      {error ? (
        <p className="notes-notice notes-notice--danger" role="alert">
          <Icon name="alert" size={14} />
          {error}
        </p>
      ) : (
        <p className="info-empty">
          Everything in the note goes with it — attachments, comments and version history.
        </p>
      )}
    </Dialog>
  )
}

interface NotebookOption {
  id: string
  name: string
  depth: number
}

function flattenNotebooks(notebooks: Notebook[], depth = 0): NotebookOption[] {
  return notebooks.flatMap((notebook) => [
    { id: notebook.id, name: notebook.name, depth },
    ...flattenNotebooks(notebook.children ?? [], depth + 1),
  ])
}

function MoveToNotebookDialog({
  note,
  onClose,
  onMoved,
}: {
  note: NoteSummary
  onClose: () => void
  onMoved: () => void
}) {
  const update = useUpdateNote()
  const [error, setError] = useState<string | null>(null)

  const notebooks = useQuery<Notebook[], ApiError>({
    queryKey: queryKeys.notebooks,
    queryFn: () => api.get<Notebook[]>('/notebooks'),
    staleTime: 60_000,
  })

  const move = (notebookId: string | null) => {
    setError(null)
    update
      .mutateAsync({ id: note.id, notebook_id: notebookId })
      .then(onMoved)
      .catch((reason: unknown) => setError(describeError(reason)))
  }

  const options = flattenNotebooks(notebooks.data ?? [])

  return (
    <Dialog open onClose={onClose} title="Move to notebook">
      {error ? (
        <p className="notes-notice notes-notice--danger" role="alert">
          <Icon name="alert" size={14} />
          {error}
        </p>
      ) : null}

      {notebooks.isPending ? (
        <p className="info-empty">Loading notebooks…</p>
      ) : notebooks.isError ? (
        <p className="notes-notice notes-notice--danger" role="alert">
          <Icon name="alert" size={14} />
          {notebooks.error.message}
        </p>
      ) : (
        <ul className="picker-list">
          <li>
            <button
              type="button"
              className="picker-option"
              aria-pressed={note.notebook_id === null}
              disabled={update.isPending}
              onClick={() => move(null)}
            >
              <Icon name="note" size={15} />
              <span className="picker-option__label">No notebook</span>
            </button>
          </li>
          {options.map((option) => (
            <li key={option.id}>
              <button
                type="button"
                className="picker-option"
                aria-pressed={note.notebook_id === option.id}
                disabled={update.isPending}
                onClick={() => move(option.id)}
                style={{ paddingLeft: `calc(var(--notes-space-3) + ${option.depth} * var(--notes-space-4))` }}
              >
                <Icon name="notebook" size={15} />
                <span className="picker-option__label">{option.name}</span>
              </button>
            </li>
          ))}
          {options.length === 0 ? (
            <li>
              <p className="info-empty">You have no notebooks yet.</p>
            </li>
          ) : null}
        </ul>
      )}
    </Dialog>
  )
}

// ---------------------------------------------------------------------------
// The card
// ---------------------------------------------------------------------------

export interface NoteCardProps {
  note: NoteSummary
  /** In-app path this card opens. */
  to: string
  selected?: boolean
  /** Called when the note leaves the current list. */
  onRemoved?: () => void
}

export function NoteCard({ note, to, selected = false, onRemoved }: NoteCardProps) {
  const flag = useNoteFlag()
  const capabilities = capabilitiesForRole(note.role)
  const typeIcon = TYPE_ICON[note.note_type]
  const checklist = note.checklist
  const progress = checklist && checklist.total > 0 ? Math.round((checklist.done / checklist.total) * 100) : 0

  return (
    <article
      className={[
        'note-card',
        note.color ? `note-card--${note.color}` : '',
        selected ? 'note-card--selected' : '',
      ]
        .filter(Boolean)
        .join(' ')}
    >
      <div className="note-card__head">
        <h3 className="note-card__title">
          <Link className="note-card__link" to={to} aria-current={selected ? 'page' : undefined}>
            {note.display_title}
          </Link>
        </h3>

        {capabilities.edit && note.deleted_at === null ? (
          <button
            type="button"
            className={`note-card__pin ${note.is_pinned ? 'note-card__pin--active' : ''}`.trim()}
            aria-pressed={note.is_pinned}
            aria-label={note.is_pinned ? `Unpin ${note.display_title}` : `Pin ${note.display_title}`}
            onClick={() => flag.mutate({ id: note.id, action: note.is_pinned ? 'unpin' : 'pin' })}
          >
            <Icon name={note.is_pinned ? 'pin-filled' : 'pin'} size={15} />
          </button>
        ) : null}
      </div>

      {note.excerpt ? <p className="note-card__excerpt">{note.excerpt}</p> : null}

      {checklist && checklist.total > 0 ? (
        <div className="note-card__checklist">
          <div
            className="note-card__progress"
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={checklist.total}
            aria-valuenow={checklist.done}
            aria-label={`${checklist.done} of ${checklist.total} items done`}
          >
            <div className="note-card__progress-bar" style={{ width: `${progress}%` }} />
          </div>
          <span className="note-card__badge">
            {checklist.done}/{checklist.total} done
          </span>
        </div>
      ) : null}

      {note.tags.length > 0 ? (
        <div className="note-card__tags">
          {note.tags.slice(0, 3).map((tag) => (
            <span key={tag.id} className="note-tag">
              {tag.name}
            </span>
          ))}
          {note.tags.length > 3 ? <span className="note-tag">+{note.tags.length - 3}</span> : null}
        </div>
      ) : null}

      <div className="note-card__meta">
        {note.updated_at ? (
          <time className="note-card__meta-item" dateTime={note.updated_at} title={formatAbsoluteTime(note.updated_at)}>
            {formatRelativeTime(note.updated_at)}
          </time>
        ) : null}

        {typeIcon ? (
          <span className="note-card__meta-item">
            <Icon name={typeIcon} size={13} label={`${note.note_type} note`} />
          </span>
        ) : null}

        {note.attachment_count > 0 ? (
          <span className="note-card__meta-item">
            <Icon name="attach" size={13} />
            {note.attachment_count}
            <span className="sr-only"> attachments</span>
          </span>
        ) : null}

        {note.has_reminder ? (
          <span className="note-card__meta-item">
            <Icon name="bell" size={13} label="Has a reminder" />
          </span>
        ) : null}

        {note.is_shared ? (
          <span className="note-card__meta-item">
            <Icon name="shared" size={13} label="Shared" />
          </span>
        ) : null}

        {note.privacy_mode === 'private' || note.is_locked ? (
          <span className="note-card__meta-item">
            <Icon name="lock" size={13} label="Private" />
          </span>
        ) : null}

        <NoteMenu note={note} to={to} onRemoved={onRemoved} />
      </div>
    </article>
  )
}
