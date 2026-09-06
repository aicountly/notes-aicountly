/**
 * What Pulse promises, and what it promises not to do.
 *
 * The first test is the important one. A deployment without `features.ai` has
 * no Pulse at all, and the product rule is that the user must never meet a
 * control for it — not a greyed-out one, not a "coming soon". The only way to
 * be sure of that is to render the panel with the flag off and assert there is
 * nothing there.
 *
 * The rest cover the two claims an answer makes about itself: where it came
 * from, and whether it came from the user's notes at all.
 */

import type { ComponentProps } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { PulsePanel } from './PulsePanel'
import { PULSE_UNAVAILABLE } from './PulseNotice'
import type { PulseAnswer } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

const { features } = vi.hoisted(() => ({ features: { ai: true } as Record<string, boolean> }))

vi.mock('../../../app/AppConfigProvider', () => ({
  useFeature: (flag: string) => features[flag] ?? false,
}))

const NOTE_ID = '8b1c0c2e-0000-4000-8000-0000000000aa'
const SOURCE_ID = '8b1c0c2e-0000-4000-8000-0000000000cc'
const NOTEBOOK_ID = '8b1c0c2e-0000-4000-8000-0000000000bb'

const fetchMock = vi.fn()

function envelope(data: unknown) {
  return { ok: true, status: 200, text: async () => JSON.stringify({ success: true, data }) } as unknown as Response
}

function answer(overrides: Partial<PulseAnswer> = {}): PulseAnswer {
  return {
    answer: 'The renewal is due on 3 November.',
    grounded: true,
    model: 'test-model',
    citations: [
      {
        note_id: SOURCE_ID,
        title: 'Acme contract',
        block_id: null,
        snippet: 'Renewal falls due on 3 November unless cancelled 30 days before.',
      },
    ],
    ...overrides,
  }
}

function renderPanel(props: Partial<ComponentProps<typeof PulsePanel>> = {}) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <PulsePanel noteId={NOTE_ID} noteTitle="Acme contract" {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The one request the panel made, as the server would read it. */
function sent(): { url: string; body: unknown } {
  const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]

  return { url, body: JSON.parse(String(init.body)) as unknown }
}

async function ask(user: ReturnType<typeof userEvent.setup>, question = 'When does the contract renew?') {
  await user.type(screen.getByLabelText('Your question'), question)
  await user.click(screen.getByRole('button', { name: 'Ask' }))
}

beforeEach(() => {
  features.ai = true
  fetchMock.mockImplementation(async () => envelope(answer()))
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('PulsePanel when Pulse is switched off', () => {
  it('offers no control at all — one line saying why, and nothing to press', () => {
    features.ai = false
    renderPanel()

    expect(screen.getByText(PULSE_UNAVAILABLE)).toBeInTheDocument()

    // Not a disabled button, not a "coming soon": there is nothing here that
    // looks like it could be made to work.
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Your question')).not.toBeInTheDocument()
    expect(screen.queryByRole('radio')).not.toBeInTheDocument()
  })

  it('does not ask the server for anything, not even the action catalogue', () => {
    features.ai = false
    renderPanel()

    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('PulsePanel', () => {
  it('offers only the scopes it has something to answer from', () => {
    renderPanel()

    expect(screen.getByLabelText('This note')).toBeInTheDocument()
    expect(screen.getByLabelText('All my notes')).toBeInTheDocument()
    // No notebook was given, so answering "this notebook" is not on offer.
    expect(screen.queryByLabelText('This notebook')).not.toBeInTheDocument()
  })

  it('shows the answer with a link to every note it came from', async () => {
    const user = userEvent.setup()
    renderPanel()

    await ask(user)

    expect(await screen.findByText('The renewal is due on 3 November.')).toBeInTheDocument()

    const source = screen.getByRole('link', { name: /Acme contract/ })
    expect(source).toHaveAttribute('href', `/notes/${SOURCE_ID}`)
    expect(screen.getByText(/Renewal falls due on 3 November/)).toBeInTheDocument()
  })

  it('says so above the answer when Pulse did not use the notes', async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(async () =>
      envelope(answer({ grounded: false, citations: [], answer: 'Contracts usually renew annually.' })),
    )
    renderPanel()

    await ask(user)

    const turn = await screen.findByRole('listitem')
    const warning = within(turn).getByText('Pulse answered from general knowledge, not from your notes.')
    const prose = within(turn).getByText('Contracts usually renew annually.')

    // Above the answer, not under it: the label has to be read before the
    // sentence it qualifies, or it is not a label at all.
    expect(warning.compareDocumentPosition(prose) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()

    // An ungrounded answer has nothing to cite, so no sources section is drawn
    // rather than an empty one.
    expect(screen.queryByRole('region', { name: 'Sources for this answer' })).not.toBeInTheDocument()
  })

  it('shows real progress while it waits, and lets the wait be cancelled', async () => {
    const user = userEvent.setup()
    // A request that never settles unless the client aborts it.
    fetchMock.mockImplementation(
      (_url: unknown, init: RequestInit) =>
        new Promise((_resolve, reject) => {
          init.signal?.addEventListener('abort', () => {
            const abort = new Error('aborted')
            abort.name = 'AbortError'
            reject(abort)
          })
        }),
    )
    renderPanel()

    await ask(user)

    expect(await screen.findByText(/Reading this note to answer/)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    // The wait is gone, the question is back in the box rather than lost, and
    // a cancellation is not reported as a failure.
    await waitFor(() => expect(screen.queryByText(/Reading this note to answer/)).not.toBeInTheDocument())
    expect(screen.getByLabelText('Your question')).toHaveValue('When does the contract renew?')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('sends a question to the endpoint the chosen scope names, with only that scope’s id', async () => {
    const user = userEvent.setup()
    renderPanel({ notebookId: NOTEBOOK_ID, notebookName: 'Contracts' })

    await user.click(screen.getByLabelText('This notebook'))
    await ask(user)

    await waitFor(() => expect(fetchMock).toHaveBeenCalled())
    expect(sent().url).toContain(`/pulse/notebook/${NOTEBOOK_ID}/ask`)
  })

  it('answers “All my notes” from all of them, not from whichever notebook is open', async () => {
    const user = userEvent.setup()
    renderPanel({ notebookId: NOTEBOOK_ID, notebookName: 'Contracts' })

    await user.click(screen.getByLabelText('All my notes'))
    await ask(user)

    await waitFor(() => expect(fetchMock).toHaveBeenCalled())
    const { url, body } = sent()

    // `notebook_id` in this body would make the server scope retrieval to that
    // one notebook — which is the option immediately above this one. The
    // widest scope has to actually be the widest, or the two radios are one.
    expect(url).toContain('/pulse/notes/ask')
    expect(body).toEqual({ question: 'When does the contract renew?' })
  })

  it('keeps the question when the server refuses, and shows what it said', async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(async () => ({
      ok: false,
      status: 429,
      text: async () =>
        JSON.stringify({
          success: false,
          error: { code: 'RATE_LIMITED', message: 'Pulse is busy. Try again in a minute.' },
        }),
    }))
    renderPanel()

    await ask(user)

    expect(await screen.findByRole('alert')).toHaveTextContent('Pulse is busy. Try again in a minute.')
    expect(screen.getByLabelText('Your question')).toHaveValue('When does the contract renew?')
  })
})
