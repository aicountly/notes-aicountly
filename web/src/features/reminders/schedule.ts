/**
 * Wall-clock helpers for reminders.
 *
 * A reminder is the one thing in this app where the user's clock and the
 * server's instant have to agree out loud. Everything here converts between the
 * two in one direction only — `<input type="date">` and `<input type="time">`
 * speak local wall time, the API speaks RFC 3339 — and the timezone that
 * conversion used is shown to the user rather than assumed.
 */

const TIME_FORMAT = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' })
const WEEKDAY_DATE = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
const DATE_WITH_YEAR = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' })

/** The IANA zone this browser is in — what a reminder is stored against. */
export function currentTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
  } catch {
    return 'UTC'
  }
}

/**
 * The zone, written the way a person can check it: "Europe/London (GMT+1)".
 *
 * The offset matters more than the name to someone who travels — "Asia/Kolkata"
 * tells them nothing about whether 09:00 is 09:00 where they are sitting.
 */
export function describeTimezone(zone: string = currentTimezone(), at: Date = new Date()): string {
  try {
    const parts = new Intl.DateTimeFormat(undefined, { timeZone: zone, timeZoneName: 'shortOffset' })
      .formatToParts(at)
      .find((part) => part.type === 'timeZoneName')

    return parts ? `${zone} (${parts.value})` : zone
  } catch {
    return zone
  }
}

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

/** A Date as the `yyyy-mm-dd` a date input expects, in local time. */
export function toDateInput(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/** A Date as the `HH:mm` a time input expects, in local time. */
export function toTimeInput(date: Date): string {
  return `${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** The two inputs back into an instant, or null while either is incomplete. */
export function fromInputs(dateValue: string, timeValue: string): Date | null {
  const date = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateValue)
  const time = /^(\d{2}):(\d{2})$/.exec(timeValue)
  if (!date || !time) return null

  const composed = new Date(
    Number(date[1]),
    Number(date[2]) - 1,
    Number(date[3]),
    Number(time[1]),
    Number(time[2]),
    0,
    0,
  )

  return Number.isNaN(composed.getTime()) ? null : composed
}

// ---------------------------------------------------------------------------
// Presets
// ---------------------------------------------------------------------------

export interface Preset {
  key: string
  label: string
  at: (now: Date) => Date
}

function at(now: Date, dayOffset: number, hours: number, minutes = 0): Date {
  const date = new Date(now)
  date.setDate(date.getDate() + dayOffset)
  date.setHours(hours, minutes, 0, 0)
  return date
}

/**
 * The three times people actually pick.
 *
 * "Later today" is three hours on, rounded up to the next quarter hour, rather
 * than a fixed evening slot — at 21:00 "later today" must not be in the past.
 */
export const PRESETS: readonly Preset[] = [
  {
    key: 'later-today',
    label: 'Later today',
    at: (now) => {
      const later = new Date(now.getTime() + 3 * 60 * 60_000)
      later.setMinutes(Math.ceil(later.getMinutes() / 15) * 15, 0, 0)
      return later
    },
  },
  { key: 'tomorrow-morning', label: 'Tomorrow morning', at: (now) => at(now, 1, 9) },
  {
    key: 'next-week',
    label: 'Next week',
    // The next Monday, so "next week" means the same thing on Friday as it
    // does on Monday.
    at: (now) => at(now, ((8 - now.getDay()) % 7) || 7, 9),
  },
]

// ---------------------------------------------------------------------------
// Reading a time back
// ---------------------------------------------------------------------------

function startOfDay(date: Date): number {
  const copy = new Date(date)
  copy.setHours(0, 0, 0, 0)
  return copy.getTime()
}

/** Whole days between two instants, by calendar day rather than by 24 hours. */
export function daysBetween(from: Date, to: Date): number {
  return Math.round((startOfDay(to) - startOfDay(from)) / 86_400_000)
}

/**
 * When something is due, as a person would say it.
 *
 * "Today at 09:00" beats "6 Sep 2026, 09:00" for anything inside a couple of
 * days, and the year only earns its place outside this one.
 */
export function formatWhen(iso: string | null, now: Date = new Date()): string {
  if (!iso) return 'No date'

  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return 'No date'

  const time = TIME_FORMAT.format(date)
  const offset = daysBetween(now, date)

  if (offset === 0) return `Today at ${time}`
  if (offset === 1) return `Tomorrow at ${time}`
  if (offset === -1) return `Yesterday at ${time}`

  const day =
    date.getFullYear() === now.getFullYear() ? WEEKDAY_DATE.format(date) : DATE_WITH_YEAR.format(date)

  return `${day} at ${time}`
}
