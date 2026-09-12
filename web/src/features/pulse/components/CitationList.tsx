/**
 * Where an answer came from.
 *
 * An assistant inside somebody's notes is only worth trusting if they can get
 * back to the note behind a sentence, so every citation is a real link to that
 * note rather than a prose "according to your notes". The snippet underneath is
 * the text the server actually put in front of the model — quoted from what was
 * sent, never from what came back — which is what lets a reader check the
 * answer against the source instead of taking its word for it.
 *
 * An answer with no citations does not render an empty list here. The panel
 * says so above the answer instead, because "no sources" is a fact about the
 * answer and not an empty section of it.
 */

import { Link } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import type { PulseCitation } from '../../../shared/api/types'
import '../pulse.css'

export interface CitationListProps {
  citations: PulseCitation[]
  /** Called before navigating — lets a panel inside a dialog close itself. */
  onNavigate?: (noteId: string) => void
}

export function CitationList({ citations, onNavigate }: CitationListProps) {
  if (citations.length === 0) return null

  return (
    <section className="pulse-sources" aria-label="Sources for this answer">
      <p className="pulse-sources__heading">
        {citations.length} {citations.length === 1 ? 'source' : 'sources'} in your notes
      </p>

      <ul className="pulse-sources__list">
        {citations.map((citation, index) => (
          // A note can be cited more than once, from different blocks, so the
          // key has to include where in the note it came from.
          <li className="pulse-source" key={`${citation.note_id}:${citation.block_id ?? index}`}>
            <Link
              className="pulse-source__pill"
              to={`/notes/${citation.note_id}`}
              onClick={() => onNavigate?.(citation.note_id)}
            >
              <Icon name="note" size={13} />
              <span className="pulse-source__title">{citation.title?.trim() || 'Untitled note'}</span>
              <Icon name="chevron-right" size={13} />
            </Link>

            {/* Outside the link on purpose: a two-line quotation inside the
                accessible name makes the link unusable to announce. */}
            {citation.snippet ? <p className="pulse-source__snippet">{citation.snippet}</p> : null}
          </li>
        ))}
      </ul>
    </section>
  )
}
