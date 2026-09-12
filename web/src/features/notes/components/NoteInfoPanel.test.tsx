/**
 * The two things in this panel that used to be decoration.
 *
 * Tags were listed and could not be changed, while Help described "the tag
 * field on a note" that did not exist anywhere. And an attachment was a link
 * to an endpoint behind the session's Bearer token, which a browser
 * navigation does not send — so it opened a 401 envelope in a new tab instead
 * of the file.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { AppConfigProvider } from '../../../app/AppConfigProvider'
import { NoteInfoPanel } from './NoteInfoPanel'
import type { Note } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

const downloadAttachment = vi.fn()
// Only this one function is replaced. Mocking the whole module would take
// `attachmentContentUrl` and the hooks with it, which NoteCard — imported here
// for its time formatters — needs to render at all.
vi.mock('../../attachments/hooks/useAttachments', async () => {
  const actual = await vi.importActual<typeof import('../../attachments/hooks/useAttachments')>(
    '../../attachments/hooks/useAttachments',
  )
  return { ...actual, downloadAttachment: (...args: unknown[]) => downloadAttachment(...args) }
})

vi.mock('../../../auth/AuthProvider', () => ({
  useAuth: () => ({ profile: { user_id: 'user-1', display_name: 'Me' } }),
}))

const CONFIG = {
  app: 'Notes',
  env: 'test',
  features: {
    ai: false, semantic_search: false, ocr: false, transcription: false,
    canvas: false, realtime: false, private_notes: false, drive: false,
    calendar: false, contacts: false, connect: false,
  },
  limits: { max_attachment_bytes: 1024, trash_retention_days: 14 },
}

const ATTACHMENT = {
  id: 'att-1',
  note_id: 'note-1',
  filename: 'invoice.pdf',
  mime_type: 'application/pdf',
  kind: 'document',
  size_bytes: 1024,
  content_url: '/notes/note-1/attachments/att-1/content',
  processing_status: 'completed',
}

const writes: Array<{ method: string; url: string; body: unknown }> = []
const fetchMock = vi.fn()

function envelope(data: unknown) {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ success: true, data }),
  } as unknown as Response
}

function makeNote(overrides: Partial<Note> = {}): Note {
  return {
    id: 'note-1',
    note_type: 'document',
    title: 'Quarterly review',
    display_title: 'Quarterly review',
    excerpt: 'Numbers to check.',
    notebook_id: null,
    color: null,
    is_pinned: false,
    is_favourite: false,
    is_archived: false,
    is_locked: false,
    privacy_mode: 'standard',
    version: 3,
    word_count: 10,
    char_count: 50,
    owner_user_id: 'user-1',
    created_at: '2026-09-01T09:00:00Z',
    updated_at: '2026-09-05T09:00:00Z',
    deleted_at: null,
    role: 'owner',
    is_shared: false,
    attachment_count: 1,
    has_reminder: false,
    checklist: null,
    tags: [{ id: 'tag-1', name: 'gst', slug: 'gst', color: null }],
    document: { type: 'doc', content: [] },
    document_schema_version: 1,
    content_hash: '',
    source: 'web',
    language: null,
    template_key: null,
    capabilities: {
      view: true, comment: true, edit: true,
      share: true, delete: true, restore: true, manage_members: true,
    },
    backlink_count: 0,
    actions: [],
    ...overrides,
  } as unknown as Note
}

function renderPanel(note: Note = makeNote()): void {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <AppConfigProvider>
        <MemoryRouter initialEntries={['/notes/note-1']}>
          <NoteInfoPanel note={note} onClose={() => {}} />
        </MemoryRouter>
      </AppConfigProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  writes.length = 0
  downloadAttachment.mockReset().mockResolvedValue(undefined)
  fetchMock.mockReset().mockImplementation(async (input: unknown, init?: RequestInit) => {
    const url = String(input)
    const method = init?.method ?? 'GET'
    if (method !== 'GET') {
      writes.push({ method, url, body: init?.body ? JSON.parse(String(init.body)) : null })
      return envelope(makeNote())
    }
    if (url.includes('/config')) return envelope(CONFIG)
    // The panel builds { entries, total } from the array plus meta, so the
    // endpoint answers with an array like the real one does.
    if (url.includes('/versions')) return envelope([])
    if (url.includes('/attachments')) return envelope([ATTACHMENT])
    if (url.includes('/links')) return envelope({ outgoing: [], incoming: [] })
    if (url.includes('/activity')) return envelope([])
    if (url.includes('/members')) return envelope([])
    return envelope([])
  })
  vi.stubGlobal('fetch', fetchMock)
})

describe('NoteInfoPanel', () => {
  it('adds a tag from the field Help has always described', async () => {
    const user = userEvent.setup()
    renderPanel()

    await user.type(await screen.findByLabelText('Add a tag'), 'urgent{Enter}')

    await waitFor(() => expect(writes.length).toBe(1))
    // The whole list, because that is what PATCH /notes/{id} takes.
    expect(writes[0].body).toMatchObject({ tags: ['gst', 'urgent'] })
  })

  it('strips a leading hash, because #gst and gst are one tag', async () => {
    const user = userEvent.setup()
    renderPanel()

    await user.type(await screen.findByLabelText('Add a tag'), '#urgent{Enter}')

    await waitFor(() => expect(writes.length).toBe(1))
    expect(writes[0].body).toMatchObject({ tags: ['gst', 'urgent'] })
  })

  it('does not add one the note already carries', async () => {
    const user = userEvent.setup()
    renderPanel()

    await user.type(await screen.findByLabelText('Add a tag'), 'GST{Enter}')

    // Case-insensitively the same tag: the server would collapse it anyway,
    // and the list must not flash a duplicate in the meantime.
    expect(writes).toHaveLength(0)
  })

  it('removes a tag', async () => {
    const user = userEvent.setup()
    renderPanel()

    await user.click(await screen.findByRole('button', { name: 'Remove tag gst' }))

    await waitFor(() => expect(writes.length).toBe(1))
    expect(writes[0].body).toMatchObject({ tags: [] })
  })

  it('offers no tag field to someone who cannot edit the note', async () => {
    renderPanel(
      makeNote({
        capabilities: {
          view: true, comment: true, edit: false,
          share: false, delete: false, restore: false, manage_members: false,
        },
      } as Partial<Note>),
    )

    expect(await screen.findByText('gst')).toBeInTheDocument()
    expect(screen.queryByLabelText('Add a tag')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Remove tag gst' })).not.toBeInTheDocument()
  })

  it('fetches an attachment with the session rather than linking at it', async () => {
    const user = userEvent.setup()
    renderPanel()

    await user.click(await screen.findByRole('button', { name: /invoice\.pdf/ }))

    // A plain <a href> to the content endpoint sends no Authorization header,
    // so it opened a 401 JSON envelope instead of the file.
    await waitFor(() => expect(downloadAttachment).toHaveBeenCalledTimes(1))
    expect(downloadAttachment.mock.calls[0][0]).toMatchObject({ id: 'att-1' })
  })
})
