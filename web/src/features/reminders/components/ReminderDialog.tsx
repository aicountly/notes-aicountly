/**
 * Setting, and changing, when a reminder fires.
 *
 * The dialog is built around one constraint: **the server's RRULE subset is the
 * whole vocabulary**. `RecurrenceRule::parse()` accepts `FREQ`, `INTERVAL`,
 * `BYDAY`, `COUNT` and `UNTIL` and rejects everything else at write time rather
 * than dropping it, so a control for anything outside that list would compose a
 * 422 the user cannot act on. Every field below maps to one of those five.
 *
 * Three decisions are worth the ink:
 *
 *   - **The time zone is stated, not assumed.** A reminder is the one thing
 *     here where the user's clock and the server's instant have to agree out
 *     loud, so the zone the rule is anchored to is written under the inputs —
 *     and when the reminder was made somewhere else, both zones are shown with
 *     a way to re-home it.
 *   - **The summary is generated from the same state as the rule.** "Every 2
 *     weeks on Mon, Wed, until 3 Nov" is the only thing most people read before
 *     saving; deriving it from the rule string separately is how a summary ends
 *     up disagreeing with what was sent.
 *   - **A rule this builder cannot show is not rewritten.** A monthly
 *     `BYDAY=2TU` reminder made in another calendar keeps its rule untouched
 *     unless the user deliberately replaces it. Silently flattening it to
 *     "monthly" would change what the reminder does without saying so.
 */

import { useCallback, useEffect, useId, useRef, useState } from 'react'

import { ApiError } from '../../../shared/api/client'
import { Icon } from '../../../shared/ui/Icon'
import { Button, Dialog } from '../../../shared/ui/primitives'
import { ErrorNotice } from '../../organise/ErrorNotice'
import {
  DEFAULT_RECURRENCE,
  FREQUENCIES,
  FREQUENCY_LABEL,
  MAX_COUNT,
  MAX_INTERVAL,
  WEEKDAYS,
  buildRule,
  describeRecurrence,
  parseRule,
  supportsWeekdays,
  validateRecurrence,
} from '../recurrence'
import type { EndCondition, Frequency, Recurrence, WeekdayToken } from '../recurrence'
import {
  PRESETS,
  currentTimezone,
  describeTimezone,
  formatWhen,
  fromInputs,
  toDateInput,
  toTimeInput,
} from '../schedule'
import { useCreateReminder, useUpdateReminder } from '../hooks/useReminders'
import type { ReminderInput, ReminderRecord } from '../hooks/useReminders'
import '../reminders.css'

export interface ReminderDialogProps {
  open: boolean
  /** The note a new reminder hangs off. Required unless `reminder` is given. */
  noteId?: string
  /** The reminder being changed. Absent when creating. */
  reminder?: ReminderRecord | null
  /** Named in the description, so it is clear which note this is about. */
  noteTitle?: string | null
  onClose: () => void
  onSaved?: (reminder: ReminderRecord, wasCreated: boolean) => void
}

/**
 * The unit a repeat is counted in, for the "Every N ___" control.
 *
 * Plural only — the field holds a number, and "every 1 weeks" reads worse than
 * a label that never changes as you type.
 */
const INTERVAL_UNIT: Record<Frequency, string> = {
  DAILY: 'days',
  WEEKLY: 'weeks',
  MONTHLY: 'months',
  YEARLY: 'years',
}

/** What "after a number of times" starts at. Ten is a quarter's worth of weeks. */
const DEFAULT_COUNT = 10

/**
 * The 422 fields this form shows beside the control they belong to.
 *
 * Everything else the server can reject on — `note_id` when a note has hit its
 * 25 reminders, `timezone`, `status`, `action_id` — has no control here, and a
 * 422 says only "Some fields need attention." in its own message. Anything not
 * in this set is printed as-is, because the alternative is a Save button that
 * does nothing and says nothing.
 */
const INLINE_FIELDS = new Set(['due_at', 'recurrence_rule'])

