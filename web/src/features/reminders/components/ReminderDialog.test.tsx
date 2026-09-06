/**
 * What the recurrence builder promises.
 *
 * These tests sit on the boundary between the builder and the server: the
 * controls offer only the RRULE parts `RecurrenceRule.php` accepts, the English
 * summary describes the rule that is actually sent, and a rule the server would
 * refuse never leaves the browser.
 *
 * They drive the dialog the way a person does — pick a frequency, click a day,
 * choose an ending — and assert on what the network sees, because the rule
 * string is the only part of this the user cannot check for themselves.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { ReminderDialog } from './ReminderDialog'
import type { ReminderRecord } from '../hooks/useReminders'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

const NOTE_ID = '8b1c0c2e-0000-4000-8000-0000000000aa'

const fetchMock = vi.fn()

function envelope(data: unknown) {
  return { ok: true, status: 200, text: async () => JSON.stringify({ success: true, data }) } as unknown as Response
}

function reminderRecord(overrides: Partial<ReminderRecord> = {}): ReminderRecord {
  return {
    id: '8b1c0c2e-0000-4000-8000-0000000000bb',
    note_id: NOTE_ID,
    action_id: null,
    reminder_type: 'recurring',
    due_at: '2026-10-05T09:00:00+00:00',
    due_effective_at: '2026-10-05T09:00:00+00:00',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    recurrence_rule: null,
    recurrence: null,
    status: 'scheduled',
    snoozed_until: null,
    completed_at: null,
    notified_at: null,
    created_at: '2026-09-06T09:00:00+00:00',
    updated_at: '2026-09-06T09:00:00+00:00',
    ...overrides,
  }
}

function renderDialog(reminder: ReminderRecord | null = null) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  const onClose = vi.fn()

  render(
    <QueryClientProvider client={client}>
      <ReminderDialog open noteId={NOTE_ID} noteTitle="Quarterly review" reminder={reminder} onClose={onClose} />
    </QueryClientProvider>,
  )

  return { onClose }
}

/** The body of the last request the client actually sent. */
function lastBody(): Record<string, unknown> {
  const [, init] = fetchMock.mock.calls[fetchMock.mock.calls.length - 1] as [string, RequestInit]
  return JSON.parse(String(init.body)) as Record<string, unknown>
}

async function save(user: ReturnType<typeof userEvent.setup>, label = 'Set reminder') {
  await user.click(screen.getByRole('button', { name: label }))
  await waitFor(() => expect(fetchMock).toHaveBeenCalled())
}

