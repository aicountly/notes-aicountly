/**
 * What a card promises.
 *
 * Three of these are about the same thing from different angles: the card opens
 * the note, and the controls sitting on top of it do not. That is the bug this
 * component exists to avoid — a pin that navigates, or a menu that opens the
 * note behind itself, makes the whole list feel broken.
 *
 * The rest cover the two rules a reviewer cannot see by looking: actions the
 * caller's role does not permit are absent, and deleting for good asks first.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { AppConfigProvider } from '../../../app/AppConfigProvider'
import { NoteCard } from './NoteCard'
import type { NoteSummary } from '../../../shared/api/types'

// The client mints a session key before every request; the portal that issues
// one is not part of what a card does.
vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

// The sharing dialog marks which member is you.
vi.mock('../../../auth/AuthProvider', () => ({
  useAuth: () => ({ profile: { user_id: 'user-1', display_name: 'Me' } }),
}))

const CONFIG = {
  app: 'Notes',
  env: 'test',
  features: {
    ai: false, semantic_search: false, ocr: false, transcription: false,
    canvas: false, private_notes: false, drive: false,
    calendar: false, contacts: false, connect: false,
  },
  limits: { max_attachment_bytes: 1024, trash_retention_days: 14 },
}

function envelope(data: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify({ success: true, data }),
  } as unknown as Response
}

const fetchMock = vi.fn()

function makeNote(overrides: Partial<NoteSummary> = {}): NoteSummary {
  return {
    id: 'note-1',
    note_type: 'document',
    title: 'Quarterly review',
    display_title: 'Quarterly review',
    excerpt: 'Numbers to check before Friday.',
    notebook_id: null,
    color: null,
    is_pinned: false,
    is_favourite: false,
    is_archived: false,
    is_locked: false,
    privacy_mode: 'standard',
    version: 3,
    word_count: 120,
    char_count: 640,
    owner_user_id: 'user-1',
    created_at: '2026-09-01T09:00:00Z',
    updated_at: '2026-09-05T09:00:00Z',
    deleted_at: null,
    role: 'owner',
    is_shared: false,
    attachment_count: 0,
    has_reminder: false,
    checklist: null,
    tags: [],
    ...overrides,
  }
}

function Location() {
  return <span data-testid="location">{useLocation().pathname}</span>
}

function renderCard(note: NoteSummary): void {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <AppConfigProvider>
        <MemoryRouter initialEntries={['/notes']}>
          <NoteCard note={note} to={`/notes/${note.id}`} />
          <Location />
        </MemoryRouter>
      </AppConfigProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  fetchMock.mockImplementation(async (input: unknown) => {
    const url = String(input)
    if (url.includes('/config')) return envelope(CONFIG)
    if (url.includes('/notebooks')) return envelope([])
    return envelope(makeNote({ is_pinned: true }))
  })
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('NoteCard', () => {
  it('opens the note when the card is clicked', () => {
    renderCard(makeNote())

    expect(screen.getByRole('link', { name: 'Quarterly review' })).toHaveAttribute('href', '/notes/note-1')
    expect(screen.getByText('Numbers to check before Friday.')).toBeInTheDocument()
  })

  it('pins from the card without opening the note', async () => {
    const user = userEvent.setup()
    renderCard(makeNote())

    await user.click(screen.getByRole('button', { name: /^pin quarterly review$/i }))

    await waitFor(() =>
      expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/notes/note-1/pin'))).toBe(true),
    )
    // The pin sits on top of the card's link; activating it must not navigate.
    expect(screen.getByTestId('location')).toHaveTextContent('/notes')
  })

  it('offers the owner every action, and closes on Escape', async () => {
    const user = userEvent.setup()
    renderCard(makeNote())

    await user.click(screen.getByRole('button', { name: /actions for quarterly review/i }))

    const menu = screen.getByRole('menu')
    expect(menu).toBeInTheDocument()
    for (const label of ['Pin', 'Colour', 'Move to notebook…', 'Duplicate', 'Copy link', 'Archive', 'Move to Trash']) {
      expect(screen.getByRole('menuitem', { name: label })).toBeInTheDocument()
    }

    await user.keyboard('{Escape}')

    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    // Focus has to come back, or a keyboard user is left on the page body.
    expect(screen.getByRole('button', { name: /actions for quarterly review/i })).toHaveFocus()
  })

  it('hides what a viewer is not allowed to do', async () => {
    const user = userEvent.setup()
    renderCard(makeNote({ role: 'viewer' }))

    await user.click(screen.getByRole('button', { name: /actions for quarterly review/i }))

    expect(screen.queryByRole('menuitem', { name: 'Move to Trash' })).not.toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Pin' })).not.toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Share…' })).not.toBeInTheDocument()
    // A viewer can still take their own copy and send someone the address.
    expect(screen.getByRole('menuitem', { name: 'Duplicate' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'Copy link' })).toBeInTheDocument()

    // Nor is there a pin on the card itself.
    expect(screen.queryByRole('button', { name: /^pin quarterly review$/i })).not.toBeInTheDocument()
  })

  it('asks before deleting a trashed note for good, and says how long it has left', async () => {
    const user = userEvent.setup()
    const fourDaysAgo = new Date(Date.now() - 4 * 86_400_000).toISOString()
    renderCard(makeNote({ deleted_at: fourDaysAgo }))

    // 14-day retention comes from the deployment's config, not a constant.
    expect(await screen.findByText('10d left')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /actions for quarterly review/i }))
    expect(screen.getByRole('menuitem', { name: 'Restore' })).toBeInTheDocument()
    await user.click(screen.getByRole('menuitem', { name: 'Delete forever' }))

    // Nothing is deleted until the dialog is confirmed.
    expect(screen.getByRole('dialog')).toHaveAccessibleName('Delete this note forever?')
    expect(fetchMock.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'DELETE')).toBe(false)

    await user.click(screen.getByRole('button', { name: 'Delete forever' }))

    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(
          ([url, init]) =>
            (init as RequestInit | undefined)?.method === 'DELETE' && String(url).includes('permanent=true'),
        ),
      ).toBe(true),
    )
  })
})

describe('sharing a note', () => {
  it('opens the sharing dialog rather than the operating system share sheet', async () => {
    const user = userEvent.setup()
    // A share sheet would pass a URL to whoever is picked — and the server
    // refuses them, because nothing granted them access. This is the control
    // that grants it, and until now nothing in the app rendered it.
    const osShare = vi.fn()
    vi.stubGlobal('navigator', { ...navigator, share: osShare })

    renderCard(makeNote({ role: 'owner' }))

    await user.click(screen.getByRole('button', { name: /actions for quarterly review/i }))
    await user.click(await screen.findByRole('menuitem', { name: 'Share…' }))

    expect(await screen.findByRole('dialog', { name: /share/i })).toBeInTheDocument()
    expect(osShare).not.toHaveBeenCalled()
  })
})
