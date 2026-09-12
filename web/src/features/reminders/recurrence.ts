/**
 * The RRULE subset this product supports, in the browser.
 *
 * `server-php/src/Domain/Reminders/RecurrenceRule.php` understands `FREQ` with
 * `INTERVAL`, `BYDAY`, `COUNT` and `UNTIL`, and **rejects anything else at
 * write time** rather than dropping it. So the builder here offers exactly that
 * vocabulary: a control the server would refuse is a control that produces a
 * validation error the user cannot act on.
 *
 * Two asymmetries with the server are deliberate:
 *
 *   - `BYDAY` is offered for weekly rules only. The server also accepts
 *     positional days on a monthly rule (`2TU`, `-1FR`), but "the second
 *     Tuesday" needs a second control to express and a monthly reminder
 *     repeating on its own date is what people mean nine times in ten.
 *   - A rule the builder cannot represent is **not** rewritten. {@link parseRule}
 *     answers null, the dialog says so, and the stored rule is left alone
 *     unless the user deliberately replaces it.
 *
 * The server rewrites `COUNT` into the equivalent `UNTIL` when it stores a
 * reminder — there is one timestamp column and nowhere to record how many
 * occurrences have already fired — so a rule saved as "after 10 times" comes
 * back as "until <date>". That is the server being honest about what it kept,
 * not a bug here.
 */

export type Frequency = 'DAILY' | 'WEEKLY' | 'MONTHLY' | 'YEARLY'

export const FREQUENCIES: readonly Frequency[] = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY']

export interface WeekdayOption {
  token: WeekdayToken
  /** The chip's label. */
  short: string
  /** The accessible name, because "Mo" is not a word. */
  long: string
}

export type WeekdayToken = 'MO' | 'TU' | 'WE' | 'TH' | 'FR' | 'SA' | 'SU'

/** Monday first: the reminder is a working-week tool before it is a calendar. */
export const WEEKDAYS: readonly WeekdayOption[] = [
  { token: 'MO', short: 'Mon', long: 'Monday' },
  { token: 'TU', short: 'Tue', long: 'Tuesday' },
  { token: 'WE', short: 'Wed', long: 'Wednesday' },
  { token: 'TH', short: 'Thu', long: 'Thursday' },
  { token: 'FR', short: 'Fri', long: 'Friday' },
  { token: 'SA', short: 'Sat', long: 'Saturday' },
  { token: 'SU', short: 'Sun', long: 'Sunday' },
]

export type EndCondition =
  | { kind: 'never' }
  /** `COUNT`. */
  | { kind: 'count'; count: number }
  /** `UNTIL`, as the `yyyy-mm-dd` an `<input type="date">` produces. */
  | { kind: 'until'; date: string }

export interface Recurrence {
  frequency: Frequency
  interval: number
  /** Weekly rules only; empty means "the same weekday the reminder starts on". */
  byDay: WeekdayToken[]
  end: EndCondition
}

/** Mirrors the server's ceilings, so the builder cannot compose a refusal. */
export const MAX_INTERVAL = 999
export const MAX_COUNT = 999

export const DEFAULT_RECURRENCE: Recurrence = {
  frequency: 'WEEKLY',
  interval: 1,
  byDay: [],
  end: { kind: 'never' },
}

const FREQUENCY_NOUN: Record<Frequency, [singular: string, plural: string]> = {
  DAILY: ['day', 'days'],
  WEEKLY: ['week', 'weeks'],
  MONTHLY: ['month', 'months'],
  YEARLY: ['year', 'years'],
}

export const FREQUENCY_LABEL: Record<Frequency, string> = {
  DAILY: 'Daily',
  WEEKLY: 'Weekly',
  MONTHLY: 'Monthly',
  YEARLY: 'Yearly',
}

// ---------------------------------------------------------------------------
// Writing a rule
// ---------------------------------------------------------------------------

/** Weekday selection only means something on a weekly rule. */
export function supportsWeekdays(frequency: Frequency): boolean {
  return frequency === 'WEEKLY'
}

export function buildRule(recurrence: Recurrence): string {
  const parts = [`FREQ=${recurrence.frequency}`]

  if (recurrence.interval > 1) parts.push(`INTERVAL=${recurrence.interval}`)

  if (supportsWeekdays(recurrence.frequency) && recurrence.byDay.length > 0) {
    // Written in week order rather than click order, so two identical rules
    // built in a different sequence are the same string.
    const ordered = WEEKDAYS.filter((day) => recurrence.byDay.includes(day.token)).map((day) => day.token)
    parts.push(`BYDAY=${ordered.join(',')}`)
  }

  if (recurrence.end.kind === 'count') parts.push(`COUNT=${recurrence.end.count}`)
  if (recurrence.end.kind === 'until') parts.push(`UNTIL=${recurrence.end.date.replaceAll('-', '')}`)

  return parts.join(';')
}

/**
 * What the builder would refuse to save, and why.
 *
 * `dueDate` is the reminder's own `yyyy-mm-dd`: a series that ends before it
 * begins is accepted by the server and then never fires, which is the kind of
 * silent nothing a person only discovers by missing something.
 */
export function validateRecurrence(recurrence: Recurrence, dueDate: string): string | null {
  if (!Number.isInteger(recurrence.interval) || recurrence.interval < 1 || recurrence.interval > MAX_INTERVAL) {
    return `Repeat every 1 to ${MAX_INTERVAL} ${FREQUENCY_NOUN[recurrence.frequency][1]}.`
  }

  if (recurrence.end.kind === 'count') {
    const { count } = recurrence.end
    if (!Number.isInteger(count) || count < 1 || count > MAX_COUNT) {
      return `Stop after 1 to ${MAX_COUNT} times.`
    }
  }

  if (recurrence.end.kind === 'until') {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(recurrence.end.date)) return 'Choose the date this should stop repeating.'
    if (dueDate !== '' && recurrence.end.date < dueDate) {
      return 'That end date is before the reminder itself.'
    }
  }

  return null
}

