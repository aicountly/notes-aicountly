/**
 * Home.
 *
 * A place to land, not a dashboard to administer. It answers three questions —
 * what do I want to write down, what is due, and what was I in the middle of —
 * and then stops.
 *
 * The rule that keeps it calm: **a section with nothing in it is not rendered**.
 * No empty "Pinned" box, no "0 reminders". A new account sees a greeting, the
 * composer and one empty state; an account with a thousand notes sees the same
 * page with things in it.
 */

import { useRef } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import { Button, EmptyState, Skeleton } from '../../../shared/ui/primitives'
import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import { useAuth } from '../../../auth/AuthProvider'
import { QuickCapture } from '../components/QuickCapture'
import { NotesGrid } from '../components/NotesGrid'
import { NotesList } from '../components/NotesList'
import { useNoteList } from '../hooks/useNotes'
import type { NoteAction, NoteSummary, Reminder } from '../../../shared/api/types'
import '../notes.css'

const DATE_FORMAT = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
const TIME_FORMAT = new Intl.DateTimeFormat(undefined, { timeStyle: 'short' })

function greetingFor(hour: number): string {
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
}

function endOfToday(): number {
  const end = new Date()
  end.setHours(23, 59, 59, 999)
  return end.getTime()
}

interface TodayItem {
  key: string
  noteId: string
  label: string
  due: number
  icon: 'bell' | 'checklist'
}

