/**
 * Everything about a note that is not the note.
 *
 * Closed by default and opened from the editor header, because none of it is
 * needed while writing: dates, where it lives, how long it is, what it links
 * to, what links back, and who did what to it. Keeping it out of the editor
 * header is what leaves the header room for the four controls people use.
 *
 * Each section fetches its own data and each is allowed to be missing: a
 * deployment without the attachments endpoint should show a shorter panel, not
 * a broken one.
 */

import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'

import { Icon } from '../../../shared/ui/Icon'
import { Button, Skeleton } from '../../../shared/ui/primitives'
import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import { useAuth } from '../../../auth/AuthProvider'
import { formatAbsoluteTime, formatRelativeTime } from './NoteCard'
import type {
  ActivityEntry,
  Attachment,
  Note,
  NoteLinks,
  NoteRevision,
  Notebook,
} from '../../../shared/api/types'

/** Past-tense verbs, so an entry reads "You edited · 2 hours ago". */
const ACTIVITY_VERB: Record<string, string> = {
  'note.created': 'created this note',
  'note.updated': 'edited this note',
  'note.archived': 'archived it',
  'note.unarchived': 'took it out of the archive',
  'note.trashed': 'moved it to Trash',
  'note.restored': 'restored it',
  'note.duplicated': 'duplicated it',
  'note.moved': 'moved it to another notebook',
  'note.version_restored': 'restored an earlier version',
  'member.added': 'shared it with someone',
  'member.removed': 'removed someone',
  'member.role_changed': 'changed someone’s access',
  'comment.added': 'commented',
  'comment.resolved': 'resolved a comment',
  'attachment.added': 'attached a file',
  'attachment.removed': 'removed a file',
  'reminder.set': 'set a reminder',
  'reminder.cleared': 'cleared a reminder',
}

function flattenNotebooks(notebooks: Notebook[]): Notebook[] {
  return notebooks.flatMap((notebook) => [notebook, ...flattenNotebooks(notebook.children ?? [])])
}

export interface NoteInfoPanelProps {
  note: Note
  onClose: () => void
}

