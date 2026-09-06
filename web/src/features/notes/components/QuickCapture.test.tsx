/**
 * What the composer promises.
 *
 * Capture is the product's first move, so the two things worth pinning down are
 * that it stays out of the way until it is used, and that saving is one gesture
 * that lands you in the note. The third is honesty: a deployment without
 * transcription must not offer a voice button that answers 503.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { QuickCapture } from './QuickCapture'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

// The flags are the deployment's answer, not the component's, so they are the
// one thing this test drives directly.
const { features } = vi.hoisted(() => ({
  features: { transcription: false, ocr: false, canvas: false } as Record<string, boolean>,
}))

vi.mock('../../../app/AppConfigProvider', () => ({
  useFeature: (flag: string) => features[flag] ?? false,
  useAppConfig: () => ({
    app: 'Notes',
    env: 'test',
    features,
    limits: { max_attachment_bytes: 1024 * 1024, trash_retention_days: 30 },
  }),
}))

const fetchMock = vi.fn()

function envelope(data: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify({ success: true, data }),
  } as unknown as Response
}

function Location() {
  return <span data-testid="location">{useLocation().pathname}</span>
}

function renderComposer(): void {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/']}>
        <QuickCapture />
        <Location />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  features.transcription = false
  features.ocr = false
  features.canvas = false

  fetchMock.mockImplementation(async () => envelope({ id: 'created-1', display_title: 'Untitled note' }))
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('QuickCapture', () => {
  it('is one line until it is used', () => {
    renderComposer()

    expect(screen.getByPlaceholderText('Take a note…')).toBeInTheDocument()
    expect(screen.queryByLabelText('Title')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument()
  })

  it('expands on focus and collapses on Escape', async () => {
    const user = userEvent.setup()
    renderComposer()

    await user.click(screen.getByLabelText('Note'))
    expect(screen.getByLabelText('Title')).toBeInTheDocument()

    await user.keyboard('{Escape}')
    expect(screen.queryByLabelText('Title')).not.toBeInTheDocument()
  })

  it('hides the shortcuts this deployment cannot honour', async () => {
    const user = userEvent.setup()
    renderComposer()

    await user.click(screen.getByLabelText('Note'))

    expect(screen.getByRole('button', { name: 'New checklist' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New voice note' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Scan a document' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New drawing' })).not.toBeInTheDocument()
  })

  it('offers the shortcuts this deployment does have', async () => {
    features.transcription = true
    features.canvas = true
    const user = userEvent.setup()
    renderComposer()

    await user.click(screen.getByLabelText('Note'))

    expect(screen.getByRole('button', { name: 'New voice note' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'New drawing' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Scan a document' })).not.toBeInTheDocument()
  })

  it('saves with Ctrl+Enter and opens the note it just made', async () => {
    const user = userEvent.setup()
    renderComposer()

    await user.click(screen.getByLabelText('Note'))
    await user.keyboard('Call the auditor back')
    await user.keyboard('{Control>}{Enter}{/Control}')

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/notes/created-1'))

    const [url, init] = fetchMock.mock.calls[fetchMock.mock.calls.length - 1] as [string, RequestInit]
    expect(String(url)).toContain('/notes')
    expect(init.method).toBe('POST')
    // The note carries what was typed, and an id the client chose, so the note
    // exists whether or not the server answers.
    const body = JSON.parse(String(init.body)) as { id?: string; document?: { content?: unknown[] } }
    expect(body.id).toBeTruthy()
    expect(JSON.stringify(body.document)).toContain('Call the auditor back')
  })

  it('will not save an empty note', async () => {
    const user = userEvent.setup()
    renderComposer()

    await user.click(screen.getByLabelText('Note'))
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()
  })
})
