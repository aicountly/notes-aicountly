/**
 * Reminders: the list, and the five things you can do to one.
 *
 * Two behaviours here come straight from `ReminderService` and are the whole
 * reason this file is not four generic mutations:
 *
 *   - **Completing a recurring reminder does not finish it.** The server moves
 *     the row to its next occurrence and answers 200 with it, so `complete`
 *     writes the returned reminder back into every cached list instead of
 *     dropping the row. A reminder that vanished and then reappeared a second
 *     later — which is what invalidate-only would do — reads as a bug.
 *   - **The order is the server's.** `GET /reminders` sorts by
 *     `coalesce(snoozed_until, due_at)`, which is the moment the user will
 *     actually be interrupted. {@link groupReminders} only cuts that sequence
 *     into groups; it never re-sorts, because sorting by `due_at` here would
 *     silently disagree with the server about where a snoozed reminder belongs.
 *
 * A reminder belongs to a person, not to a note: two people sharing a note each
 * have their own and cannot see the other's. That is why there is no note id in
 * the list query — it is always "mine".
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { QueryClient, UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { NoteType, Reminder } from '../../../shared/api/types'
import type { Frequency } from '../recurrence'

/**
 * The parsed rule the presenter sends beside the RRULE string.
 *
 * `by_day` is `string[]` rather than a weekday union because the server also
 * emits positional days (`2TU`, `-1FR`) that the browser's builder has no
 * control for — see `recurrence.ts`.
 */
export interface ParsedRecurrence {
  frequency: Frequency
  interval: number
  by_day: string[]
  count: number | null
  until: string | null
}

/**
 * A reminder as the API actually sends it.
 *
 * `Reminder` in the shared wire types is the older, smaller shape; these five
 * fields are on every response from `ReminderService::present()` and are what
 * this screen is built out of. Declared as an extension rather than as a second
 * definition, so there is still one description of a reminder and this is
 * visibly the delta — the same shape `SmartFolderNode` takes for the same
 * reason.
 */
export interface ReminderRecord extends Reminder {
  /** `coalesce(snoozed_until, due_at)` — when the user is actually interrupted. */
  due_effective_at: string
  recurrence: ParsedRecurrence | null
  notified_at: string | null
  created_at: string
  updated_at: string
}

/** A list row, which also carries enough of its note to name and link it. */
export interface ReminderRow extends ReminderRecord {
  /** The note's title, or its first line when it has none. Computed server-side. */
  note_display_title: string
  note_type: NoteType
}

/** `open` is scheduled and snoozed; `all` adds completed and cancelled. */
export type ReminderScope = 'open' | 'all'

/** The server caps at 200. A hundred is more than a person triages in a sitting. */
export const LIST_LIMIT = 100

