/**
 * Setting or changing a reminder.
 *
 * Three things this dialog refuses to leave implicit:
 *
 *   - **The timezone.** A reminder is a promise about a wall clock, so the zone
 *     it will be interpreted in is written on screen rather than inferred from
 *     the browser and hoped about.
 *   - **What a repeat actually means.** The rule is summarised in English under
 *     the controls, from the same state that builds the RRULE — so what the
 *     user reads is what gets sent.
 *   - **A rule this builder cannot show.** The server's vocabulary is slightly
 *     wider than these controls (positional weekdays, monthly `BYDAY`). Rather
 *     than round-tripping such a rule into something simpler, the dialog says
 *     it cannot show it and leaves it alone unless the user replaces it.
 */

import { useEffect, useId, useMemo, useState } from 'react'

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
import type { ReminderRow } from '../hooks/useReminders'
import '../reminders.css'

export interface ReminderDialogProps {
  open: boolean
  /** The note the reminder hangs off. The API creates reminders under a note. */
  noteId: string
  /** Shown in the description, so the user can see what they are setting this on. */
  noteTitle?: string
  /** The reminder being changed, or null to set a new one. */
  reminder?: ReminderRow | null
  onClose: () => void
  onSaved?: (reminder: ReminderRow) => void
}

interface FormState {
  date: string
  time: string
  repeats: boolean
  recurrence: Recurrence
  /** A stored rule the builder has no controls for; kept verbatim until replaced. */
  customRule: string | null
}

function initialState(reminder: ReminderRow | null | undefined, now: Date): FormState {
  const start = reminder ? new Date(reminder.due_at) : PRESETS[1].at(now)
  const parsed = parseRule(reminder?.recurrence_rule)

  return {
    date: toDateInput(Number.isNaN(start.getTime()) ? now : start),
    time: toTimeInput(Number.isNaN(start.getTime()) ? now : start),
    repeats: Boolean(reminder?.recurrence_rule),
    recurrence: parsed ?? DEFAULT_RECURRENCE,
    customRule: reminder?.recurrence_rule && parsed === null ? reminder.recurrence_rule : null,
  }
}

