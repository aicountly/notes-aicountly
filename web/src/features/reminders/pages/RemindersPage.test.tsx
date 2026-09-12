/**
 * What the reminders page promises.
 *
 * The one that matters most is the last: **a repeating reminder that is
 * completed stays on the screen**. The server answers `/complete` with the
 * reminder moved on to its next occurrence precisely so the client can show
 * that, and a page that removed the row instead would teach people that
 * ticking off a repeating reminder cancels the series.
 *
 * The rest is the page being readable and honest: what is late is first, every
 * row can get you back to the note it is about, a control the server would
 * refuse is not offered, and a failure says what the server said.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import RemindersPage from './RemindersPage'
import type { ReminderRow } from '../hooks/useReminders'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

const HOUR = 3_600_000

function makeReminder(overrides: Partial<ReminderRow> = {}): ReminderRow {
  const due = overrides.due_at ?? new Date(Date.now() + HOUR).toISOString()

  return {
    id: 'rem-1',
    note_id: 'note-1',
    action_id: null,
    reminder_type: 'datetime',
    due_at: due,
    due_effective_at: due,
    timezone: 'Europe/London',
    recurrence_rule: null,
    recurrence: null,
    status: 'scheduled',
    snoozed_until: null,
    completed_at: null,
    notified_at: null,
    created_at: '2026-09-01T09:00:00Z',
    updated_at: '2026-09-01T09:00:00Z',
    note_title: 'Quarterly review',
    note_display_title: 'Quarterly review',
    note_type: 'document',
    ...overrides,
  }
}

/** Overdue, today, upcoming and completed — one of each, in server order. */
function fourReminders(): ReminderRow[] {
  const overdueAt = new Date(Date.now() - 2 * HOUR).toISOString()
  const laterAt = new Date(Date.now() + HOUR).toISOString()
  const nextWeekAt = new Date(Date.now() + 8 * 24 * HOUR).toISOString()
  const doneAt = new Date(Date.now() - 5 * 24 * HOUR).toISOString()

  return [
    makeReminder({ id: 'rem-done', note_id: 'note-4', note_display_title: 'Filed VAT', due_at: doneAt, due_effective_at: doneAt, status: 'completed', completed_at: doneAt }),
    makeReminder({ id: 'rem-overdue', note_id: 'note-1', note_display_title: 'Chase invoice', due_at: overdueAt, due_effective_at: overdueAt }),
    makeReminder({ id: 'rem-today', note_id: 'note-2', note_display_title: 'Call the auditor', due_at: laterAt, due_effective_at: laterAt }),
    makeReminder({ id: 'rem-later', note_id: 'note-3', note_display_title: 'Renew the lease', due_at: nextWeekAt, due_effective_at: nextWeekAt }),
  ]
}

const fetchMock = vi.fn()

function envelope(data: unknown, status = 200) {
  return {
    ok: true,
    status,
    text: async () => JSON.stringify({ success: true, data }),
  } as unknown as Response
}

function failure(code: string, message: string, status = 500) {
  return {
    ok: false,
    status,
    text: async () => JSON.stringify({ success: false, error: { code, message } }),
  } as unknown as Response
}

