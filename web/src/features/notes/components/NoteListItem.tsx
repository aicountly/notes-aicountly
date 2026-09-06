/**
 * One note, as a row.
 *
 * The row is what the list and compact views are made of: the same note as the
 * card, reduced to what survives at one line — the kind of note it is, its
 * title, when it changed, and the same actions menu. Compact drops the excerpt
 * too, which is the point of it: forty notes on a screen instead of twelve.
 */

import { Link } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { useAppConfig } from '../../../app/AppConfigProvider'
import { NoteMenu, daysUntilPurge, formatAbsoluteTime, formatRelativeTime } from './NoteCard'
import type { NoteSummary } from '../../../shared/api/types'

const TYPE_ICON: Record<string, IconName> = {
  document: 'note',
  checklist: 'checklist',
  voice: 'mic',
  meeting: 'meeting',
  drawing: 'draw',
  canvas: 'draw',
  scan: 'scan',
}

export interface NoteListItemProps {
  note: NoteSummary
  /** In-app path this row opens. */
  to: string
  selected?: boolean
  /** Title and date only. */
  compact?: boolean
  onRemoved?: () => void
}

export function NoteListItem({ note, to, selected = false, compact = false, onRemoved }: NoteListItemProps) {
  const config = useAppConfig()

  return (
    <div className={`note-row ${selected ? 'note-row--selected' : ''}`.trim()}>
      <span className="note-row__leading">
        <Icon name={TYPE_ICON[note.note_type] ?? 'note'} size={16} />
      </span>

      <div className="note-row__body">
        <p className="note-row__title">
          <Link className="note-row__link" to={to} aria-current={selected ? 'page' : undefined}>
            {note.display_title}
          </Link>
        </p>
        {!compact && note.excerpt ? <p className="note-row__excerpt">{note.excerpt}</p> : null}
      </div>

      {note.is_pinned ? (
        <span className="note-card__meta-item">
          <Icon name="pin-filled" size={13} label="Pinned" />
        </span>
      ) : null}

      {note.checklist && note.checklist.total > 0 ? (
        <span className="note-card__badge">
          {note.checklist.done}/{note.checklist.total}
          <span className="sr-only"> checklist items done</span>
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

      {note.deleted_at ? (
        <span className="note-card__badge">
          {`${daysUntilPurge(note.deleted_at, config.limits.trash_retention_days)}d`}
          <span className="sr-only"> until this note is deleted for good</span>
        </span>
      ) : null}

      {note.updated_at ? (
        <time className="note-row__date" dateTime={note.updated_at} title={formatAbsoluteTime(note.updated_at)}>
          {formatRelativeTime(note.updated_at)}
        </time>
      ) : null}

      <NoteMenu note={note} to={to} onRemoved={onRemoved} />
    </div>
  )
}