/** Tomorrow at nine: always in the future, whatever time it is now. */
function defaultWhen(now: Date = new Date()): Date {
  const date = new Date(now)
  date.setDate(date.getDate() + 1)
  date.setHours(9, 0, 0, 0)
  return date
}

export function ReminderDialog({
  open,
  noteId,
  reminder = null,
  noteTitle = null,
  onClose,
  onSaved,
}: ReminderDialogProps) {
  const create = useCreateReminder()
  const update = useUpdateReminder()

  const dateId = useId()
  const timeId = useId()
  const repeatId = useId()
  const intervalId = useId()
  const neverId = useId()
  const countRadioId = useId()
  const countId = useId()
  const untilRadioId = useId()
  const untilId = useId()
  const endName = useId()
  const summaryId = useId()

  const [date, setDate] = useState('')
  const [time, setTime] = useState('')
  /** null means "does not repeat" — the absence of a rule, not a rule of none. */
  const [recurrence, setRecurrence] = useState<Recurrence | null>(null)
  /** A stored rule this builder has no controls for. Kept verbatim until replaced. */
  const [customRule, setCustomRule] = useState<string | null>(null)
  const [adoptZone, setAdoptZone] = useState(false)
  const [error, setError] = useState<unknown>(null)

  useEffect(() => {
    if (!open) return

    const when = reminder ? new Date(reminder.due_at) : defaultWhen()
    setDate(toDateInput(when))
    setTime(toTimeInput(when))

    const parsed = parseRule(reminder?.recurrence_rule)
    setRecurrence(parsed)
    // Non-null rule that would not parse: the builder cannot show it, so it is
    // held aside rather than approximated.
    setCustomRule(parsed === null && reminder?.recurrence_rule ? reminder.recurrence_rule : null)

    setAdoptZone(false)
    setError(null)
  }, [open, reminder])

  /**
   * A close handler whose identity never changes.
   *
   * `Dialog` keys its Escape handler, its focus trap and its scroll lock on
   * `onClose`, so handing it the caller's inline arrow would tear all three
   * down and rebuild them every time the page behind the dialog re-renders —
   * a background refetch of the reminder list is enough — and each rebuild
   * throws focus out of the form and back to whatever opened it. Latched
   * through a ref so the caller still does not have to memoise anything.
   */
  const onCloseRef = useRef(onClose)
  useEffect(() => {
    onCloseRef.current = onClose
  })
  const close = useCallback(() => onCloseRef.current(), [])

  const editing = reminder !== null
  const busy = create.isPending || update.isPending
  const fieldErrors = error instanceof ApiError ? error.fieldErrors : {}
  const otherFieldErrors = Object.entries(fieldErrors).filter(([field]) => !INLINE_FIELDS.has(field))
  // A reminder is created under a note, so without one there is nowhere to
  // POST. Caught here rather than by a request to `/notes//reminders`.
  const missingNote = !editing && (noteId === undefined || noteId === '')

  const when = fromInputs(date, time)
  const whenError = when === null ? 'Choose a date and a time.' : null
  const recurrenceError = recurrence === null ? null : validateRecurrence(recurrence, date)
  const summary = recurrence === null ? null : describeRecurrence(recurrence)

  const deviceZone = currentTimezone()
  const storedZone = reminder?.timezone ?? deviceZone
  const zoneDiffers = editing && storedZone !== deviceZone

  const patchRecurrence = (patch: Partial<Recurrence>) =>
    setRecurrence((current) => (current === null ? current : { ...current, ...patch }))

  const setFrequency = (value: string) => {
    if (value === 'NONE') {
      setRecurrence(null)
      return
    }
    const frequency = value as Frequency
    setRecurrence((current) => ({
      ...(current ?? DEFAULT_RECURRENCE),
      frequency,
      // Weekdays only mean something on a weekly rule; carrying them into a
      // monthly one would build `BYDAY` the server reads as "the 2nd Tuesday".
      byDay: supportsWeekdays(frequency) ? (current?.byDay ?? []) : [],
    }))
  }

  // Toggled off the state being updated rather than off the rendered value: two
  // days ticked inside one batch would otherwise each start from the same
  // `recurrence` and the second would drop the first.
  const toggleWeekday = (token: WeekdayToken) =>
    setRecurrence((current) =>
      current === null
        ? current
        : {
            ...current,
            byDay: current.byDay.includes(token)
              ? current.byDay.filter((day) => day !== token)
              : [...current.byDay, token],
          },
    )

  const setEnd = (end: EndCondition) => patchRecurrence({ end })

  const submit = () => {
    if (when === null || recurrenceError !== null || missingNote || busy) return
    setError(null)

    // Omitted, not null, when the rule is one this builder cannot show: the
    // server leaves an absent `recurrence_rule` alone and re-resolves it
    // against the new due date, which is exactly what "keep it" means.
    const rule: Pick<ReminderInput, 'recurrence_rule'> =
      customRule !== null ? {} : { recurrence_rule: recurrence === null ? null : buildRule(recurrence) }

    let work: Promise<ReminderRecord>
    if (reminder !== null) {
      work = update.mutateAsync({
        id: reminder.id,
        due_at: when.toISOString(),
        ...rule,
        ...(adoptZone ? { timezone: deviceZone } : {}),
        // Choosing a new time puts a reminder back in the queue. Without this
        // a snoozed one keeps its `snoozed_until` and still fires at the old
        // moment, and a completed one is rescheduled while staying completed —
        // both of which look like the new time was ignored.
        ...(reminder.status === 'scheduled' ? {} : ({ status: 'scheduled' } as const)),
      })
    } else if (noteId !== undefined && noteId !== '') {
      work = create.mutateAsync({
        noteId,
        due_at: when.toISOString(),
        timezone: deviceZone,
        ...rule,
      })
    } else {
      return
    }

    work
      .then((saved) => {
        onSaved?.(saved, !editing)
        close()
      })
      .catch(setError)
  }

  return (
    <Dialog
      open={open}
      onClose={close}
      title={editing ? 'Edit reminder' : 'Set a reminder'}
      description={noteTitle ? `On “${noteTitle}”.` : undefined}
      width={560}
      footer={
        <>
          <Button onClick={close} disabled={busy}>
            Cancel
          </Button>
          <Button
            variant="primary"
            icon="check"
            loading={busy}
            disabled={whenError !== null || recurrenceError !== null || missingNote}
            onClick={submit}
          >
            {editing ? 'Save reminder' : 'Set reminder'}
          </Button>
        </>
      }
    >
      <div className="org-form">
        {error && Object.keys(fieldErrors).length === 0 ? <ErrorNotice error={error} /> : null}

        {otherFieldErrors.map(([field, message]) => (
          <p className="org-error" role="alert" key={field}>
            {message}
          </p>
        ))}

        {missingNote ? (
          <p className="org-error" role="alert">
            A reminder hangs off a note, and this dialog was opened without one — so there is nothing to save it
            against. Open the note you want to be reminded about and set it from there.
          </p>
        ) : null}

        <div className="rem-presets" role="group" aria-label="Common times">
          {PRESETS.map((preset) => (
            <Button
              key={preset.key}
              size="sm"
              disabled={busy}
              onClick={() => {
                const at = preset.at(new Date())
                setDate(toDateInput(at))
                setTime(toTimeInput(at))
              }}
            >
              {preset.label}
            </Button>
          ))}
        </div>

        <div className="rem-when">
          <div className="org-field">
            <label className="org-label" htmlFor={dateId}>
              Date
            </label>
            <input
              id={dateId}
              className="org-input"
              type="date"
              value={date}
              required
              disabled={busy}
              data-autofocus=""
              aria-invalid={whenError !== null || fieldErrors.due_at !== undefined}
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
              required
              disabled={busy}
              aria-invalid={whenError !== null || fieldErrors.due_at !== undefined}
              onChange={(event) => setTime(event.target.value)}
            />
          </div>
        </div>

        {whenError !== null ? (
          <p className="org-error" role="alert">
            {whenError}
          </p>
        ) : null}
        {fieldErrors.due_at ? (
          <p className="org-error" role="alert">
            {fieldErrors.due_at}
          </p>
        ) : null}

        {when !== null ? (
          <p className="rem-preview">
            <Icon name="bell" size={14} />
            <span>{formatWhen(when.toISOString())}</span>
            {when.getTime() <= Date.now() ? (
              <span className="rem-preview__note">— already passed, so it will show as overdue</span>
            ) : null}
          </p>
        ) : null}

        <ZoneNote
          deviceZone={deviceZone}
          storedZone={storedZone}
          differs={zoneDiffers}
          adopt={adoptZone}
          disabled={busy}
          onAdoptChange={setAdoptZone}
        />

        {customRule !== null ? (
          <div className="org-notice org-notice--info" role="status">
            <Icon name="info" size={15} className="org-notice__icon" />
            <div className="org-notice__body">
              This reminder repeats on a schedule this form cannot show (<code>{customRule}</code>). Saving keeps
              it exactly as it is.
              <div className="org-notice__actions">
                <Button
                  size="sm"
                  disabled={busy}
                  onClick={() => {
                    setCustomRule(null)
                    setRecurrence(DEFAULT_RECURRENCE)
                  }}
                >
                  Replace it
                </Button>
              </div>
            </div>
          </div>
        ) : (
          <>
            <div className="org-field">
              <label className="org-label" htmlFor={repeatId}>
                Repeat
              </label>
              <select
                id={repeatId}
                className="org-select"
                value={recurrence?.frequency ?? 'NONE'}
                disabled={busy}
                onChange={(event) => setFrequency(event.target.value)}
              >
                <option value="NONE">Does not repeat</option>
                {FREQUENCIES.map((frequency) => (
                  <option key={frequency} value={frequency}>
                    {FREQUENCY_LABEL[frequency]}
                  </option>
                ))}
              </select>
            </div>

            {recurrence !== null ? (
              <div className="rem-repeat">
                <div className="org-field rem-interval">
                  <label className="org-label" htmlFor={intervalId}>
                    Every
                  </label>
                  <div className="rem-interval__row">
                    <input
                      id={intervalId}
                      className="org-input"
                      type="number"
                      min={1}
                      max={MAX_INTERVAL}
                      value={recurrence.interval}
                      disabled={busy}
                      aria-describedby={summaryId}
                      onChange={(event) => patchRecurrence({ interval: Number(event.target.value) })}
                    />
                    <span className="rem-interval__unit">{INTERVAL_UNIT[recurrence.frequency]}</span>
                  </div>
                </div>

                {supportsWeekdays(recurrence.frequency) ? (
                  <fieldset className="rem-fieldset">
                    <legend className="org-label">On these days</legend>
                    <div className="rem-days">
                      {WEEKDAYS.map((day) => (
                        <button
                          key={day.token}
                          type="button"
                          className="rem-day"
                          aria-pressed={recurrence.byDay.includes(day.token)}
                          aria-label={day.long}
                          disabled={busy}
                          onClick={() => toggleWeekday(day.token)}
                        >
                          {day.short}
                        </button>
                      ))}
                    </div>
                    <p className="org-hint">
                      {recurrence.byDay.length === 0
                        ? 'No day chosen, so it repeats on the same weekday as the date above.'
                        : 'Chosen days are ticked; the rest are not.'}
                    </p>
                  </fieldset>
                ) : null}

                <fieldset className="rem-fieldset">
                  <legend className="org-label">Ends</legend>
                  {/* The radio and the number beside it are separate controls
                      with separate labels: a <label> wrapping both would give
                      its text to one of them and leave the other unnamed. */}
                  <div className="rem-end">
                    <div className="rem-end__option">
                      <input
                        id={neverId}
                        type="radio"
                        name={endName}
                        checked={recurrence.end.kind === 'never'}
                        disabled={busy}
                        onChange={() => setEnd({ kind: 'never' })}
                      />
                      <label htmlFor={neverId}>Never</label>
                    </div>

                    <div className="rem-end__option">
                      <input
                        id={countRadioId}
                        type="radio"
                        name={endName}
                        checked={recurrence.end.kind === 'count'}
                        disabled={busy}
                        onChange={() => setEnd({ kind: 'count', count: DEFAULT_COUNT })}
                      />
                      <label htmlFor={countRadioId}>After a number of times</label>
                      <input
                        id={countId}
                        className="org-input rem-end__number"
                        type="number"
                        min={1}
                        max={MAX_COUNT}
                        aria-label="Number of times"
                        value={recurrence.end.kind === 'count' ? recurrence.end.count : ''}
                        disabled={busy || recurrence.end.kind !== 'count'}
                        onChange={(event) => setEnd({ kind: 'count', count: Number(event.target.value) })}
                      />
                    </div>

                    <div className="rem-end__option">
                      <input
                        id={untilRadioId}
                        type="radio"
                        name={endName}
                        checked={recurrence.end.kind === 'until'}
                        disabled={busy}
                        onChange={() => setEnd({ kind: 'until', date })}
                      />
                      <label htmlFor={untilRadioId}>On a date</label>
                      <input
                        id={untilId}
                        className="org-input rem-end__date"
                        type="date"
                        aria-label="Last date"
                        value={recurrence.end.kind === 'until' ? recurrence.end.date : ''}
                        disabled={busy || recurrence.end.kind !== 'until'}
                        onChange={(event) => setEnd({ kind: 'until', date: event.target.value })}
                      />
                    </div>
                  </div>

                  {/* The server has one timestamp column for a reminder and
                      nowhere to record how many occurrences have fired, so it
                      resolves COUNT into the equivalent UNTIL when it saves.
                      Saying so here stops "after 10 times" coming back as a
                      date and reading like a bug. */}
                  {recurrence.end.kind === 'count' ? (
                    <p className="org-hint">
                      Saved as the date occurrence {recurrence.end.count} falls on, so it stays right however
                      often this reminder is later moved.
                    </p>
                  ) : null}
                </fieldset>

                <p className="rem-summary" id={summaryId} aria-live="polite">
                  <Icon name="refresh" size={14} />
                  <span>{summary}</span>
                </p>

                {recurrenceError !== null ? (
                  <p className="org-error" role="alert">
                    {recurrenceError}
                  </p>
                ) : null}
              </div>
            ) : null}
          </>
        )}

        {fieldErrors.recurrence_rule ? (
          <p className="org-error" role="alert">
            {fieldErrors.recurrence_rule}
          </p>
        ) : null}
      </div>
    </Dialog>
  )
}

