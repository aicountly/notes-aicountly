/**
 * What has happened to this note.
 *
 * The server stores action codes and ids — `member.role_changed`,
 * `{"member_user_id": "u_42", "role": "editor"}` — and never content, because
 * the trail is visible to every collaborator including the viewer added five
 * minutes ago. This file's whole job is turning those rows into sentences a
 * person reads without a legend, and it keeps the server's rule: nothing from
 * inside the note is rendered here, only who did what.
 *
 * Consecutive rows by the same person doing the same thing are collapsed, so
 * adding four people to a note reads as one line and not as four.
 */

import { useState } from 'react'

import { Button, Skeleton } from '../../../shared/ui/primitives'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { formatAbsoluteTime, formatRelativeTime } from '../../notes/components/NoteCard'
import { useMemberNames } from '../hooks/useMembers'
import { useNoteActivity } from '../hooks/useActivity'
import type { ActivityEntry, NoteRole } from '../../../shared/api/types'
import '../../organise/organise.css'
import '../collaboration.css'

/** How far apart two acts can be and still read as one. */
const GROUP_WINDOW_MS = 10 * 60_000

const ROLE_NOUN: Record<NoteRole, string> = {
  owner: 'the owner',
  editor: 'an editor',
  commenter: 'a commenter',
  viewer: 'a viewer',
}

export interface ActivityGroup {
  key: string
  actorUserId: string
  action: string
  /** How many rows this line stands for. */
  count: number
  /** When the most recent of them happened. */
  at: string
  /** The newest row's context; the others differ only in which id they name. */
  context: Record<string, unknown>
}

/**
 * Collapse a run of the same act by the same person.
 *
 * Entries arrive newest first, so a run is always adjacent. The server already
 * coalesces repeated edits into one row; this covers the acts it deliberately
 * does not coalesce — adding three people is three rows, and three rows is not
 * three sentences worth reading.
 */
export function groupActivity(entries: ActivityEntry[]): ActivityGroup[] {
  const groups: ActivityGroup[] = []

  for (const entry of entries) {
    const previous = groups[groups.length - 1]
    const withinWindow =
      previous !== undefined &&
      previous.actorUserId === entry.actor_user_id &&
      previous.action === entry.action &&
      Math.abs(Date.parse(previous.at) - Date.parse(entry.created_at)) <= GROUP_WINDOW_MS

    if (withinWindow) {
      previous.count += 1
      continue
    }

    groups.push({
      key: entry.id,
      actorUserId: entry.actor_user_id,
      action: entry.action,
      count: 1,
      at: entry.created_at,
      context: entry.context,
    })
  }

  return groups
}

function plural(count: number, one: string, many: string): string {
  return count === 1 ? one : `${count} ${many}`
}

/**
 * One group, as a sentence.
 *
 * Exported so the phrasing can be tested without rendering a panel, and so an
 * action this build has never heard of still says something — the raw code with
 * its punctuation removed, rather than a blank line.
 */
function roleNounFor(role: string | null): string | null {
  switch (role) {
    case 'owner':
    case 'editor':
    case 'commenter':
    case 'viewer':
      return ROLE_NOUN[role]
    default:
      return null
  }
}

