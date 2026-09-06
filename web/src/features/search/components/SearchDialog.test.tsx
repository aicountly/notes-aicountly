/**
 * Search, from the outside.
 *
 * The API client and the feature flags are stubbed because neither a network
 * nor a deployment exists in a test run; everything else is the real dialog,
 * driven by typing and by the arrow keys.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { api } from '../../../shared/api/client'
import { useFeature } from '../../../app/AppConfigProvider'
import type { NoteSummary } from '../../../shared/api/types'
import { SearchDialog } from './SearchDialog'

vi.mock('../../../shared/api/client', async () => {
  const actual =
    await vi.importActual<typeof import('../../../shared/api/client')>('../../../shared/api/client')

  return {
    ...actual,
    api: { ...actual.api, get: vi.fn(), getWithMeta: vi.fn(), request: vi.fn() },
  }
})

vi.mock('../../../app/AppConfigProvider', () => ({
  useFeature: vi.fn(() => false),
  useAppConfig: () => ({
    app: 'Notes',
    env: 'test',
    features: {},
    limits: { max_attachment_bytes: 1024, trash_retention_days: 30 },
  }),
}))

function noteRow(overrides: Partial<NoteSummary> & { snippet: string }) {
  return {
    id: 'note-1',
    note_type: 'document',
    title: 'Invoice for March',
    display_title: 'Invoice for March',
    excerpt: '',
    notebook_id: null,
    color: null,
    is_pinned: false,
    is_favourite: false,
    is_archived: false,
    is_locked: false,
    privacy_mode: 'standard',
    version: 1,
    word_count: 12,
    char_count: 80,
    owner_user_id: 'user-1',
    created_at: '2026-03-01T09:00:00Z',
    updated_at: '2026-03-02T09:00:00Z',
    deleted_at: null,
    role: 'owner',
    is_shared: false,
    attachment_count: 0,
    has_reminder: false,
    checklist: null,
    tags: [],
    score: 0.8,
    ...overrides,
  }
}

const EMPTY_SUGGESTIONS = { query: 'inv', notes: [], tags: [], notebooks: [] }

function LocationProbe() {
  const location = useLocation()
  return <span data-testid="location">{location.pathname}</span>
}

function renderDialog() {
  const onClose = vi.fn()
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/']}>
        <LocationProbe />
        <SearchDialog open onClose={onClose} />
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return { onClose, input: screen.getByRole('combobox') }
}

describe('SearchDialog', () => {
  beforeEach(() => {
    vi.mocked(useFeature).mockReturnValue(false)
    vi.mocked(api.get).mockResolvedValue(EMPTY_SUGGESTIONS)
    vi.mocked(api.getWithMeta).mockResolvedValue({
      data: [noteRow({ snippet: 'paid the [[hl]]invoice[[/hl]] <script>alert(1)</script>' })],
      meta: { has_more: false, highlight: { open: '[[hl]]', close: '[[/hl]]' } },
    })
  })

  it('marks the matched words and renders markup in a note as text', async () => {
    const { input } = renderDialog()

    fireEvent.change(input, { target: { value: 'invoice' } })

    const mark = await screen.findByText('invoice')
    expect(mark.tagName).toBe('MARK')

    const option = screen.getByRole('option')
    expect(option.querySelector('script')).toBeNull()
    expect(option).toHaveTextContent('<script>alert(1)</script>')
  })

  it('opens the highlighted result on Enter', async () => {
    const { input, onClose } = renderDialog()

    fireEvent.change(input, { target: { value: 'invoice' } })
    await screen.findByRole('option')

    fireEvent.keyDown(input, { key: 'ArrowDown' })
    fireEvent.keyDown(input, { key: 'Enter' })

    expect(onClose).toHaveBeenCalled()
    expect(screen.getByTestId('location')).toHaveTextContent('/notes/note-1')
  })

  it('sends one request for a burst of typing, and cancels nothing else', async () => {
    const { input } = renderDialog()

    fireEvent.change(input, { target: { value: 'i' } })
    fireEvent.change(input, { target: { value: 'in' } })
    fireEvent.change(input, { target: { value: 'inv' } })

    await screen.findByRole('option')
    await waitFor(() => expect(vi.mocked(api.getWithMeta)).toHaveBeenCalledTimes(1))
    expect(vi.mocked(api.getWithMeta).mock.calls[0][1]?.query).toMatchObject({ q: 'inv' })
  })

  it('offers to start a note when nothing matches', async () => {
    vi.mocked(api.getWithMeta).mockResolvedValue({ data: [], meta: { has_more: false } })
    const { input } = renderDialog()

    fireEvent.change(input, { target: { value: 'nothing here' } })

    expect(await screen.findByRole('button', { name: /New note/ })).toBeInTheDocument()
  })

  it('reports what the server said rather than an empty list', async () => {
    const { ApiError } = await vi.importActual<typeof import('../../../shared/api/client')>(
      '../../../shared/api/client',
    )
    vi.mocked(api.getWithMeta).mockRejectedValue(
      new ApiError('RATE_LIMITED', 'Too many searches. Try again in a minute.', 429),
    )

    const { input } = renderDialog()
    fireEvent.change(input, { target: { value: 'invoice' } })

    expect(await screen.findByText('Too many searches. Try again in a minute.')).toBeInTheDocument()
  })

  it('says when semantic search fell back to keywords', async () => {
    vi.mocked(useFeature).mockReturnValue(true)
    vi.mocked(api.request).mockResolvedValue({
      data: [noteRow({ snippet: 'an invoice' })],
      meta: { mode: 'keyword_fallback' },
    })

    const { input } = renderDialog()
    fireEvent.change(input, { target: { value: 'invoice' } })
    fireEvent.click(screen.getByLabelText('Search by meaning'))

    expect(
      await screen.findByText(/Keyword results — semantic search could not answer/),
    ).toBeInTheDocument()
  })
})