export function ReminderDialog({
  open,
  noteId,
  noteTitle,
  reminder = null,
  onClose,
  onSaved,
}: ReminderDialogProps) {
  const [form, setForm] = useState<FormState>(() => initialState(reminder, new Date()))
  const [problem, setProblem] = useState<string | null>(null)

  const create = useCreateReminder()
  const update = useUpdateReminder()
  const saving = create.isPending || update.isPending
  const failure: ApiError | null = create.error ?? update.error

  const dateId = useId()
  const timeId = useId()
  const intervalId = useId()
  const frequencyId = useId()
  /** Radios need a name that is unique to this dialog, not to the document. */
  const endName = useId()

  // Reopening on a different reminder must not show the last one's schedule.
  // The mutations are fresh objects on every render, so depending on them here
  // would reset the form on each keystroke rather than once per opening.
  useEffect(() => {
    if (!open) return
    setForm(initialState(reminder, new Date()))
    setProblem(null)
    create.reset()
    update.reset()
  }, [open, reminder?.id])

  const zone = currentTimezone()
  const due = fromInputs(form.date, form.time)
  const summary = useMemo(() => describeRecurrence(form.recurrence), [form.recurrence])

  const patch = (change: Partial<FormState>) => setForm((current) => ({ ...current, ...change }))

  const patchRecurrence = (change: Partial<Recurrence>) =>
    setForm((current) => ({ ...current, recurrence: { ...current.recurrence, ...change } }))

  const applyPreset = (at: Date) => {
    patch({ date: toDateInput(at), time: toTimeInput(at) })
    setProblem(null)
  }

  const toggleWeekday = (token: WeekdayToken) =>
    setForm((current) => {
      const selected = current.recurrence.byDay.includes(token)
      return {
        ...current,
        recurrence: {
          ...current.recurrence,
          byDay: selected
            ? current.recurrence.byDay.filter((day) => day !== token)
            : [...current.recurrence.byDay, token],
        },
      }
    })

  const setEnd = (end: EndCondition) => patchRecurrence({ end })

  const save = async () => {
    setProblem(null)

    if (!due) {
      setProblem('Choose a date and a time for this reminder.')
      return
    }

    let rule: string | null = null
    if (form.repeats) {
      if (form.customRule) {
        rule = form.customRule
      } else {
        const invalid = validateRecurrence(form.recurrence, form.date)
        if (invalid) {
          setProblem(invalid)
          return
        }
        rule = buildRule(form.recurrence)
      }
    }

    const body = { due_at: due.toISOString(), timezone: zone, recurrence_rule: rule }

    try {
      const saved = reminder
        ? await update.mutateAsync({ id: reminder.id, ...body })
        : await create.mutateAsync({ noteId, ...body })

      onSaved?.(saved)
      onClose()
    } catch {
      // Rendered from the mutation's own error below, with the server's words.
    }
  }

  const fieldErrors = failure?.fieldErrors ?? {}

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={reminder ? 'Reschedule reminder' : 'Set a reminder'}
      description={noteTitle ? `On “${noteTitle}”.` : undefined}
      width={560}
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" icon="bell" loading={saving} onClick={() => void save()}>
            {reminder ? 'Save changes' : 'Set reminder'}
          </Button>
        </>
      }
    >
      <div className="reminder-form">
        <div className="reminder-form__presets" role="group" aria-label="Quick times">
          {PRESETS.map((preset) => (
            <Button key={preset.key} size="sm" onClick={() => applyPreset(preset.at(new Date()))}>
              {preset.label}
            </Button>
          ))}
        </div>

        <div className="reminder-form__when">
          <div className="org-field">
            <label className="org-label" htmlFor={dateId}>
              Date
            </label>
            <input
              id={dateId}
              className="org-input"
              type="date"
              data-autofocus
              value={form.date}
              aria-invalid={fieldErrors.due_at !== undefined || undefined}
              onChange={(event) => patch({ date: event.target.value })}
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
              value={form.time}
              onChange={(event) => patch({ time: event.target.value })}
            />
          </div>
        </div>

        {/* The zone is not a setting here — it is a statement of how the time
            above will be read, which is the thing that goes wrong silently. */}
        <p className="reminder-form__zone">
          <Icon name="info" size={14} />
          Times are in {describeTimezone(zone)}
          {due ? ` — this one fires ${formatWhen(due.toISOString()).toLowerCase()}` : ''}.
        </p>

        {fieldErrors.due_at ? (
          <p className="org-error" role="alert">
            {fieldErrors.due_at}
          </p>
        ) : null}

        <fieldset className="reminder-repeat">
          <legend className="reminder-repeat__legend">Repeat</legend>

          <label className="reminder-repeat__toggle">
            <input
              type="checkbox"
              checked={form.repeats}
              onChange={(event) => patch({ repeats: event.target.checked })}
            />
            <span>Repeat this reminder</span>
          </label>

          {form.repeats && form.customRule ? (
            <div className="reminder-repeat__custom">
              <p className="org-hint">
                This reminder repeats on a schedule these controls cannot show
                (<code>{form.customRule}</code>). It is left exactly as it is unless you replace it.
              </p>
              <Button
                size="sm"
                onClick={() => patch({ customRule: null, recurrence: DEFAULT_RECURRENCE })}
              >
                Replace with a simple repeat
              </Button>
            </div>
          ) : null}

          {form.repeats && !form.customRule ? (
            <div className="reminder-repeat__builder">
              <div className="reminder-repeat__cadence">
                <label className="org-label" htmlFor={intervalId}>
                  Every
                </label>
                <input
                  id={intervalId}
                  className="org-input reminder-repeat__interval"
                  type="number"
                  min={1}
                  max={MAX_INTERVAL}
                  step={1}
                  value={form.recurrence.interval}
                  onChange={(event) =>
                    patchRecurrence({ interval: Number(event.target.value) || 1 })
                  }
                />

                <label className="sr-only" htmlFor={frequencyId}>
                  Frequency
                </label>
                <select
                  id={frequencyId}
                  className="org-select"
                  value={form.recurrence.frequency}
                  onChange={(event) => {
                    const frequency = event.target.value as Frequency
                    // Weekdays only mean something weekly; carrying a stale
                    // selection into a monthly rule would build a rule the
                    // server refuses.
                    patchRecurrence({
                      frequency,
                      byDay: supportsWeekdays(frequency) ? form.recurrence.byDay : [],
                    })
                  }}
                >
                  {FREQUENCIES.map((frequency) => (
                    <option key={frequency} value={frequency}>
                      {FREQUENCY_LABEL[frequency]}
                    </option>
                  ))}
                </select>
              </div>

              {supportsWeekdays(form.recurrence.frequency) ? (
                <div className="reminder-repeat__days" role="group" aria-label="Days of the week">
                  {WEEKDAYS.map((day) => {
                    const selected = form.recurrence.byDay.includes(day.token)
                    return (
                      <button
                        key={day.token}
                        type="button"
                        className="reminder-day"
                        aria-pressed={selected}
                        aria-label={day.long}
                        onClick={() => toggleWeekday(day.token)}
                      >
                        {day.short}
                      </button>
                    )
                  })}
                </div>
              ) : null}

              {/* One control per label: the number and date fields sit beside
                  their radio rather than inside its label, so a screen reader
                  is not told that "After" names two different inputs. */}
              <fieldset className="reminder-repeat__end">
                <legend className="org-label">Ends</legend>

                <div className="reminder-repeat__option">
                  <label>
                    <input
                      type="radio"
                      name={endName}
                      checked={form.recurrence.end.kind === 'never'}
                      onChange={() => setEnd({ kind: 'never' })}
                    />
                    <span>Never</span>
                  </label>
                </div>

                <div className="reminder-repeat__option">
                  <label>
                    <input
                      type="radio"
                      name={endName}
                      checked={form.recurrence.end.kind === 'count'}
                      onChange={() => setEnd({ kind: 'count', count: 10 })}
                    />
                    <span>After</span>
                  </label>
                  <input
                    className="org-input reminder-repeat__count"
                    type="number"
                    min={1}
                    max={MAX_COUNT}
                    step={1}
                    aria-label="Number of times"
                    disabled={form.recurrence.end.kind !== 'count'}
                    value={form.recurrence.end.kind === 'count' ? form.recurrence.end.count : 10}
                    onChange={(event) => setEnd({ kind: 'count', count: Number(event.target.value) || 1 })}
                  />
                  <span>times</span>
                </div>

                <div className="reminder-repeat__option">
                  <label>
                    <input
                      type="radio"
                      name={endName}
                      checked={form.recurrence.end.kind === 'until'}
                      onChange={() => setEnd({ kind: 'until', date: form.date })}
                    />
                    <span>On</span>
                  </label>
                  <input
                    className="org-input"
                    type="date"
                    aria-label="Last date"
                    disabled={form.recurrence.end.kind !== 'until'}
                    value={form.recurrence.end.kind === 'until' ? form.recurrence.end.date : ''}
                    onChange={(event) => setEnd({ kind: 'until', date: event.target.value })}
                  />
                </div>
              </fieldset>

              {/* Built from the same state as the rule itself, so it cannot
                  describe something other than what is sent. */}
              <p className="reminder-repeat__summary">
                <Icon name="refresh" size={14} />
                {summary}
              </p>
            </div>
          ) : null}
        </fieldset>

        {problem ? (
          <p className="org-error" role="alert">
            {problem}
          </p>
        ) : null}

        {fieldErrors.recurrence_rule ? (
          <p className="org-error" role="alert">
            {fieldErrors.recurrence_rule}
          </p>
        ) : null}

        {failure && Object.keys(fieldErrors).length === 0 ? <ErrorNotice error={failure} /> : null}
      </div>
    </Dialog>
  )
}
