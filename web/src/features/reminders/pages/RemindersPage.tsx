/**
 * Everything the user has asked to be reminded about, in the order it will
 * happen.
 *
 * The page is a triage queue, not a calendar. It answers "what is late", "what
 * is today" and "what is coming", in that order, because that is the order a
 * person deals with them in — and it keeps the finished ones underneath rather
 * than throwing them away, so ticking something off produces visible movement
 * instead of a row that silently disappears.
 *
 * Two things it is careful about:
 *
 *   - **A repeating reminder does not vanish when it is completed.** The server
 *     advances it to its next occurrence and answers with the updated row, so
 *     the row stays, moves to its new time, and says what happened. Removing it
 *     and letting the refetch put it back is how people stop trusting a
 *     repeating reminder.
 *   - **A control that would be refused is not shown.** Snoozing or completing
 *     a finished reminder is a 422 from `ReminderService::requireOpen`, so a
 *     completed row offers rescheduling and deletion and nothing else.
 *
 * Reminders are made *on a note*, so there is no "new reminder" button here:
 * there would be nothing to attach it to. The empty state says so and points at
 * the notes.
 */

import { useEffect, useId, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import { ApiError } from '../../../shared/api/client'
import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, EmptyState, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { describeRule } from '../recurrence'
import { describeTimezone, formatWhen, fromInputs, toDateInput, toTimeInput, PRESETS } from '../schedule'
import { ReminderDialog } from '../components/ReminderDialog'
import {
  SNOOZE_CHOICES,
  groupReminders,
  isOpen,
  repeats,
  useCompleteReminder,
  useDeleteReminder,
  useReminders,
  useSnoozeReminder,
} from '../hooks/useReminders'
import type { ReminderRow, ReminderScope, SnoozeInput } from '../hooks/useReminders'
import '../reminders.css'

/**
 * A refusal, said the way the server said it.
 *
 * A 422's own message is the generic "Some fields need attention." — the
 * sentence the user can act on ("Snooze by 1 to 43200 minutes", "A completed
 * reminder cannot be snoozed") is in `details.fields` beside it. Without this
 * every rejected snooze or completion reads as the same blank shrug. The code
 * is carried over so the notice still knows an offline error when it sees one.
 */
function readable(error: unknown): unknown {
  if (!(error instanceof ApiError)) return error

  const fields = Object.values(error.fieldErrors)

  return fields.length === 0 ? error : new ApiError(error.code, fields.join(' '), error.status, error.details)
}

export default function RemindersPage() {
  const [scope, setScope] = useState<ReminderScope>('all')
  const reminders = useReminders(scope)

  const [editing, setEditing] = useState<ReminderRow | null>(null)
  /**
   * What just happened. Always announced; shown on the row it happened to when
   * there still is one — a deletion has no row left to write on, so it carries
   * a null id and is heard rather than seen.
   */
  const [outcome, setOutcome] = useState<{ id: string | null; message: string } | null>(null)
  const [actionError, setActionError] = useState<unknown>(null)

  const complete = useCompleteReminder()
  const snooze = useSnoozeReminder()
  const remove = useDeleteReminder()

  const navigate = useNavigate()
  const rows = reminders.data ?? []
  const groups = groupReminders(rows)

  /**
   * Which row is being changed right now.
   *
   * Read from the mutation that is actually running: a settled mutation keeps
   * its `variables`, so taking the first one that has any would put the busy
   * state on whichever row was touched *last* rather than the one being
   * touched now — and then disable it.
   */
  const busyId = complete.isPending
    ? complete.variables
    : snooze.isPending
      ? snooze.variables?.id
      : remove.isPending
        ? remove.variables
        : undefined

  const fail = (error: unknown) => setActionError(readable(error))

  const onComplete = (reminder: ReminderRow) => {
    setActionError(null)
    complete
      .mutateAsync(reminder.id)
      .then((saved) => {
        // The server answers 200 with the reminder either way. Still open means
        // the rule had another occurrence in it and the row moved rather than
        // finished — which is worth saying, because the row is still there.
        setOutcome({
          id: reminder.id,
          message: isOpen(saved)
            ? `Done. It repeats, so the next one is ${formatWhen(saved.due_effective_at)}.`
            : 'Done.',
        })
      })
      .catch(fail)
  }

  const onSnooze = (reminder: ReminderRow, input: SnoozeInput) => {
    setActionError(null)
    snooze
      .mutateAsync({ id: reminder.id, input })
      .then((saved) => {
        setOutcome({ id: reminder.id, message: `Snoozed — ${formatWhen(saved.due_effective_at)}.` })
      })
      .catch(fail)
  }

  const onDelete = (reminder: ReminderRow) => {
    setActionError(null)
    setOutcome(null)
    remove
      .mutateAsync(reminder.id)
      // The row goes; without this the only feedback is a gap in a list nobody
      // was looking at, and a screen reader hears nothing at all.
      .then(() => setOutcome({ id: null, message: `Deleted the reminder for ${reminder.note_display_title}.` }))
      .catch(fail)
  }

  return (
    <div className="reminders">
      <div className="reminders__inner">
        <header className="reminders__header">
          <div>
            <h1 className="reminders__title">Reminders</h1>
            <p className="reminders__subtitle">
              Times are shown in {describeTimezone()}, this device’s time zone.
            </p>
          </div>

          <div className="reminders__filters" role="group" aria-label="Which reminders to show">
            {(
              [
                { value: 'all', label: 'All' },
                { value: 'open', label: 'Still to do' },
              ] as const
            ).map((option) => (
              <button
                key={option.value}
                type="button"
                className="reminders__filter"
                aria-pressed={scope === option.value}
                onClick={() => setScope(option.value)}
              >
                {option.label}
              </button>
            ))}
          </div>
        </header>

        {actionError ? <ErrorNotice error={actionError} /> : null}

        {reminders.isError ? (
          <ErrorNotice error={reminders.error} onRetry={() => void reminders.refetch()} />
        ) : null}

        {reminders.isPending ? <RemindersSkeleton /> : null}

        {!reminders.isPending && !reminders.isError && rows.length === 0 ? (
          scope === 'open' ? (
            <EmptyState
              icon="check"
              title="Nothing left to do"
              description="Every reminder you have set has been dealt with."
              action={
                <Button icon="history" onClick={() => setScope('all')}>
                  Show the ones you have finished
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon="bell"
              title="No reminders yet"
              description="A reminder is set on a note, so it can take you back to what it was about. Open a note and pick a time."
              action={
                <Button variant="primary" icon="note" onClick={() => navigate('/notes')}>
                  Go to your notes
                </Button>
              }
            />
          )
        ) : null}

        {groups.map((group) => (
          <section className="reminder-group" key={group.key} aria-labelledby={`reminder-group-${group.key}`}>
            <h2 className="reminder-group__title" id={`reminder-group-${group.key}`}>
              {group.title}
              <span className="reminder-group__count">{group.reminders.length}</span>
            </h2>

            <ul className="reminder-list">
              {group.reminders.map((reminder) => (
                <ReminderRowView
                  key={reminder.id}
                  reminder={reminder}
                  overdue={group.key === 'overdue'}
                  outcome={outcome?.id === reminder.id ? outcome.message : null}
                  // Only the row being changed goes quiet. Disabling every row
                  // while one of them saves makes the whole page dead for the
                  // length of a request, and takes focus off whatever the user
                  // was on when it does.
                  busy={busyId === reminder.id}
                  onComplete={() => onComplete(reminder)}
                  onSnooze={(input) => onSnooze(reminder, input)}
                  onEdit={() => {
                    setOutcome(null)
                    setEditing(reminder)
                  }}
                  onDelete={() => onDelete(reminder)}
                />
              ))}
            </ul>
          </section>
        ))}
      </div>

      <LiveStatus>{outcome?.message ?? ''}</LiveStatus>

      <ReminderDialog
        open={editing !== null}
        reminder={editing}
        noteTitle={editing?.note_display_title ?? null}
        onClose={() => setEditing(null)}
        onSaved={(saved) => setOutcome({ id: saved.id, message: `Moved to ${formatWhen(saved.due_effective_at)}.` })}
      />
    </div>
  )
}

// ---------------------------------------------------------------------------
// One reminder
// ---------------------------------------------------------------------------

function ReminderRowView({
  reminder,
  overdue,
  outcome,
  busy,
  onComplete,
  onSnooze,
  onEdit,
  onDelete,
}: {
  reminder: ReminderRow
  overdue: boolean
  outcome: string | null
  /** This reminder has a change in flight. Other rows stay usable. */
  busy: boolean
  onComplete: () => void
  onSnooze: (input: SnoozeInput) => void
  onEdit: () => void
  onDelete: () => void
}) {
  const title = reminder.note_display_title
  const open = isOpen(reminder)
  const recurring = repeats(reminder)
  const rule = describeRule(reminder.recurrence_rule)

  return (
    <li className={`reminder ${overdue ? 'reminder--overdue' : ''} ${open ? '' : 'reminder--done'}`.trim()}>
      {open ? (
        <button
          type="button"
          className="reminder__complete"
          disabled={busy}
          aria-busy={busy || undefined}
          // The recurring case is spelled out rather than left to be
          // discovered: pressing this does not make the reminder go away.
          aria-label={
            recurring
              ? `Complete this occurrence of the reminder for ${title}. It repeats, so it moves to its next time.`
              : `Complete the reminder for ${title}`
          }
          onClick={onComplete}
        >
          <Icon name="check" size={15} />
        </button>
      ) : (
        <span className="reminder__done-mark" aria-hidden>
          <Icon name={reminder.status === 'cancelled' ? 'close' : 'check'} size={15} />
        </span>
      )}

      <div className="reminder__body">
        <Link className="reminder__note" to={`/notes/${reminder.note_id}`}>
          {title}
        </Link>

        <p className="reminder__meta">
          <span className="reminder__when">{formatWhen(reminder.due_effective_at)}</span>

          {overdue ? (
            <Badge tone="danger">
              <Icon name="alert" size={11} /> Overdue
            </Badge>
          ) : null}

          {reminder.status === 'snoozed' ? (
            <Badge tone="warning">
              <Icon name="history" size={11} /> Snoozed
            </Badge>
          ) : null}

          {rule ? (
            <span className="reminder__repeat">
              <Icon name="refresh" size={12} />
              {rule}
            </span>
          ) : null}
        </p>

        {outcome ? (
          <p className="reminder__outcome">
            <Icon name="check" size={12} />
            {outcome}
          </p>
        ) : null}
      </div>

      <div className="reminder__actions">
        {open ? (
          <>
            <SnoozeMenu title={title} busy={busy} onSnooze={onSnooze} />
            <Button
              icon="draw"
              iconOnly
              size="sm"
              variant="ghost"
              aria-label={`Change when to be reminded about ${title}`}
              disabled={busy}
              onClick={onEdit}
            />
          </>
        ) : (
          <Button
            icon="history"
            size="sm"
            variant="ghost"
            aria-label={`Set a new time for the reminder about ${title}`}
            disabled={busy}
            onClick={onEdit}
          >
            Reschedule
          </Button>
        )}

        <Button
          icon="trash"
          iconOnly
          size="sm"
          variant="ghost"
          aria-label={`Delete the reminder for ${title}`}
          disabled={busy}
          onClick={onDelete}
        />
      </div>
    </li>
  )
}

// ---------------------------------------------------------------------------
// Snooze
// ---------------------------------------------------------------------------

/**
 * Push a reminder out, by one of three common amounts or to a time of the
 * user's choosing.
 *
 * The first two are sent as a number of minutes rather than as an instant, so
 * the server counts from its own clock: "15 minutes" on a device whose time is
 * ten minutes fast must not mean five.
 *
 * A popup rather than a menu, because the fourth choice is a small form and a
 * `role="menu"` with text inputs in it is a menu screen readers cannot describe.
 * It still behaves: focus moves in on open, and every way out of it — Escape,
 * choosing one of the three, or setting a time — closes the popup and puts
 * focus back on the trigger rather than dropping it on the body. A press
 * anywhere outside dismisses it, and focus follows the press.
 *
 * The trigger is never disabled, only marked busy: it is where focus has to
 * land when the popup closes, and a button that is disabled at that moment is
 * a button focus falls straight off.
 */
function SnoozeMenu({
  title,
  busy,
  onSnooze,
}: {
  title: string
  busy: boolean
  onSnooze: (input: SnoozeInput) => void
}) {
  const [open, setOpen] = useState(false)
  const [date, setDate] = useState('')
  const [time, setTime] = useState('')
  const [error, setError] = useState<string | null>(null)

  const containerRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const popupRef = useRef<HTMLDivElement>(null)
  const dateId = useId()
  const timeId = useId()

  // Capture phase, so opening another row's snooze closes this one first
  // rather than leaving two popups on screen.
  useEffect(() => {
    if (!open) return undefined

    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('pointerdown', onPointerDown, true)
    return () => document.removeEventListener('pointerdown', onPointerDown, true)
  }, [open])

  useEffect(() => {
    if (open) popupRef.current?.querySelector<HTMLButtonElement>('button')?.focus()
  }, [open])

  const close = () => {
    setOpen(false)
    triggerRef.current?.focus()
  }

  const openMenu = () => {
    // Seeded with "later today", the same idea as the dialog's first preset, so
    // the form opens on a sensible answer rather than an empty pair of fields.
    const later = PRESETS[0].at(new Date())
    setDate(toDateInput(later))
    setTime(toTimeInput(later))
    setError(null)
    setOpen(true)
  }

  const snoozeUntilChosen = () => {
    const until = fromInputs(date, time)
    if (until === null) {
      setError('Choose a date and a time.')
      return
    }
    // The server refuses a snooze into the past; saying so here means the user
    // is told by the control they used rather than by a banner after a round
    // trip.
    if (until.getTime() <= Date.now()) {
      setError('Snoozing moves a reminder forwards — pick a time still to come.')
      return
    }
    close()
    onSnooze({ until: until.toISOString() })
  }

  return (
    <div
      className="snooze"
      ref={containerRef}
      onKeyDown={(event) => {
        if (event.key === 'Escape' && open) {
          event.stopPropagation()
          close()
        }
      }}
    >
      <button
        ref={triggerRef}
        type="button"
        className="btn btn--ghost btn--sm"
        aria-busy={busy || undefined}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={`Snooze the reminder for ${title}`}
        onClick={() => (open ? close() : openMenu())}
      >
        <Icon name="history" size={15} />
        <span>Snooze</span>
      </button>

      {open ? (
        <div
          className="snooze__popup"
          role="dialog"
          aria-label={`Snooze the reminder for ${title}`}
          ref={popupRef}
        >
          {SNOOZE_CHOICES.map((choice) => (
            <button
              key={choice.key}
              type="button"
              className="snooze__option"
              onClick={() => {
                close()
                onSnooze(choice.input(new Date()))
              }}
            >
              {choice.label}
            </button>
          ))}

          <hr className="snooze__separator" />

          <div className="snooze__custom">
            <div className="org-field">
              <label className="org-label" htmlFor={dateId}>
                Date
              </label>
              <input
                id={dateId}
                className="org-input"
                type="date"
                value={date}
                onChange={(event) => setDate(event.target.value)}
              />
            </div>
            <div className="org-field">
              <label className="org-label" htmlFor={timeId}>
                Time
              </label>
              <input
                id={timeId}
                className="org-input"
                type="time"
                value={time}
                onChange={(event) => setTime(event.target.value)}
              />
            </div>
            <Button size="sm" variant="primary" onClick={snoozeUntilChosen}>
              Snooze until then
            </Button>
          </div>

          {error ? (
            <p className="org-error" role="alert">
              {error}
            </p>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------------

/** Shaped like two groups of reminders, so nothing moves when they arrive. */
function RemindersSkeleton() {
  return (
    <div aria-busy="true">
      <LiveStatus>Loading your reminders</LiveStatus>
      {[0, 1].map((group) => (
        <section className="reminder-group" key={group}>
          <p className="reminder-group__title">
            <Skeleton width={90} height={16} />
          </p>
          <ul className="reminder-list">
            {[0, 1, 2].map((row) => (
              <li className="reminder reminder--loading" key={row}>
                <Skeleton width={26} height={26} radius={999} />
                <div className="reminder__body">
                  <Skeleton width="42%" height={14} />
                  <Skeleton width="28%" height={12} />
                </div>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  )
}
