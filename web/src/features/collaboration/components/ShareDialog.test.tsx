/**
 * What sharing promises.
 *
 * These cover the rules a reviewer cannot see by reading the markup: the role
 * picker never offers ownership, the roles are explained rather than named, a
 * person who cannot manage sharing gets the list without the controls, and a
 * private note says why it cannot be shared instead of failing on submit.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { ShareDialog } from './ShareDialog'
import type { Note, NoteCapabilities, NoteMember } from '../../../shared/api/types'

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

const OWNER_CAPABILITIES: NoteCapabilities = {
  view: true, comment: true, edit: true,
  share: true, delete: true, restore: true, manage_members: true,
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
    version: 4,
    word_count: 10,
    char_count: 50,
    owner_user_id: 'u_me',
    created_at: '2026-09-01T09:00:00Z',
    updated_at: '2026-09-05T09:00:00Z',
    deleted_at: null,
    role: 'owner',
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
    capabilities: OWNER_CAPABILITIES,
    ...overrides,
  }
}

const MEMBERS: NoteMember[] = [
  { user_id: 'u_me', role: 'owner' },
  { user_id: 'u_priya', role: 'commenter' },
]

function envelope(data: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify({ success: status < 400, data }),
  } as unknown as Response
}

function failure(code: string, message: string, status: number): Response {
  return {
    ok: false,
    status,
    text: async () => JSON.stringify({ success: false, error: { code, message } }),
  } as unknown as Response
}

const fetchMock = vi.fn()

function renderDialog(note: Note = makeNote()) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <ShareDialog note={note} open onClose={() => undefined} />
    </QueryClientProvider>,
  )
}

/** The request bodies this dialog sent, in order. */
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
  fetchMock.mockImplementation(async (input: unknown, init?: RequestInit) => {
    const url = String(input)
    const method = init?.method ?? 'GET'

    if (url.includes('/members') && method === 'GET') return envelope(MEMBERS)
    if (url.includes('/members') && method === 'POST') return envelope({ user_id: 'u_sam', role: 'editor' }, 201)
    if (url.includes('/members') && method === 'PATCH') return envelope({ user_id: 'u_priya', role: 'editor' })
    if (url.includes('/members') && method === 'DELETE') return envelope(null, 204)
    return envelope(null)
  })
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('ShareDialog', () => {
  it('offers the three grantable roles and explains each one', async () => {
    renderDialog()

    const picker = await screen.findByLabelText('Access')
    const options = [...picker.querySelectorAll('option')].map((option) => option.textContent)

    expect(options).toEqual(['Can view', 'Can comment', 'Can edit'])
    // Ownership is not something sharing hands over, so it is not on offer.
    expect(options).not.toContain('Owner')

    // And "commenter" is explained rather than left as a word to guess at.
    expect(
      screen.getByText(/Can read the note and leave comments, but cannot change a word of it/),
    ).toBeInTheDocument()
  })

  it('shares with the role that was chosen', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.type(await screen.findByLabelText('AICOUNTLY account id'), 'u_sam')
    await user.selectOptions(screen.getByLabelText('Access'), 'editor')
    await user.click(screen.getByRole('button', { name: 'Share' }))

    await waitFor(() => expect(writes()).toHaveLength(1))
    expect(writes()[0]).toMatchObject({
      method: 'POST',
      body: { user_id: 'u_sam', role: 'editor' },
    })
  })

  it('changes an existing member’s role without touching the owner’s', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.selectOptions(await screen.findByLabelText('Access for u_priya'), 'editor')

    await waitFor(() => expect(writes()).toHaveLength(1))
    expect(writes()[0].method).toBe('PATCH')
    expect(writes()[0].url).toContain('/notes/note-1/members/u_priya')
    expect(writes()[0].body).toEqual({ role: 'editor' })

    // The owner's row is a badge, not a picker: their role is not editable here.
    expect(screen.queryByLabelText('Access for u_me')).not.toBeInTheDocument()
    expect(screen.getByText('Owner')).toBeInTheDocument()
  })

  it('removes a member', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.click(await screen.findByRole('button', { name: 'Remove u_priya' }))

    await waitFor(() => expect(writes()).toHaveLength(1))
    expect(writes()[0].method).toBe('DELETE')
    expect(writes()[0].url).toContain('/notes/note-1/members/u_priya')
  })

  it('shows the list read-only to someone who cannot manage sharing', async () => {
    renderDialog(
      makeNote({
        role: 'editor',
        capabilities: { ...OWNER_CAPABILITIES, share: false, manage_members: false },
      }),
    )

    expect(await screen.findByText(/Only the owner can change who this note is shared with/)).toBeInTheDocument()
    expect(screen.queryByLabelText('AICOUNTLY account id')).not.toBeInTheDocument()
    // Everyone who can open the note can still see who else is in it.
    expect(await screen.findByText('u_priya')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Remove u_priya' })).not.toBeInTheDocument()
    expect(screen.getByText('Can comment')).toBeInTheDocument()
  })

  it('says why a private note cannot be shared instead of offering a form', async () => {
    renderDialog(makeNote({ privacy_mode: 'private' }))

    expect(await screen.findByText(/only ever be opened by you/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Share' })).not.toBeInTheDocument()
  })

  it('explains a refusal in the server’s own words', async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(async (input: unknown, init?: RequestInit) => {
      const url = String(input)
      const method = init?.method ?? 'GET'
      if (url.includes('/members') && method === 'GET') return envelope(MEMBERS)
      return failure('VALIDATION_FAILED', 'You already have access to this note.', 422)
    })

    renderDialog()

    await user.type(await screen.findByLabelText('AICOUNTLY account id'), 'u_me')
    await user.click(screen.getByRole('button', { name: 'Share' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('You already have access to this note.')
  })
})