// ---------------------------------------------------------------------------
// Time zone
// ---------------------------------------------------------------------------

/**
 * Which clock this reminder is kept on.
 *
 * Always stated, because "09:00" means nothing without it. When the reminder
 * was made in another zone the two are shown side by side with a checkbox to
 * move it: changing the zone re-homes future occurrences and leaves this one
 * exactly where it is, which is worth spelling out rather than leaving people
 * to discover.
 */
function ZoneNote({
  deviceZone,
  storedZone,
  differs,
  adopt,
  disabled,
  onAdoptChange,
}: {
  deviceZone: string
  storedZone: string
  differs: boolean
  adopt: boolean
  disabled: boolean
  onAdoptChange: (value: boolean) => void
}) {
  if (!differs) {
    return <p className="org-hint">Times are in {describeTimezone(deviceZone)}, this device’s time zone.</p>
  }

  return (
    <div className="rem-zone">
      <p className="org-hint">
        This reminder is kept on {describeTimezone(storedZone)}. This device is on{' '}
        {describeTimezone(deviceZone)}.
      </p>
      <label className="rem-zone__adopt">
        <input
          type="checkbox"
          checked={adopt}
          disabled={disabled}
          onChange={(event) => onAdoptChange(event.target.checked)}
        />
        <span>Move it to {deviceZone}. The time above does not change — only where future repeats fall.</span>
      </label>
    </div>
  )
}
