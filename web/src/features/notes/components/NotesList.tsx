/**
 * Notes as rows.
 *
 * Two densities from one component, because the difference between "list" and
 * "compact" is one line of body text and nothing else — a second component
 * would be the same file with a paragraph deleted.
 */

import { NoteListItem } from './NoteListItem'
import type { NoteSummary } from '../../../shared/api/types'

export interface NotesListProps {
  notes: NoteSummary[]
  hrefFor: (note: NoteSummary) => string
  label: string
  selectedId?: string | null
  compact?: boolean
  onRemoved?: (noteId: string) => void
}

export function NotesList({
  notes,
  hrefFor,
  label,
  selectedId = null,
  compact = false,
  onRemoved,
}: NotesListProps) {
  return (
    <ul className={`notes-list ${compact ? 'notes-list--compact' : ''}`.trim()} aria-label={label}>
      {notes.map((note) => (
        <li key={note.id}>
          <NoteListItem
            note={note}
            to={hrefFor(note)}
            selected={note.id === selectedId}
            compact={compact}
            onRemoved={() => onRemoved?.(note.id)}
          />
        </li>
      ))}
    </ul>
  )
}