beforeEach(() => {
  fetchMock.mockImplementation(async () => envelope(reminderRecord()))
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('ReminderDialog recurrence', () => {
  it('starts as a one-off, with no repeat controls in the way', async () => {
    const user = userEvent.setup()
    renderDialog()

    expect(screen.getByLabelText('Repeat')).toHaveValue('NONE')
    expect(screen.queryByLabelText('Every')).not.toBeInTheDocument()

    await save(user)
    expect(lastBody().recurrence_rule).toBeNull()
  })

  it('offers weekday selection only where the server accepts it', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.selectOptions(screen.getByLabelText('Repeat'), 'WEEKLY')
    expect(screen.getByRole('button', { name: 'Monday' })).toBeInTheDocument()

    // `BYDAY` cannot be combined with `FREQ=DAILY`, so the control goes away
    // rather than composing a rule the server refuses.
    await user.selectOptions(screen.getByLabelText('Repeat'), 'DAILY')
    expect(screen.queryByRole('button', { name: 'Monday' })).not.toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Repeat'), 'MONTHLY')
    expect(screen.queryByRole('button', { name: 'Monday' })).not.toBeInTheDocument()
  })

  it('summarises the rule in English and sends exactly what it described', async () => {
    const user = userEvent.setup()
    renderDialog()

    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-10-05' } })
    fireEvent.change(screen.getByLabelText('Time'), { target: { value: '09:00' } })

    await user.selectOptions(screen.getByLabelText('Repeat'), 'WEEKLY')
    fireEvent.change(screen.getByLabelText('Every'), { target: { value: '2' } })
    await user.click(screen.getByRole('button', { name: 'Monday' }))
    await user.click(screen.getByRole('button', { name: 'Wednesday' }))

    await user.click(screen.getByLabelText('On a date'))
    fireEvent.change(screen.getByLabelText('Last date'), { target: { value: '2026-11-03' } })

    // The date's wording follows the reader's locale; the rest of the sentence
    // is the part that has to be right.
    expect(screen.getByText(/^Every 2 weeks on Mon, Wed, until /)).toBeInTheDocument()

    await save(user)
    expect(lastBody().recurrence_rule).toBe('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;UNTIL=20261103')
  })

  it('writes the weekdays in week order, not in the order they were clicked', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.selectOptions(screen.getByLabelText('Repeat'), 'WEEKLY')
    await user.click(screen.getByRole('button', { name: 'Friday' }))
    await user.click(screen.getByRole('button', { name: 'Tuesday' }))

    await save(user)
    expect(lastBody().recurrence_rule).toBe('FREQ=WEEKLY;BYDAY=TU,FR')
  })

  it('sends a count when the series ends after a number of times', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.selectOptions(screen.getByLabelText('Repeat'), 'MONTHLY')
    await user.click(screen.getByLabelText('After a number of times'))
    fireEvent.change(screen.getByLabelText('Number of times'), { target: { value: '6' } })

    expect(screen.getByText('Every month, 6 times')).toBeInTheDocument()

    await save(user)
    expect(lastBody().recurrence_rule).toBe('FREQ=MONTHLY;COUNT=6')
  })

  it('refuses an end date that falls before the reminder itself', async () => {
    const user = userEvent.setup()
    renderDialog()

    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-10-05' } })
    await user.selectOptions(screen.getByLabelText('Repeat'), 'WEEKLY')
    await user.click(screen.getByLabelText('On a date'))
    fireEvent.change(screen.getByLabelText('Last date'), { target: { value: '2026-09-01' } })

    expect(await screen.findByRole('alert')).toHaveTextContent('before the reminder itself')
    expect(screen.getByRole('button', { name: 'Set reminder' })).toBeDisabled()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('leaves a rule these controls cannot show exactly as the server stored it', async () => {
    const user = userEvent.setup()
    // `2TU` — "the second Tuesday" — is valid on the server and has no control
    // here. Rewriting it as "monthly" would silently change what fires.
    renderDialog(reminderRecord({ recurrence_rule: 'FREQ=MONTHLY;BYDAY=2TU' }))

    expect(screen.getByText(/cannot show/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Repeat')).not.toBeInTheDocument()

    await save(user, 'Save reminder')
    // Omitted rather than sent: the server leaves an absent rule alone.
    expect(lastBody()).not.toHaveProperty('recurrence_rule')
  })

  it('shows a refusal it has no control for rather than failing silently', async () => {
    const user = userEvent.setup()
    renderDialog()

    // `note_id` has no field in this form, and the 422 that carries it says
    // only "Some fields need attention." in its own message. Swallowing it
    // leaves a Save button that does nothing and explains nothing.
    fetchMock.mockImplementation(
      async () =>
        ({
          ok: false,
          status: 422,
          text: async () =>
            JSON.stringify({
              success: false,
              error: {
                code: 'VALIDATION_FAILED',
                message: 'Some fields need attention.',
                details: { fields: { note_id: 'You already have 25 reminders on this note.' } },
              },
            }),
        }) as unknown as Response,
    )

    await save(user)

    expect(await screen.findByText('You already have 25 reminders on this note.')).toBeInTheDocument()
  })

  it('replaces an unshowable rule only when the user asks for it', async () => {
    const user = userEvent.setup()
    renderDialog(reminderRecord({ recurrence_rule: 'FREQ=MONTHLY;BYDAY=2TU' }))

    await user.click(screen.getByRole('button', { name: 'Replace it' }))

    expect(screen.getByLabelText('Repeat')).toHaveValue('WEEKLY')
    expect(screen.getByText('Every week')).toBeInTheDocument()
  })
})
