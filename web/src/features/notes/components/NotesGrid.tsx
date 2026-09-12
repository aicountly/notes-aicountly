/**
 * Notes as a wall of cards.
 *
 * A list, semantically, even though it is laid out as a grid: a screen reader
 * announcing "list, 24 items" is telling the user something true and useful
 * that a bag of divs does not.
 */

import { NoteCard } from './NoteCard'
import type { NoteSummary } from '../../../shared/api/types'

export interface NotesGridProps {
  notes: NoteSummary[]
  /** Where each note opens. Scoped routes keep the list they came from. */
  hrefFor: (note: NoteSummary) => string
  label: string
  selectedId?: string | null
  onRemoved?: (noteId: string) => void
}

export function NotesGrid({ notes, hrefFor, label, selectedId = null, onRemoved }: NotesGridProps) {
  return (
    <ul className="notes-grid" aria-label={label}>
      {notes.map((note) => (
        <li key={note.id}>
          <NoteCard
            note={note}
            to={hrefFor(note)}
            selected={note.id === selectedId}
            onRemoved={() => onRemoved?.(note.id)}
          />
        </li>
      ))}
    </ul>
  )
}
