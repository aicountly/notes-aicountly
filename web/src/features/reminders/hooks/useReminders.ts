/**
 * Reading and changing reminders.
 *
 * One query backs the whole page. `GET /reminders` takes a `status` filter, but
 * the four buckets the page shows — overdue, today, upcoming, completed — are
 * three slices of the same rows plus one, and fetching them separately would
 * make "complete this" refetch four lists to move one row between two of them.
 * So everything is read once with `status=all` and grouped here.
 *
 * The time a reminder is grouped by is **not** `due_at`: a snoozed reminder is
 * due when the snooze runs out. That is `coalesce(snoozed_until, due_at)`, the
 * same expression the server orders by.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { QueryClient, UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { Reminder } from '../../../shared/api/types'

/**
 * A reminder as the presenter actually sends it.
 *
 * The extra fields are not in the shared wire types yet, so they are optional
 * here and every read has a fallback: an older server that omits them still
 * renders a usable row.
 */
export interface ReminderRow extends Reminder {
  /** `snoozed_until` where there is one, `due_at` otherwise. */
  due_effective_at?: string | null
  /** The note's title, or its first line when it has none. */
  note_display_title?: string | null
}

export type ReminderBucket = 'overdue' | 'today' | 'upcoming' | 'completed'

/**
 * Read every status in one request.
 *
 * The key extends {@link queryKeys.reminders} rather than replacing it, so
 * invalidating `['reminders']` — which every write below does, and which Home
 * also relies on — still reaches this list.
 */
const REMINDERS_KEY = [...queryKeys.reminders, { status: 'all' }] as const

/** The server's own ceiling. Past it, a reminders list is a different product. */
const LIMIT = 200

export function useReminders(): UseQueryResult<ReminderRow[], ApiError> {
  return useQuery<ReminderRow[], ApiError>({
    queryKey: REMINDERS_KEY,
    queryFn: () => api.get<ReminderRow[]>('/reminders', { query: { status: 'all', limit: LIMIT } }),
  })
}

/** When the user will actually be interrupted. */
export function effectiveDue(reminder: ReminderRow): string {
  return reminder.due_effective_at ?? reminder.snoozed_until ?? reminder.due_at
}

export function noteTitleOf(reminder: ReminderRow): string {
  return reminder.note_display_title?.trim() || reminder.note_title?.trim() || 'Untitled note'
}

export type ReminderGroups = Record<ReminderBucket, ReminderRow[]>

/**
 * The four buckets, soonest first.
 *
 * Cancelled reminders are not shown anywhere: the row exists so the server can
 * tell "never set" from "called off", which is a distinction the person who
 * called it off does not need repeating back to them.
 */
export function groupReminders(reminders: ReminderRow[], now: Date = new Date()): ReminderGroups {
  const endOfToday = new Date(now)
  endOfToday.setHours(23, 59, 59, 999)

  const groups: ReminderGroups = { overdue: [], today: [], upcoming: [], completed: [] }

  for (const reminder of reminders) {
    if (reminder.status === 'cancelled') continue

    if (reminder.status === 'completed') {
      groups.completed.push(reminder)
      continue
    }

    const due = new Date(effectiveDue(reminder)).getTime()
    if (due <= now.getTime()) groups.overdue.push(reminder)
    else if (due <= endOfToday.getTime()) groups.today.push(reminder)
    else groups.upcoming.push(reminder)
  }

  const bySoonest = (a: ReminderRow, b: ReminderRow) => effectiveDue(a).localeCompare(effectiveDue(b))

  groups.overdue.sort(bySoonest)
  groups.today.sort(bySoonest)
  groups.upcoming.sort(bySoonest)
  // Most recently dealt with first — a completed list is read as history.
  groups.completed.sort((a, b) => (b.completed_at ?? '').localeCompare(a.completed_at ?? ''))

  return groups
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

/**
 * Every write moves a note between "has a reminder" and not, which is a field
 * on the note card, so both caches are refreshed rather than only this one.
 */
function invalidate(client: QueryClient): void {
  void client.invalidateQueries({ queryKey: queryKeys.reminders })
  void client.invalidateQueries({ queryKey: queryKeys.notes.all })
}

export interface ReminderInput {
  due_at: string
  timezone: string
  recurrence_rule: string | null
}

export function useCreateReminder() {
  const client = useQueryClient()

  return useMutation<ReminderRow, ApiError, ReminderInput & { noteId: string }>({
    mutationFn: ({ noteId, ...body }) => api.post<ReminderRow>(`/notes/${noteId}/reminders`, body),
    onSuccess: () => invalidate(client),
  })
}

export function useUpdateReminder() {
  const client = useQueryClient()

  return useMutation<ReminderRow, ApiError, Partial<ReminderInput> & { id: string }>({
    mutationFn: ({ id, ...patch }) => api.patch<ReminderRow>(`/reminders/${id}`, patch),
    onSuccess: () => invalidate(client),
  })
}

/** `minutes` or `until` — the server refuses both together, so the type does too. */
export type SnoozeInput = { id: string; minutes: number } | { id: string; until: string }

export function useSnoozeReminder() {
  const client = useQueryClient()

  return useMutation<ReminderRow, ApiError, SnoozeInput>({
    mutationFn: ({ id, ...body }) => api.post<ReminderRow>(`/reminders/${id}/snooze`, body),
    onSuccess: () => invalidate(client),
  })
}

/**
 * Tick one off.
 *
 * A recurring reminder is still there afterwards, moved to its next
 * occurrence — the server answers with the row rather than a 204 precisely so
 * the list can show the new date instead of removing the row.
 */
export function useCompleteReminder() {
  const client = useQueryClient()

  return useMutation<ReminderRow, ApiError, string>({
    mutationFn: (id) => api.post<ReminderRow>(`/reminders/${id}/complete`),
    onSuccess: () => invalidate(client),
  })
}

export function useDeleteReminder() {
  const client = useQueryClient()

  return useMutation<void, ApiError, string>({
    mutationFn: (id) => api.delete(`/reminders/${id}`),
    onSuccess: () => invalidate(client),
  })
}
