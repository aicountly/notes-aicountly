/**
 * Linked references.
 *
 * The half of linking that people actually navigate by: not "what does this
 * note point at", but "what points at this note". Incoming references are
 * grouped by the note they come from, because five mentions from one meeting
 * note are one place to go, not five rows.
 *
 * A link is stored as the target's id, so a renamed note keeps its backlinks
 * and this list never shows a title that has moved on. Both directions come
 * from one request — `GET /notes/{id}/links` — and both are filtered by what
 * the reader may open: a note linking here that they cannot see is not listed,
 * which is the same answer as it not existing.
 */

import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import { EmptyState, Skeleton } from '../../../shared/ui/primitives'
import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import { ErrorNotice } from '../../organise/ErrorNotice'
import type { BacklinkEntry, NoteLinks } from '../../../shared/api/types'
import '../../organise/organise.css'
import '../collaboration.css'

export function useNoteLinks(noteId: string | null) {
  return useQuery<NoteLinks, ApiError>({
    queryKey: queryKeys.notes.links(noteId ?? ''),
    enabled: noteId !== null,
    queryFn: () => api.get<NoteLinks>(`/notes/${noteId}/links`),
  })
}

interface LinkGroup {
  noteId: string
  title: string
  entries: BacklinkEntry[]
}

/**
 * One row per source note.
 *
 * The endpoint already returns a row per note, but grouping here rather than
 * trusting that keeps the panel correct if a future server returns one row per
 * mention — and it is what lets a note that references this one three times say
 * so instead of appearing three times.
 */
function groupByNote(entries: BacklinkEntry[]): LinkGroup[] {
  const groups = new Map<string, LinkGroup>()

  for (const entry of entries) {
    const existing = groups.get(entry.note_id)
    if (existing) {
      existing.entries.push(entry)
      continue
    }
    groups.set(entry.note_id, {
      noteId: entry.note_id,
      title: entry.title?.trim() || 'Untitled note',
      entries: [entry],
    })
  }

  return [...groups.values()]
}

export interface BacklinksPanelProps {
  noteId: string
}

export function BacklinksPanel({ noteId }: BacklinksPanelProps) {
  const links = useNoteLinks(noteId)

  if (links.isPending) {
    return (
      <section className="collab-section" aria-busy="true">
        <h3 className="collab-section__title">Linked references</h3>
        <div className="collab-skeletons" aria-hidden>
          {[0, 1].map((row) => (
            <div className="collab-skeleton-row" key={row}>
              <Skeleton width="60%" height={13} />
              <Skeleton width="85%" height={11} />
            </div>
          ))}
        </div>
      </section>
    )
  }

  if (links.isError) {
    return (
      <section className="collab-section">
        <h3 className="collab-section__title">Linked references</h3>
        <ErrorNotice error={links.error} onRetry={() => void links.refetch()} />
      </section>
    )
  }

  const incoming = groupByNote(links.data.incoming)
  const outgoing = groupByNote(links.data.outgoing)

  return (
    <>
      <section className="collab-section">
        <h3 className="collab-section__title">Linked references ({incoming.length})</h3>

        {incoming.length === 0 ? (
          <EmptyState
            icon="link"
            title="Nothing links here yet"
            description="In any note, type / and choose “Link to note”. A link reads as [[Note title]] when it is copied out, and every note you link to lists this one back here."
          />
        ) : (
          <ul className="collab-links">
            {incoming.map((group) => (
              <li key={group.noteId}>
                <Link className="collab-link-item" to={`/notes/${group.noteId}`}>
                  <span className="collab-link-item__title">
                    <Icon name="chevron-left" size={14} />
                    {group.title}
                  </span>
                  {group.entries[0].excerpt ? (
                    <span className="collab-link-item__excerpt">{group.entries[0].excerpt}</span>
                  ) : null}
                  {group.entries.length > 1 ? (
                    <span className="collab-link-item__mentions">
                      {group.entries.length} references
                    </span>
                  ) : null}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="collab-section">
        <h3 className="collab-section__title">Links from this note ({outgoing.length})</h3>

        {outgoing.length === 0 ? (
          <p className="org-empty">This note does not link to another note yet.</p>
        ) : (
          <ul className="collab-links">
            {outgoing.map((group) => (
              <li key={group.noteId}>
                <Link className="collab-link-item" to={`/notes/${group.noteId}`}>
                  <span className="collab-link-item__title">
                    <Icon name="link" size={14} />
                    {group.entries[0].label?.trim() || group.title}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  )
}