export function NoteInfoPanel({ note, onClose }: NoteInfoPanelProps) {
  const { profile } = useAuth()
  const noteId = note.id

  const links = useQuery<NoteLinks, ApiError>({
    queryKey: queryKeys.notes.links(noteId),
    queryFn: () => api.get<NoteLinks>(`/notes/${noteId}/links`),
  })

  const versions = useQuery<{ entries: NoteRevision[]; total: number }, ApiError>({
    queryKey: queryKeys.notes.versions(noteId),
    queryFn: async () => {
      const { data, meta } = await api.getWithMeta<NoteRevision[]>(`/notes/${noteId}/versions`, {
        query: { limit: 5 },
      })
      return { entries: data, total: typeof meta.total === 'number' ? meta.total : data.length }
    },
  })

  const activity = useQuery<ActivityEntry[], ApiError>({
    queryKey: queryKeys.notes.activity(noteId),
    queryFn: () => api.get<ActivityEntry[]>(`/notes/${noteId}/activity`, { query: { limit: 8 } }),
  })

  const attachments = useQuery<Attachment[], ApiError>({
    queryKey: queryKeys.notes.attachments(noteId),
    queryFn: () => api.get<Attachment[]>(`/notes/${noteId}/attachments`),
    enabled: note.attachment_count > 0,
  })

  const notebooks = useQuery<Notebook[], ApiError>({
    queryKey: queryKeys.notebooks,
    queryFn: () => api.get<Notebook[]>('/notebooks'),
    enabled: note.notebook_id !== null,
    staleTime: 60_000,
  })

  const notebook = note.notebook_id
    ? flattenNotebooks(notebooks.data ?? []).find((candidate) => candidate.id === note.notebook_id)
    : undefined

  const isOwner = profile !== null && profile.user_id === note.owner_user_id

  return (
    <aside className="side-panel" aria-label="Note information">
      <header className="side-panel__header">
        <Icon name="info" size={16} />
        <h2 className="side-panel__title">Note info</h2>
        <Button icon="close" iconOnly variant="ghost" size="sm" aria-label="Close note info" onClick={onClose} />
      </header>

      <div className="side-panel__body">
        <ul className="info-rows">
          <InfoRow label="Created">
            <time dateTime={note.created_at ?? undefined}>{formatAbsoluteTime(note.created_at) || 'Unknown'}</time>
          </InfoRow>
          <InfoRow label="Modified">
            <time dateTime={note.updated_at ?? undefined} title={formatAbsoluteTime(note.updated_at)}>
              {formatRelativeTime(note.updated_at) || 'Unknown'}
            </time>
          </InfoRow>
          <InfoRow label="Owner">{isOwner ? 'You' : 'Another member'}</InfoRow>
          <InfoRow label="Your access">{note.role}</InfoRow>
          <InfoRow label="Notebook">
            {note.notebook_id === null ? 'None' : (notebook?.name ?? 'A notebook you cannot open')}
          </InfoRow>
          <InfoRow label="Tags">
            {note.tags.length === 0 ? (
              'None'
            ) : (
              <span className="note-card__tags">
                {note.tags.map((tag) => (
                  <span key={tag.id} className="note-tag">
                    {tag.name}
                  </span>
                ))}
              </span>
            )}
          </InfoRow>
          <InfoRow label="Length">
            {note.word_count.toLocaleString()} words · {note.char_count.toLocaleString()} characters
          </InfoRow>
        </ul>

        <section className="info-section">
          <h3 className="info-section__title">Attachments ({note.attachment_count})</h3>
          {note.attachment_count === 0 ? (
            <p className="info-empty">Nothing attached.</p>
          ) : attachments.isPending ? (
            <Skeleton height={16} />
          ) : attachments.isError ? (
            <p className="info-empty">{attachments.error.message}</p>
          ) : (
            <ul className="info-list">
              {(attachments.data ?? []).map((attachment) => (
                <li key={attachment.id}>
                  <a className="info-link" href={attachment.content_url} target="_blank" rel="noreferrer">
                    <Icon name="attach" size={14} />
                    <span className="info-link__label">{attachment.filename}</span>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="info-section">
          <h3 className="info-section__title">Links</h3>
          {links.isPending ? (
            <Skeleton height={16} />
          ) : links.isError ? (
            <p className="info-empty">{links.error.message}</p>
          ) : links.data.outgoing.length === 0 ? (
            <p className="info-empty">This note does not link to another note yet.</p>
          ) : (
            <ul className="info-list">
              {links.data.outgoing.map((entry) => (
                <li key={`out-${entry.note_id}-${entry.block_id ?? ''}`}>
                  <Link className="info-link" to={`/notes/${entry.note_id}`}>
                    <Icon name="link" size={14} />
                    <span className="info-link__label">{entry.label ?? entry.title ?? 'Untitled note'}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="info-section">
          <h3 className="info-section__title">Backlinks</h3>
          {links.isPending ? (
            <Skeleton height={16} />
          ) : links.isError ? (
            <p className="info-empty">{links.error.message}</p>
          ) : links.data.incoming.length === 0 ? (
            <p className="info-empty">No other note points here.</p>
          ) : (
            <ul className="info-list">
              {links.data.incoming.map((entry) => (
                <li key={`in-${entry.note_id}-${entry.block_id ?? ''}`}>
                  <Link className="info-link" to={`/notes/${entry.note_id}`}>
                    <Icon name="chevron-left" size={14} />
                    <span className="info-link__label">{entry.title ?? 'Untitled note'}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="info-section">
          <h3 className="info-section__title">
            Version history{versions.data ? ` (${versions.data.total})` : ''}
          </h3>
          {versions.isPending ? (
            <Skeleton height={16} />
          ) : versions.isError ? (
            <p className="info-empty">{versions.error.message}</p>
          ) : versions.data.entries.length === 0 ? (
            <p className="info-empty">No earlier versions yet — they appear as the note is edited.</p>
          ) : (
            <ul className="info-list">
              {versions.data.entries.map((revision) => (
                <li key={revision.id} className="info-entry">
                  <span className="info-entry__label">
                    Version {revision.revision_number}
                    {revision.reason === 'autosave' ? '' : ` · ${revision.reason}`}
                  </span>
                  <time className="info-entry__time" dateTime={revision.created_at}>
                    {formatRelativeTime(revision.created_at)}
                  </time>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="info-section">
          <h3 className="info-section__title">Activity</h3>
          {activity.isPending ? (
            <Skeleton height={16} />
          ) : activity.isError ? (
            <p className="info-empty">{activity.error.message}</p>
          ) : activity.data.length === 0 ? (
            <p className="info-empty">Nothing has happened to this note yet.</p>
          ) : (
            <ul className="info-list">
              {activity.data.map((entry) => (
                <li key={entry.id} className="info-entry">
                  <span className="info-entry__label">
                    {profile !== null && entry.actor_user_id === profile.user_id ? 'You ' : 'Someone '}
                    {ACTIVITY_VERB[entry.action] ?? entry.action.replace(/[._]/g, ' ')}
                  </span>
                  <time className="info-entry__time" dateTime={entry.created_at}>
                    {formatRelativeTime(entry.created_at)}
                  </time>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </aside>
  )
}

function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <li className="info-row">
      <span className="info-row__label">{label}</span>
      <span className="info-row__value">{children}</span>
    </li>
  )
}