/** A 422, whose own message is generic and whose reason is in `fields`. */
function refusal(fields: Record<string, string>) {
  return {
    ok: false,
    status: 422,
    text: async () =>
      JSON.stringify({
        success: false,
        error: {
          code: 'VALIDATION_FAILED',
          message: 'Some fields need attention.',
          details: { fields },
        },
      }),
  } as unknown as Response
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <RemindersPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The list request, so a test can assert on what a mutation sent instead. */
function callsTo(fragment: string): { url: string; body: unknown }[] {
  return fetchMock.mock.calls
    .map(([input, init]) => ({ url: String(input), init: init as RequestInit | undefined }))
    .filter((call) => call.url.includes(fragment))
    .map((call) => ({
      url: call.url,
      body: typeof call.init?.body === 'string' ? (JSON.parse(call.init.body) as unknown) : undefined,
    }))
}

beforeEach(() => {
  fetchMock.mockImplementation(async () => envelope(fourReminders()))
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('RemindersPage', () => {
  it('groups reminders by when they are due, late ones first', async () => {
    renderPage()

    const headings = await screen.findAllByRole('heading', { level: 2 })
    expect(headings.map((heading) => heading.textContent)).toEqual([
      'Overdue1',
      'Today1',
      'Upcoming1',
      'Completed1',
    ])
  })

  it('links every row to the note it is about, by the note’s display title', async () => {
    renderPage()

    const link = await screen.findByRole('link', { name: 'Chase invoice' })
    expect(link).toHaveAttribute('href', '/notes/note-1')
    expect(screen.getByRole('link', { name: 'Renew the lease' })).toHaveAttribute('href', '/notes/note-3')
  })

  it('offers the four snooze choices and sends minutes for the relative ones', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Snooze the reminder for Chase invoice' }))

    const popup = screen.getByRole('dialog', { name: 'Snooze the reminder for Chase invoice' })
    expect(within(popup).getByRole('button', { name: '15 minutes' })).toBeInTheDocument()
    expect(within(popup).getByRole('button', { name: '1 hour' })).toBeInTheDocument()
    expect(within(popup).getByRole('button', { name: 'Tomorrow morning' })).toBeInTheDocument()
    // The fourth choice is a time of the user's own.
    expect(within(popup).getByLabelText('Date')).toBeInTheDocument()
    expect(within(popup).getByLabelText('Time')).toBeInTheDocument()

    fetchMock.mockImplementation(async (input: unknown) =>
      String(input).includes('/snooze')
        ? envelope(makeReminder({ id: 'rem-overdue', status: 'snoozed' }))
        : envelope(fourReminders()),
    )
    await user.click(within(popup).getByRole('button', { name: '15 minutes' }))

    await waitFor(() => expect(callsTo('/reminders/rem-overdue/snooze')).toHaveLength(1))
    // Minutes rather than an instant, so the server counts from its own clock.
    expect(callsTo('/reminders/rem-overdue/snooze')[0].body).toEqual({ minutes: 15 })
  })

  it('keeps a repeating reminder on screen when it is completed, and says where it went', async () => {
    const user = userEvent.setup()
    const nextWeek = new Date(Date.now() + 7 * 24 * HOUR).toISOString()

    const repeating = makeReminder({
      id: 'rem-weekly',
      note_display_title: 'Weekly payroll',
      reminder_type: 'recurring',
      recurrence_rule: 'FREQ=WEEKLY',
      recurrence: { frequency: 'WEEKLY', interval: 1, by_day: [], count: null, until: null },
      due_at: new Date(Date.now() - HOUR).toISOString(),
      due_effective_at: new Date(Date.now() - HOUR).toISOString(),
    })

    fetchMock.mockImplementation(async () => envelope([repeating]))
    renderPage()

    // The row says up front that this one repeats, so the tick is not a
    // surprise either way.
    expect(await screen.findByText('Every week')).toBeInTheDocument()

    const complete = screen.getByRole('button', {
      name: /Complete this occurrence of the reminder for Weekly payroll/,
    })

    fetchMock.mockImplementation(async (input: unknown) => {
      if (String(input).includes('/complete')) {
        // The server's answer: still scheduled, moved on a week.
        return envelope({ ...repeating, status: 'scheduled', due_at: nextWeek, due_effective_at: nextWeek })
      }
      return envelope([{ ...repeating, due_at: nextWeek, due_effective_at: nextWeek }])
    })

    await user.click(complete)

    // Said on the row itself, not only announced: the row is still there and
    // now sits at a different time, which needs explaining.
    await waitFor(() => {
      const row = screen.getByRole('link', { name: 'Weekly payroll' }).closest('li')
      expect(row).not.toBeNull()
      expect(within(row as HTMLElement).getByText(/It repeats, so the next one is/)).toBeInTheDocument()
    })
  })

  it('does not offer to snooze or complete a reminder the server would refuse', async () => {
    renderPage()

    await screen.findByRole('link', { name: 'Filed VAT' })

    expect(screen.queryByRole('button', { name: 'Snooze the reminder for Filed VAT' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Complete the reminder for Filed VAT/ })).not.toBeInTheDocument()
    // What is left is putting it back in the queue, or removing it.
    expect(
      screen.getByRole('button', { name: 'Set a new time for the reminder about Filed VAT' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete the reminder for Filed VAT' })).toBeInTheDocument()
  })

  it('deletes a reminder and takes the row away', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByRole('link', { name: 'Chase invoice' })

    fetchMock.mockImplementation(async (input: unknown) => {
      if (String(input).includes('/reminders/rem-overdue')) return envelope(null, 204)
      return envelope(fourReminders().filter((row) => row.id !== 'rem-overdue'))
    })

    await user.click(screen.getByRole('button', { name: 'Delete the reminder for Chase invoice' }))

    await waitFor(() => expect(screen.queryByRole('link', { name: 'Chase invoice' })).not.toBeInTheDocument())
  })

  it('offers a way back to the notes when there is nothing to be reminded about', async () => {
    fetchMock.mockImplementation(async () => envelope([]))
    renderPage()

    expect(await screen.findByText('No reminders yet')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Go to your notes' })).toBeInTheDocument()
  })

  it('shows the server’s own message when the list cannot be read', async () => {
    fetchMock.mockImplementation(async () =>
      failure('BAD_REQUEST', '`status` must be one of open, overdue, upcoming, completed, cancelled, all.', 400),
    )
    renderPage()

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('`status` must be one of open')
    expect(within(alert).getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  })

  it('says it is offline rather than blaming the user', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent(/offline/i)
  })

  it('says out loud that a reminder was deleted, because the row it was on is gone', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByRole('link', { name: 'Chase invoice' })

    fetchMock.mockImplementation(async (input: unknown) => {
      if (String(input).includes('/reminders/rem-overdue')) return envelope(null, 204)
      return envelope(fourReminders().filter((row) => row.id !== 'rem-overdue'))
    })

    await user.click(screen.getByRole('button', { name: 'Delete the reminder for Chase invoice' }))

    expect(await screen.findByText('Deleted the reminder for Chase invoice.')).toBeInTheDocument()
  })

  it('says why the server refused, not just that some fields need attention', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Snooze the reminder for Chase invoice' }))
    const popup = screen.getByRole('dialog', { name: 'Snooze the reminder for Chase invoice' })

    // The sentence a person can act on lives in `details.fields`; the 422's own
    // message is boilerplate.
    fetchMock.mockImplementation(async (input: unknown) =>
      String(input).includes('/snooze')
        ? refusal({ minutes: 'Snooze by 1 to 43200 minutes.' })
        : envelope(fourReminders()),
    )

    await user.click(within(popup).getByRole('button', { name: '15 minutes' }))

    expect(await screen.findByText('Snooze by 1 to 43200 minutes.')).toBeInTheDocument()
  })

  it('puts focus back on the snooze button after a choice is made', async () => {
    const user = userEvent.setup()
    renderPage()

    const trigger = await screen.findByRole('button', { name: 'Snooze the reminder for Chase invoice' })
    await user.click(trigger)

    fetchMock.mockImplementation(async (input: unknown) =>
      String(input).includes('/snooze')
        ? envelope(makeReminder({ id: 'rem-overdue', status: 'snoozed' }))
        : envelope(fourReminders()),
    )
    await user.click(screen.getByRole('button', { name: '1 hour' }))

    // The popup and the button inside it are gone; without this the keyboard
    // user is back at the top of the document.
    await waitFor(() => expect(trigger).toHaveFocus())
  })

  it('leaves the other reminders usable while one of them is saving', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByRole('link', { name: 'Chase invoice' })

    let release = () => {}
    const held = new Promise<void>((resolve) => {
      release = resolve
    })

    fetchMock.mockImplementation(async (input: unknown) => {
      if (String(input).includes('/complete')) {
        await held
        return envelope(makeReminder({ id: 'rem-overdue', status: 'completed' }))
      }
      return envelope(fourReminders())
    })

    await user.click(screen.getByRole('button', { name: /Complete the reminder for Chase invoice/ }))

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /Complete the reminder for Chase invoice/ })).toBeDisabled(),
    )
    // A different reminder is nothing to do with the one in flight.
    expect(screen.getByRole('button', { name: 'Delete the reminder for Call the auditor' })).toBeEnabled()
    expect(screen.getByRole('button', { name: /Complete the reminder for Call the auditor/ })).toBeEnabled()

    release()
    // On the row, and in the live region beside it.
    await waitFor(() => expect(screen.getAllByText('Done.')).not.toHaveLength(0))
  })

  it('narrows to what is still to do when asked', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByRole('link', { name: 'Filed VAT' })
    await user.click(screen.getByRole('button', { name: 'Still to do' }))

    await waitFor(() => expect(callsTo('status=open')).not.toHaveLength(0))
  })
})