export function useReminders(scope: ReminderScope = 'all'): UseQueryResult<ReminderRow[], ApiError> {
  return useQuery<ReminderRow[], ApiError>({
    queryKey: [...queryKeys.reminders, scope],
    // The query's own signal, so switching scope aborts the list request the
    // previous scope started rather than leaving it to land on a cache nobody
    // is reading any more.
    queryFn: ({ signal }) =>
      api.get<ReminderRow[]>('/reminders', { query: { status: scope, limit: LIST_LIMIT }, signal }),
  })
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

export interface ReminderInput {
  /** RFC 3339. The instant, not a wall clock. */
  due_at: string
  /** IANA name. Governs where future occurrences fall, never where this one is. */
  timezone?: string
  /** An RRULE, or null for a one-off. Omit to leave a stored rule alone. */
  recurrence_rule?: string | null
  /**
   * Only ever `scheduled`, and only to lift a snooze: the server clears
   * `snoozed_until` on that transition, and choosing a new time for a snoozed
   * reminder has to mean the snooze is over.
   */
  status?: 'scheduled'
}

export function useCreateReminder() {
  const client = useQueryClient()

  return useMutation<ReminderRecord, ApiError, ReminderInput & { noteId: string }>({
    // Created under its note so the note can be authorised; changed by its own
    // id afterwards.
    mutationFn: ({ noteId, ...body }) => api.post<ReminderRecord>(`/notes/${noteId}/reminders`, body),
    onSuccess: () => invalidate(client),
  })
}

export function useUpdateReminder() {
  const client = useQueryClient()

  return useMutation<ReminderRecord, ApiError, ReminderInput & { id: string }>({
    mutationFn: ({ id, ...patch }) => api.patch<ReminderRecord>(`/reminders/${id}`, patch),
    onSuccess: (reminder) => {
      patchCaches(client, reminder)
      invalidate(client)
    },
  })
}

export function useDeleteReminder() {
  const client = useQueryClient()

  return useMutation<void, ApiError, string>({
    mutationFn: (id) => api.delete(`/reminders/${id}`),
    onSuccess: (_result, id) => {
      dropFromCaches(client, id)
      invalidate(client)
    },
  })
}

export type SnoozeInput = { minutes: number } | { until: string }

export function useSnoozeReminder() {
  const client = useQueryClient()

  return useMutation<ReminderRecord, ApiError, { id: string; input: SnoozeInput }>({
    mutationFn: ({ id, input }) => api.post<ReminderRecord>(`/reminders/${id}/snooze`, input),
    onSuccess: (reminder) => {
      patchCaches(client, reminder)
      invalidate(client)
    },
  })
}

/**
 * Tick a reminder off — or move a repeating one on.
 *
 * The response is the reminder either way, and the caller needs to be able to
 * tell the two apart: `status` comes back `completed` for a one-off and
 * `scheduled` with a new `due_at` for a series that has another occurrence.
 */
export function useCompleteReminder() {
  const client = useQueryClient()

  return useMutation<ReminderRecord, ApiError, string>({
    mutationFn: (id) => api.post<ReminderRecord>(`/reminders/${id}/complete`),
    onSuccess: (reminder) => {
      patchCaches(client, reminder)
      invalidate(client)
    },
  })
}

// ---------------------------------------------------------------------------
// Cache
// ---------------------------------------------------------------------------

/**
 * Write a changed reminder into every cached list that holds it.
 *
 * Merged rather than replaced: a mutation response is one reminder without its
 * note (`present()` only joins the note for a list), so overwriting the row
 * would blank the title the row is rendered from.
 */
function patchCaches(client: QueryClient, reminder: ReminderRecord): void {
  for (const [key, rows] of client.getQueriesData<ReminderRecord[]>({ queryKey: queryKeys.reminders })) {
    if (!Array.isArray(rows)) continue
    client.setQueryData<ReminderRecord[]>(
      key,
      rows.map((row) => (row.id === reminder.id ? { ...row, ...reminder } : row)),
    )
  }
}

function dropFromCaches(client: QueryClient, reminderId: string): void {
  for (const [key, rows] of client.getQueriesData<ReminderRecord[]>({ queryKey: queryKeys.reminders })) {
    if (!Array.isArray(rows)) continue
    client.setQueryData<ReminderRecord[]>(
      key,
      rows.filter((row) => row.id !== reminderId),
    )
  }
}

/**
 * Notes are invalidated too: `has_reminder` is on every note summary, and a
 * card still showing a bell for a reminder that was just deleted is the kind of
 * small lie that makes people stop believing the badge.
 */
function invalidate(client: QueryClient): void {
  void client.invalidateQueries({ queryKey: queryKeys.reminders })
  void client.invalidateQueries({ queryKey: queryKeys.notes.all })
}

// ---------------------------------------------------------------------------
// Grouping
// ---------------------------------------------------------------------------

export type ReminderGroupKey = 'overdue' | 'today' | 'upcoming' | 'completed' | 'cancelled'

export interface ReminderGroup {
  key: ReminderGroupKey
  title: string
  reminders: ReminderRow[]
}

const GROUP_TITLES: Record<ReminderGroupKey, string> = {
  overdue: 'Overdue',
  today: 'Today',
  upcoming: 'Upcoming',
  completed: 'Completed',
  cancelled: 'Cancelled',
}

/** The order the page renders them in: what is late, then what is next. */
const GROUP_ORDER: readonly ReminderGroupKey[] = ['overdue', 'today', 'upcoming', 'completed', 'cancelled']

/** Still has a firing ahead of it. Mirrors `ReminderService::OPEN_STATUSES`. */
export function isOpen(reminder: Pick<ReminderRecord, 'status'>): boolean {
  return reminder.status === 'scheduled' || reminder.status === 'snoozed'
}

/** True when completing this moves it on to a next occurrence instead of ending it. */
export function repeats(reminder: Pick<ReminderRecord, 'recurrence_rule'>): boolean {
  return reminder.recurrence_rule !== null && reminder.recurrence_rule !== ''
}

/** When a reminder will actually interrupt — the server's own coalesce. */
export function effectiveDue(reminder: ReminderRecord): number {
  return Date.parse(reminder.due_effective_at)
}

function endOfDay(date: Date): number {
  const end = new Date(date)
  end.setHours(23, 59, 59, 999)
  return end.getTime()
}

/**
 * Cut the server's sequence into the sections a person reads.
 *
 * Deliberately a partition and not a sort: rows keep the order they arrived in,
 * so a reminder snoozed to Thursday stays under Thursday's neighbours rather
 * than jumping back to the Monday it was first set for.
 *
 * Cancelled reminders get their own section rather than being folded into
 * "Completed" or dropped: they are not done, and a row the server sent that
 * the screen never shows is a row the user cannot delete.
 */
export function groupReminders(rows: readonly ReminderRow[], now: Date = new Date()): ReminderGroup[] {
  const buckets = new Map<ReminderGroupKey, ReminderRow[]>(GROUP_ORDER.map((key) => [key, []]))
  const nowMs = now.getTime()
  const todayEnds = endOfDay(now)

  for (const row of rows) {
    const due = effectiveDue(row)
    const key: ReminderGroupKey = !isOpen(row)
      ? row.status === 'cancelled'
        ? 'cancelled'
        : 'completed'
      : due <= nowMs
        ? 'overdue'
        : due <= todayEnds
          ? 'today'
          : 'upcoming'

    buckets.get(key)?.push(row)
  }

  return GROUP_ORDER.filter((key) => (buckets.get(key)?.length ?? 0) > 0).map((key) => ({
    key,
    title: GROUP_TITLES[key],
    reminders: buckets.get(key) ?? [],
  }))
}

// ---------------------------------------------------------------------------
// Snoozing
// ---------------------------------------------------------------------------

export interface SnoozeChoice {
  key: string
  label: string
  input: (now: Date) => SnoozeInput
}

/** 09:00 the next day — the same "morning" the dialog's preset means. */
function tomorrowMorning(now: Date): Date {
  const date = new Date(now)
  date.setDate(date.getDate() + 1)
  date.setHours(9, 0, 0, 0)
  return date
}

/**
 * The three ready-made ways to push a reminder out. The fourth — a time of the
 * user's own — is the picker in the snooze menu and needs no entry here.
 *
 * The first two are relative and sent as `minutes`, so the server anchors them
 * to its own clock rather than trusting a device whose time may be wrong. The
 * third is an absolute moment, because "tomorrow morning" is a wall clock and
 * only the browser knows which one the user is reading.
 */
export const SNOOZE_CHOICES: readonly SnoozeChoice[] = [
  { key: '15m', label: '15 minutes', input: () => ({ minutes: 15 }) },
  { key: '1h', label: '1 hour', input: () => ({ minutes: 60 }) },
  { key: 'tomorrow', label: 'Tomorrow morning', input: (now) => ({ until: tomorrowMorning(now).toISOString() }) },
]
