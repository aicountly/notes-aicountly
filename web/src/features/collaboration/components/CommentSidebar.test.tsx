/**
 * What the comment panel promises.
 *
 * The first test is the one this component exists for: a comment whose
 * paragraph has been rewritten is still shown, with the text it was written
 * against, because the alternative is silently deleting what somebody said.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { CommentSidebar } from './CommentSidebar'
import type { CommentThread } from '../hooks/useComments'
import type { Note, NoteCapabilities } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

vi.mock('../../../auth/AuthProvider', () => ({
  useAuth: () => ({
    status: 'authenticated',
    message: null,
    profile: { user_id: 'u_me', tenant_id: null, display_name: 'Me', email: 'me@example.com' },
    signIn: () => undefined,
    signOut: () => undefined,
  }),
}))

const COMMENTER: NoteCapabilities = {
  view: true, comment: true, edit: false,
  share: false, delete: false, restore: false, manage_members: false,
}

function makeNote(overrides: Partial<Note> = {}): Note {
  return {
    id: 'note-1',
    note_type: 'document',
    title: 'Quarterly review',
    display_title: 'Quarterly review',
    excerpt: '',
    notebook_id: null,
    color: null,
    is_pinned: false,
    is_favourite: false,
    is_archived: false,
    is_locked: false,
    privacy_mode: 'standard',
    version: 2,
    word_count: 10,
    char_count: 50,
    owner_user_id: 'u_other',
    created_at: '2026-09-01T09:00:00Z',
    updated_at: '2026-09-05T09:00:00Z',
    deleted_at: null,
    role: 'commenter',
    is_shared: true,
    attachment_count: 0,
    has_reminder: false,
    checklist: null,
    tags: [],
    document: { type: 'doc', content: [] },
    document_schema_version: 1,
    content_hash: 'abc',
    source: 'web',
    language: null,
    template_key: null,
    capabilities: COMMENTER,
    ...overrides,
  }
}

const CAN_ACT = { edit: true, delete: true, resolve: true, reply: true }
const READ_ONLY = { edit: false, delete: false, resolve: false, reply: false }

function threads(capabilities = CAN_ACT): CommentThread[] {
  return [
    {
      id: 'c1',
      parent_id: null,
      block_id: 'b-9',
      anchor_text: 'Margin held at 32 per cent',
      orphaned: true,
      body: 'Is this before or after the rebate?',
      author_user_id: 'u_me',
      mentions: [],
      is_resolved: false,
      edited: false,
      resolved_at: null,
      resolved_by: null,
      capabilities,
      created_at: new Date(Date.now() - 3_600_000).toISOString(),
      updated_at: new Date(Date.now() - 3_600_000).toISOString(),
      replies: [],
    },
    {
      id: 'c2',
      parent_id: null,
      block_id: null,
      anchor_text: null,
      body: 'Ready for Friday.',
      author_user_id: 'u_other',
      mentions: [],
      is_resolved: true,
      edited: false,
      resolved_at: new Date().toISOString(),
      resolved_by: 'u_me',
      capabilities,
      created_at: new Date(Date.now() - 7_200_000).toISOString(),
      updated_at: new Date(Date.now() - 7_200_000).toISOString(),
      replies: [],
    },
  ]
}

function envelope(data: unknown, meta?: Record<string, unknown>, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify({ success: true, data, meta }),
  } as unknown as Response
}

const fetchMock = vi.fn()

function renderSidebar(note: Note = makeNote(), capabilities = CAN_ACT) {
  fetchMock.mockImplementation(async (input: unknown, init?: RequestInit) => {
    const url = String(input)
    const method = init?.method ?? 'GET'

    if (url.includes('/members')) return envelope([])
    if (url.includes('/comments') && method === 'GET') {
      return envelope(threads(capabilities), { total: 2, unresolved: 1, has_more: false })
    }
    return envelope(threads(capabilities)[0], undefined, 201)
  })
  globalThis.fetch = fetchMock as unknown as typeof fetch

  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <CommentSidebar note={note} onClose={() => undefined} />
    </QueryClientProvider>,
  )
}

function writes(): { method: string; url: string; body: Record<string, unknown> }[] {
  return fetchMock.mock.calls
    .map(([url, init]) => ({ url: String(url), init: init as RequestInit | undefined }))
    .filter((call) => call.init?.method !== undefined && call.init.method !== 'GET')
    .map((call) => ({
      method: String(call.init?.method),
      url: call.url,
      body: call.init?.body ? (JSON.parse(String(call.init.body)) as Record<string, unknown>) : {},
    }))
}

beforeEach(() => {
  fetchMock.mockReset()
})

describe('CommentSidebar', () => {
  it('keeps a comment whose text was edited away, and says so', async () => {
    renderSidebar()

    expect(await screen.findByText('Is this before or after the rebate?')).toBeInTheDocument()
    expect(screen.getByText('The original text is no longer in this note.')).toBeInTheDocument()
    // The quotation it was written against is still there to read.
    expect(screen.getByText('Margin held at 32 per cent')).toBeInTheDocument()
  })

  it('posts a comment on the whole note', async () => {
    const user = userEvent.setup()
    renderSidebar()

    await user.type(await screen.findByLabelText('Add a comment'), 'Checked with Ross.')
    await user.click(screen.getByRole('button', { name: 'Comment' }))

    await waitFor(() => expect(writes()).toHaveLength(1))
    expect(writes()[0]).toMatchObject({
      method: 'POST',
      body: { body: 'Checked with Ross.', parent_id: null, block_id: null },
    })
  })

  it('resolves an open thread', async () => {
    const user = userEvent.setup()
    renderSidebar()

    await user.click(await screen.findByRole('button', { name: 'Resolve' }))

    await waitFor(() => expect(writes()).toHaveLength(1))
    expect(writes()[0].url).toContain('/comments/c1/resolve')
  })

  it('shows resolved threads only when they are asked for', async () => {
    const user = userEvent.setup()
    renderSidebar()

    expect(await screen.findByText('Is this before or after the rebate?')).toBeInTheDocument()
    expect(screen.queryByText('Ready for Friday.')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Resolved' }))

    expect(screen.getByText('Ready for Friday.')).toBeInTheDocument()
    expect(screen.queryByText('Is this before or after the rebate?')).not.toBeInTheDocument()
  })

  it('gives a viewer the discussion read-only, with the reason', async () => {
    renderSidebar(
      makeNote({ role: 'viewer', capabilities: { ...COMMENTER, comment: false } }),
      READ_ONLY,
    )

    expect(await screen.findByText('Is this before or after the rebate?')).toBeInTheDocument()
    expect(
      screen.getByText(/view-only access to this note, so you can read the discussion but not add to it/),
    ).toBeInTheDocument()
    expect(screen.queryByLabelText('Add a comment')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Resolve' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reply' })).not.toBeInTheDocument()
  })
})