// ---------------------------------------------------------------------------
// Reading one back
// ---------------------------------------------------------------------------

/**
 * A stored rule as the builder's state, or null when it says something the
 * builder has no control for.
 *
 * Null is the honest answer for `BYDAY=2TU` or a monthly rule with weekdays:
 * showing it as "monthly" would be a quiet lie about what the reminder does.
 */
export function parseRule(rule: string | null | undefined): Recurrence | null {
  if (!rule) return null

  const text = rule.trim().toUpperCase().replace(/^RRULE:/, '')
  if (text === '') return null

  const parts = new Map<string, string>()
  for (const segment of text.split(';')) {
    const trimmed = segment.trim()
    if (trimmed === '') continue
    const equals = trimmed.indexOf('=')
    if (equals < 1) return null
    const name = trimmed.slice(0, equals)
    if (parts.has(name)) return null
    parts.set(name, trimmed.slice(equals + 1))
  }

  const frequency = parts.get('FREQ')
  if (!isFrequency(frequency)) return null

  const interval = parts.has('INTERVAL') ? Number(parts.get('INTERVAL')) : 1
  if (!Number.isInteger(interval) || interval < 1 || interval > MAX_INTERVAL) return null

  const byDay = parseByDay(parts.get('BYDAY'), frequency)
  if (byDay === null) return null

  if (parts.has('COUNT') && parts.has('UNTIL')) return null

  let end: EndCondition = { kind: 'never' }
  if (parts.has('COUNT')) {
    const count = Number(parts.get('COUNT'))
    if (!Number.isInteger(count) || count < 1 || count > MAX_COUNT) return null
    end = { kind: 'count', count }
  } else if (parts.has('UNTIL')) {
    const date = parseUntil(parts.get('UNTIL') ?? '')
    if (date === null) return null
    end = { kind: 'until', date }
  }

  for (const name of parts.keys()) {
    if (!['FREQ', 'INTERVAL', 'BYDAY', 'COUNT', 'UNTIL'].includes(name)) return null
  }

  return { frequency, interval, byDay, end }
}

function isFrequency(value: string | undefined): value is Frequency {
  return value !== undefined && (FREQUENCIES as readonly string[]).includes(value)
}

/** `[]` for "not given", null for "the builder cannot show this". */
function parseByDay(value: string | undefined, frequency: Frequency): WeekdayToken[] | null {
  if (value === undefined || value === '') return []
  if (!supportsWeekdays(frequency)) return null

  const tokens: WeekdayToken[] = []
  for (const raw of value.split(',')) {
    const token = raw.trim()
    const match = WEEKDAYS.find((day) => day.token === token)
    // A positional day (`2TU`) never matches, which is exactly the rule the
    // builder has no control for.
    if (!match) return null
    if (!tokens.includes(match.token)) tokens.push(match.token)
  }

  return tokens
}

/** Both RFC 5545 forms, reduced to the `yyyy-mm-dd` the date input speaks. */
function parseUntil(value: string): string | null {
  const match = /^(\d{4})(\d{2})(\d{2})(?:T\d{6}Z)?$/.exec(value.trim())
  if (!match) return null

  const [, year, month, day] = match
  const date = new Date(Number(year), Number(month) - 1, Number(day))
  if (date.getMonth() !== Number(month) - 1 || date.getDate() !== Number(day)) return null

  return `${year}-${month}-${day}`
}

// ---------------------------------------------------------------------------
// Saying it in words
// ---------------------------------------------------------------------------

const DAY_MONTH = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' })
const DAY_MONTH_YEAR = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' })

/** A `yyyy-mm-dd` as a date, read at midday so no timezone can shift the day. */
export function dateFromInput(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return null

  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12)
}

/** The year is only worth the space when it is not this one. */
export function formatShortDate(value: string, now: Date = new Date()): string {
  const date = dateFromInput(value)
  if (!date) return value

  return date.getFullYear() === now.getFullYear() ? DAY_MONTH.format(date) : DAY_MONTH_YEAR.format(date)
}

/**
 * The rule in plain English — "Every 2 weeks on Mon, Wed, until 3 Nov".
 *
 * This is the only thing most people will read before saving, so it is built
 * from the same state the rule is, and never from the rule string: a summary
 * derived separately is a summary that can disagree with what gets sent.
 */
export function describeRecurrence(recurrence: Recurrence, now: Date = new Date()): string {
  const [singular, plural] = FREQUENCY_NOUN[recurrence.frequency]
  const every = recurrence.interval === 1 ? `Every ${singular}` : `Every ${recurrence.interval} ${plural}`

  const days =
    supportsWeekdays(recurrence.frequency) && recurrence.byDay.length > 0
      ? ` on ${WEEKDAYS.filter((day) => recurrence.byDay.includes(day.token))
          .map((day) => day.short)
          .join(', ')}`
      : ''

  const end =
    recurrence.end.kind === 'count'
      ? `, ${recurrence.end.count} ${recurrence.end.count === 1 ? 'time' : 'times'}`
      : recurrence.end.kind === 'until'
        ? `, until ${formatShortDate(recurrence.end.date, now)}`
        : ''

  return `${every}${days}${end}`
}

/** The same sentence for a rule that came off the server. */
export function describeRule(rule: string | null | undefined, now: Date = new Date()): string | null {
  if (!rule) return null

  const parsed = parseRule(rule)

  return parsed === null ? 'Repeats on a custom schedule' : describeRecurrence(parsed, now)
}