export function HomePage() {
  const { profile } = useAuth()
  const composerRef = useRef<HTMLTextAreaElement>(null)

  const pinned = useNoteList({ pinned: true, sort: 'updated_desc', limit: 6 })
  const recent = useNoteList({ pinned: false, sort: 'updated_desc', limit: 12 })
  const shared = useNoteList({ shared_with_me: true, sort: 'updated_desc', limit: 5 })

  // Today is assembled from two supporting endpoints. Neither is load-bearing:
  // if a deployment cannot answer them, Home is one section shorter rather
  // than an error page about a reminder.
  const reminders = useQuery<Reminder[], ApiError>({
    queryKey: queryKeys.reminders,
    queryFn: () => api.get<Reminder[]>('/reminders'),
    retry: false,
  })
  const actions = useQuery<NoteAction[], ApiError>({
    queryKey: queryKeys.openActions,
    queryFn: () => api.get<NoteAction[]>('/actions', { query: { limit: 50 } }),
    retry: false,
  })

  const cutoff = endOfToday()

  const today: TodayItem[] = [
    ...(reminders.data ?? [])
      .filter((reminder) => reminder.status === 'scheduled' || reminder.status === 'snoozed')
      .map((reminder) => ({
        key: `reminder-${reminder.id}`,
        noteId: reminder.note_id,
        label: reminder.note_title ?? 'A note',
        due: Date.parse(reminder.snoozed_until ?? reminder.due_at),
        icon: 'bell' as const,
      })),
    ...(actions.data ?? [])
      .filter((action) => action.status === 'open' && action.due_at !== null)
      .map((action) => ({
        key: `action-${action.id}`,
        noteId: action.note_id,
        label: action.text,
        due: Date.parse(action.due_at ?? ''),
        icon: 'checklist' as const,
      })),
  ]
    .filter((item) => !Number.isNaN(item.due) && item.due <= cutoff)
    .sort((a, b) => a.due - b.due)
    .slice(0, 6)

  // Any of the three failing leaves a section silently missing, so the first
  // failure is reported once and "Try again" refetches all of them.
  const listError = recent.error ?? pinned.error ?? shared.error

  const continueEditing = recent.data?.notes.slice(0, 3) ?? []
  const olderNotes = recent.data?.notes.slice(3) ?? []
  const pinnedNotes = pinned.data?.notes ?? []
  const sharedNotes = shared.data?.notes ?? []

  const hrefFor = (note: NoteSummary) => `/notes/${note.id}`
  // Only once every section has answered: an empty state that appears for a
  // moment and is then replaced by content is worse than a slower page.
  const nothingAtAll =
    !recent.isPending &&
    !pinned.isPending &&
    !shared.isPending &&
    listError === null &&
    pinnedNotes.length === 0 &&
    continueEditing.length === 0 &&
    sharedNotes.length === 0 &&
    today.length === 0

  return (
    <div className="home">
      <div className="home__inner">
        <header className="home__header">
          <div className="home__header-text">
            <h1 className="home__greeting">
              {greetingFor(new Date().getHours())}
              {profile?.display_name ? `, ${profile.display_name.trim().split(/\s+/)[0]}` : ''}
            </h1>
            <p className="home__subtitle">{DATE_FORMAT.format(new Date())}</p>
          </div>
        </header>

        <QuickCapture inputRef={composerRef} />

        {listError ? (
          <div className="notes-error" role="alert">
            <Icon name={listError.isOffline ? 'cloud-off' : 'alert'} size={24} />
            <p className="notes-error__message">{listError.message}</p>
            <Button
              icon="refresh"
              onClick={() => {
                void recent.refetch()
                void pinned.refetch()
                void shared.refetch()
              }}
            >
              Try again
            </Button>
          </div>
        ) : null}

        {recent.data?.fromCache ? (
          <p className="notes-notice">
            <Icon name="cloud-off" size={14} />
            You are offline. These are the notes saved on this device.
          </p>
        ) : null}

        {recent.isPending ? (
          <section className="home-section" aria-busy>
            <h2 className="home-section__title">Loading your notes</h2>
            <div className="notes-grid">
              {[0, 1, 2].map((index) => (
                <div key={index} className="note-skeleton">
                  <Skeleton width="70%" height={15} />
                  <Skeleton width="100%" height={12} />
                  <Skeleton width="85%" height={12} />
                </div>
              ))}
            </div>
          </section>
        ) : null}

        {nothingAtAll ? (
          <EmptyState
            icon="note"
            title="Capture your first thought"
            description="Anything you type above becomes a note. It saves as you write, on this device and everywhere else."
            action={
              <Button variant="primary" icon="plus" onClick={() => composerRef.current?.focus()}>
                Take a note
              </Button>
            }
          />
        ) : null}

        {today.length > 0 ? (
          <HomeSection title="Today" link="/reminders" linkLabel="All reminders">
            <ul className="today-list">
              {today.map((item) => (
                <li key={item.key}>
                  <Link className="today-item" to={`/notes/${item.noteId}`}>
                    <span className="today-item__icon">
                      <Icon name={item.icon} size={15} />
                    </span>
                    <span className="today-item__label">{item.label}</span>
                    <span
                      className={`today-item__when ${item.due < Date.now() ? 'today-item__when--overdue' : ''}`.trim()}
                    >
                      {item.due < Date.now() ? 'Overdue' : TIME_FORMAT.format(item.due)}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </HomeSection>
        ) : null}

        {pinnedNotes.length > 0 ? (
          <HomeSection title="Pinned" link="/notes" linkLabel="All notes">
            <NotesGrid notes={pinnedNotes} hrefFor={hrefFor} label="Pinned notes" />
          </HomeSection>
        ) : null}

        {continueEditing.length > 0 ? (
          <HomeSection title="Continue editing">
            <NotesGrid notes={continueEditing} hrefFor={hrefFor} label="Notes you edited most recently" />
          </HomeSection>
        ) : null}

        {olderNotes.length > 0 ? (
          <HomeSection title="Recent" link="/notes" linkLabel="All notes">
            <NotesList notes={olderNotes} hrefFor={hrefFor} label="Recent notes" compact />
          </HomeSection>
        ) : null}

        {sharedNotes.length > 0 ? (
          <HomeSection title="Shared with me" link="/shared" linkLabel="See all">
            <NotesList notes={sharedNotes} hrefFor={hrefFor} label="Notes shared with you" compact />
          </HomeSection>
        ) : null}
      </div>
    </div>
  )
}

function HomeSection({
  title,
  link,
  linkLabel,
  children,
}: {
  title: string
  link?: string
  linkLabel?: string
  children: ReactNode
}) {
  return (
    <section className="home-section">
      <div className="home-section__header">
        <h2 className="home-section__title">{title}</h2>
        {link ? (
          <Link className="home-section__link" to={link}>
            {linkLabel ?? 'See all'}
          </Link>
        ) : null}
      </div>
      {children}
    </section>
  )
}