export function describeActivity(
  group: ActivityGroup,
  nameFor: (userId: string) => string,
): string {
  const { action, count, context } = group

  const actor = nameFor(group.actorUserId)

  const contextString = (key: string): string | null => {
    const value = context[key]
    return typeof value === 'string' ? value : null
  }
  const contextNumber = (key: string): number | null => {
    const value = context[key]
    return typeof value === 'number' ? value : null
  }

  // "…with You" is not a sentence; as the object of one, it is lower case.
  const objectName = (userId: string | null): string => {
    if (userId === null) return 'someone'
    const name = nameFor(userId)
    return name === 'You' ? 'you' : name
  }

  const roleNoun = roleNounFor(contextString('role'))

  const phrase = ((): string => {
    switch (action) {
      case 'note.created': return 'created this note'
      case 'note.updated': return 'edited this note'
      case 'note.archived': return 'archived it'
      case 'note.unarchived': return 'took it out of the archive'
      case 'note.trashed': return 'moved it to Trash'
      case 'note.restored': return 'restored it from Trash'
      case 'note.deleted': return 'deleted it for good'
      case 'note.duplicated': return 'made a copy of it'
      case 'note.moved': return 'moved it to another notebook'

      case 'note.version_restored': {
        const version = contextNumber('revision_number')
        return version === null ? 'restored an earlier version' : `restored version ${version}`
      }

      case 'member.added':
        return count > 1
          ? `shared this note with ${count} people`
          : `shared this note with ${objectName(contextString('member_user_id'))}`

      case 'member.removed':
        return count > 1
          ? `removed ${count} people from this note`
          : `removed ${objectName(contextString('member_user_id'))} from this note`

      case 'member.role_changed':
        if (count > 1) return `changed access for ${count} people`
        return roleNoun === null
          ? `changed ${objectName(contextString('member_user_id'))}’s access`
          : `made ${objectName(contextString('member_user_id'))} ${roleNoun}`

      case 'comment.added': return count > 1 ? `added ${count} comments` : 'commented'
      case 'comment.updated': return count > 1 ? `edited ${count} comments` : 'edited a comment'
      case 'comment.resolved': return `resolved ${plural(count, 'a comment', 'comments')}`
      case 'comment.reopened': return `reopened ${plural(count, 'a comment', 'comments')}`
      case 'comment.deleted': return `deleted ${plural(count, 'a comment', 'comments')}`

      case 'attachment.added': return `attached ${plural(count, 'a file', 'files')}`
      case 'attachment.removed': return `removed ${plural(count, 'a file', 'files')}`
      case 'reminder.set': return `set ${plural(count, 'a reminder', 'reminders')}`
      case 'reminder.cleared': return `cleared ${plural(count, 'a reminder', 'reminders')}`

      // An action added to the server after this build shipped. Readable is
      // better than blank, and blank is what a lookup table returns.
      default: return action.replace(/[._]/g, ' ')
    }
  })()

  return `${actor} ${phrase}`
}

export interface ActivityFeedProps {
  noteId: string
  /** How many entries to fetch before the reader asks for more. */
  pageSize?: number
}

export function ActivityFeed({ noteId, pageSize = 25 }: ActivityFeedProps) {
  const [limit, setLimit] = useState(pageSize)
  const activity = useNoteActivity(noteId, limit)
  const nameFor = useMemberNames(noteId)

  return (
    <section className="collab-section">
      <h3 className="collab-section__title">Activity</h3>

      {activity.isPending ? (
        <div className="collab-skeletons" aria-hidden>
          {[0, 1, 2].map((row) => (
            <Skeleton key={row} width="80%" height={13} />
          ))}
        </div>
      ) : activity.isError ? (
        <ErrorNotice error={activity.error} onRetry={() => void activity.refetch()} />
      ) : activity.data.entries.length === 0 ? (
        <p className="org-empty">Nothing has happened to this note yet.</p>
      ) : (
        <>
          <ul className="collab-activity">
            {groupActivity(activity.data.entries).map((group) => (
              <li className="collab-activity__item" key={group.key}>
                <span className="collab-activity__text">{describeActivity(group, nameFor)}</span>
                <time
                  className="collab-activity__time"
                  dateTime={group.at}
                  title={formatAbsoluteTime(group.at)}
                >
                  {formatRelativeTime(group.at)}
                </time>
              </li>
            ))}
          </ul>

          {activity.data.hasMore ? (
            <div className="collab-actions">
              <Button
                size="sm"
                icon="chevron-down"
                loading={activity.isFetching}
                onClick={() => setLimit((current) => current + pageSize)}
              >
                Show earlier activity
              </Button>
            </div>
          ) : null}
        </>
      )}
    </section>
  )
}
