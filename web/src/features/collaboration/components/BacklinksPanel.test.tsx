/**
 * Linked references.
 *
 * Two behaviours worth pinning: a note that mentions this one three times is
 * one row and not three, and an empty panel says how a link is made rather
 * than only that there are none.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { BacklinksPanel } from './BacklinksPanel'
import type { NoteLinks } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

function envelope(data: unknown): Response {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ success: true, data }),
  } as unknown as Response
}

const fetchMock = vi.fn()

function renderPanel(links: NoteLinks) {
  fetchMock.mockImplementation(async () => envelope(links))
  globalThis.fetch = fetchMock as unknown as typeof fetch

  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <BacklinksPanel noteId="note-1" />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  fetchMock.mockReset()
})

describe('BacklinksPanel', () => {
  it('groups references by the note they come from and links to it', async () => {
    renderPanel({
      incoming: [
        {
          note_id: 'note-2',
          title: 'Monday meeting',
          note_type: 'meeting',
          excerpt: 'Ross to confirm the quarterly review numbers',
          block_id: 'b-1',
          updated_at: '2026-09-04T09:00:00Z',
        },
        {
          note_id: 'note-2',
          title: 'Monday meeting',
          note_type: 'meeting',
          excerpt: 'Ross to confirm the quarterly review numbers',
          block_id: 'b-7',
          updated_at: '2026-09-04T09:00:00Z',
        },
      ],
      outgoing: [
        { note_id: 'note-3', title: 'Pricing', note_type: 'document', updated_at: '2026-09-02T09:00:00Z' },
      ],
    })

    const link = await screen.findByRole('link', { name: /Monday meeting/ })
    expect(link).toHaveAttribute('href', '/notes/note-2')
    expect(screen.getByText('2 references')).toBeInTheDocument()
    expect(screen.getByText('Linked references (1)')).toBeInTheDocument()

    expect(screen.getByRole('link', { name: /Pricing/ })).toHaveAttribute('href', '/notes/note-3')
  })

  it('explains how linking works when nothing links here', async () => {
    renderPanel({ incoming: [], outgoing: [] })

    expect(await screen.findByText('Nothing links here yet')).toBeInTheDocument()
    expect(screen.getByText(/\[\[Note title\]\]/)).toBeInTheDocument()
    expect(screen.getByText('This note does not link to another note yet.')).toBeInTheDocument()
  })
})
