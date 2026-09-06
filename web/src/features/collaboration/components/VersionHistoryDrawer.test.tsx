/**
 * What the history panel promises.
 *
 * The two that matter most are about not losing anything: looking at an old
 * version must not change the note, and restoring must say — before it happens
 * — that what the note says now is kept too. The rest is the panel being
 * honest about who did what, and about what a viewer may not do.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { VersionHistoryDrawer, previewBlocks } from './VersionHistoryDrawer'
import type { Note, NoteCapabilities, NoteRevision } from '../../../shared/api/types'

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

const EDITOR_CAPABILITIES: NoteCapabilities = {
  view: true, comment: true, edit: true,
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
    version: 6,
    word_count: 40,
    char_count: 220,
    owner_user_id: 'u_me',
    created_at: '2026-09-01T09:00:00Z',
    updated_at: new Date(Date.now() - 3_600_000).toISOString(),
    deleted_at: null,
    role: 'editor',
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
    capabilities: EDITOR_CAPABILITIES,
    ...overrides,
  }
}

const REVISIONS: NoteRevision[] = [
  {
    id: 'rev-2',
    revision_number: 2,
    title: 'Quarterly review',
    reason: 'manual',
    created_by: 'u_me',
    created_at: new Date(Date.now() - 7_200_000).toISOString(),
    size: 220,
  },
  {
    id: 'rev-1',
    revision_number: 1,
    title: null,
    reason: 'autosave',
    created_by: 'u_gone',
    created_at: new Date(Date.now() - 86_400_000).toISOString(),
    size: 90,
  },
]

const REVISION_DETAIL = {
  id: 'rev-2',
  revision_number: 2,
  title: 'Quarterly review',
  reason: 'manual',
  created_by: 'u_me',
  created_at: REVISIONS[0].created_at,
  document: {
    type: 'doc',
    content: [
      { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Numbers' }] },
      { type: 'paragraph', content: [{ type: 'text', text: 'Margin held at 32 per cent.' }] },
      {
        type: 'bulletList',
        content: [
          {
            type: 'listItem',
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Chase the Ross invoice' }] }],
          },
        ],
      },
    ],
  },
}

function envelope(data: unknown, meta?: Record<string, unknown>, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify({ success: true, data, meta }),
  } as unknown as Response
}

const fetchMock = vi.fn()

function renderDrawer(note: Note = makeNote()) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <VersionHistoryDrawer note={note} onClose={() => undefined} />
    </QueryClientProvider>,
  )
}

function restoreCalls(): string[] {
  return fetchMock.mock.calls
    .filter(([, init]) => (init as RequestInit | undefined)?.method === 'POST')
    .map(([url]) => String(url))
}

beforeEach(() => {
  fetchMock.mockImplementation(async (input: unknown, init?: RequestInit) => {
    const url = String(input)
    const method = init?.method ?? 'GET'

    if (url.includes('/members')) return envelope([{ user_id: 'u_me', role: 'owner' }])
    if (url.endsWith('/restore') && method === 'POST') return envelope(makeNote())
    if (url.includes('/versions/rev-2')) return envelope(REVISION_DETAIL)
    if (url.includes('/versions')) return envelope(REVISIONS, { total: 2 })
    return envelope(null)
  })
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('VersionHistoryDrawer', () => {
  it('lists the checkpoints with who made them and why', async () => {
    renderDrawer()

    const first = await screen.findByRole('button', { name: /Version 2/ })
    // Named from the member list; "You" for the reader themselves.
    expect(first).toHaveTextContent('You')
    expect(first).toHaveTextContent('Saved by hand')

    // Somebody whose access has since been removed is not in the member list,
    // so they are "Someone" rather than a raw account id in a sentence.
    const second = screen.getByRole('button', { name: /Version 1/ })
    expect(second).toHaveTextContent('Someone')
    expect(second).toHaveTextContent('While writing')
  })

  it('previews a version read-only, changing nothing', async () => {
    const user = userEvent.setup()
    renderDrawer()

    await user.click(await screen.findByRole('button', { name: /Version 2/ }))

    expect(await screen.findByText('Numbers')).toBeInTheDocument()
    expect(screen.getByText('Margin held at 32 per cent.')).toBeInTheDocument()
    expect(screen.getByText('• Chase the Ross invoice')).toBeInTheDocument()
    // Looking is not restoring.
    expect(restoreCalls()).toEqual([])
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('asks before restoring, and says the current version is kept too', async () => {
    const user = userEvent.setup()
    renderDrawer()

    await user.click(await screen.findByRole('button', { name: /Version 2/ }))
    await user.click(await screen.findByRole('button', { name: 'Restore this version' }))

    const dialog = screen.getByRole('dialog')
    expect(dialog).toHaveAccessibleName('Restore version 2?')
    expect(dialog).toHaveTextContent(/checkpointed first, so nothing is lost/)
    // Nothing has been restored while the question is still on screen.
    expect(restoreCalls()).toEqual([])

    await user.click(screen.getByRole('button', { name: 'Restore' }))

    await waitFor(() => expect(restoreCalls()).toHaveLength(1))
    expect(restoreCalls()[0]).toContain('/notes/note-1/versions/rev-2/restore')
  })

  it('lets a viewer read history but not restore it, and says why', async () => {
    const user = userEvent.setup()
    renderDrawer(
      makeNote({ role: 'viewer', capabilities: { ...EDITOR_CAPABILITIES, edit: false } }),
    )

    await user.click(await screen.findByRole('button', { name: /Version 2/ }))

    expect(await screen.findByText('Numbers')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Restore this version' })).not.toBeInTheDocument()
    expect(screen.getByText(/view-only access .* but not\s+restore one/s)).toBeInTheDocument()
  })

  it('keeps the shape of a document that structure gives meaning to', () => {
    expect(previewBlocks(REVISION_DETAIL.document)).toEqual([
      { kind: 'heading', text: 'Numbers' },
      { kind: 'paragraph', text: 'Margin held at 32 per cent.' },
      { kind: 'list', text: '• Chase the Ross invoice' },
    ])
  })
})
