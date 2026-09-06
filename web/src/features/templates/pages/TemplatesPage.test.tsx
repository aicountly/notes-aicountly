/**
 * What the Templates page promises.
 *
 * The page's whole job is to be honest about two things the server decides and
 * the browser cannot: who owns a template, and what one will write. So the
 * tests are about exactly that — the scopes stay in order, the editing controls
 * appear only where `editable` says an edit would be accepted, the preview
 * shows the document as a document rather than as JSON, and a template this
 * deployment could not turn into a note says so instead of failing on click.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import TemplatesPage from './TemplatesPage'
import { DEFAULT_NOTEBOOK_KEY } from '../../settings/preferences'
import type { NoteDocument, NoteTemplate } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

vi.mock('../../../auth/AuthProvider', () => ({
  useAuth: () => ({
    profile: { user_id: 'user-1', tenant_id: 'acme', display_name: 'Ada', email: 'ada@acme.test' },
  }),
}))

const { features } = vi.hoisted(() => ({ features: { canvas: false } as Record<string, boolean> }))

vi.mock('../../../app/AppConfigProvider', () => ({
  useAppConfig: () => ({
    app: 'Notes',
    env: 'test',
    features,
    limits: { max_attachment_bytes: 1024, trash_retention_days: 30 },
  }),
  useFeature: (flag: string) => features[flag] ?? false,
}))

function template(partial: Partial<NoteTemplate>): NoteTemplate {
  return {
    id: 'template',
    scope: 'system',
    template_key: null,
    name: 'A template',
    description: null,
    icon: null,
    note_type: 'document',
    title_template: null,
    default_tags: [],
    position: 0,
    editable: false,
    ...partial,
  }
}

const TEMPLATES: NoteTemplate[] = [
  template({
    id: 't-meeting',
    name: 'Meeting notes',
    template_key: 'meeting-notes',
    note_type: 'meeting',
    title_template: 'Meeting — {{date}}',
  }),
  template({ id: 't-canvas', name: 'Launch canvas', note_type: 'canvas' }),
  template({
    id: 't-call',
    name: 'Client call',
    scope: 'tenant',
    description: 'What the firm agreed on.',
  }),
  template({
    id: 't-weekly',
    name: 'Weekly review',
    scope: 'user',
    editable: true,
    default_tags: ['review'],
    title_template: 'Weekly review — week of {{date}}',
  }),
]

const MEETING_DOCUMENT: NoteDocument = {
  type: 'doc',
  content: [
    { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Agenda' }] },
    {
      type: 'taskList',
      content: [
        {
          type: 'taskItem',
          attrs: { checked: false },
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Review last week' }] }],
        },
      ],
    },
    { type: 'paragraph' },
  ],
}

const NOTES = [
  { id: 'note-1', display_title: 'Board pack', privacy_mode: 'standard' },
  { id: 'note-2', display_title: 'Salary review', privacy_mode: 'private' },
]

function envelope(data: unknown, status = 200) {
  return {
    ok: status < 400,
    status,
    text: async () => JSON.stringify({ success: status < 400, data }),
  } as unknown as Response
}

function failure(status: number, code: string, message: string) {
  return {
    ok: false,
    status,
    text: async () => JSON.stringify({ success: false, error: { code, message } }),
  } as unknown as Response
}

const fetchMock = vi.fn()

function respond(templates: NoteTemplate[] = TEMPLATES) {
  return async (input: unknown, init?: RequestInit) => {
    const url = String(input)
    const method = init?.method ?? 'GET'

    if (url.includes('/create-note')) return envelope({ id: 'note-42' }, 201)
    if (method === 'DELETE') return { ok: true, status: 204, text: async () => '' } as unknown as Response
    if (url.includes('/templates/')) return envelope({ ...templates[0], document: MEETING_DOCUMENT })
    if (url.includes('/templates')) {
      return method === 'POST' ? envelope(templates[3], 201) : envelope(templates)
    }
    if (url.includes('/notes')) return envelope(NOTES)
    if (url.includes('/tags')) return envelope([])
    return envelope(null)
  }
}

beforeEach(() => {
  features.canvas = false
  window.localStorage.clear()
  fetchMock.mockImplementation(respond())
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/templates']}>
        <Routes>
          <Route path="/templates" element={<TemplatesPage />} />
          <Route path="/notes/:noteId" element={<p>the note editor</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The card a template is shown in. */
async function card(name: string): Promise<HTMLElement> {
  const heading = await screen.findByRole('heading', { name, level: 3 })
  const item = heading.closest('li')
  if (item === null) throw new Error(`No card for ${name}`)
  return item
}

function writes(method: string): [string, RequestInit][] {
  return fetchMock.mock.calls.filter(
    (call) => (call[1] as RequestInit | undefined)?.method === method,
  ) as [string, RequestInit][]
}

describe('TemplatesPage', () => {
  it('keeps the three scopes in the order the server sends them', async () => {
    renderPage()

    await screen.findByRole('heading', { name: 'Built in', level: 2 })
    const groups = screen.getAllByRole('heading', { level: 2 })

    expect(groups.map((heading) => heading.textContent)).toEqual([
      'Built in',
      'Shared with your company',
      'Yours',
    ])
  })

  it('offers editing only on the templates the server says are editable', async () => {
    renderPage()
    await screen.findByRole('heading', { name: 'Built in', level: 2 })

    // A built-in and a colleague's are read-only, so there is no menu to press.
    expect(screen.queryByRole('button', { name: 'Actions for Meeting notes' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Actions for Client call' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Actions for Weekly review' })).toBeInTheDocument()
  })

  it('previews the document as a document, and says why a built-in cannot be changed', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(within(await card('Meeting notes')).getByRole('button', { name: 'Preview' }))

    const dialog = await screen.findByRole('dialog')

    // Rendered structurally: a heading is a heading and a checklist item reads
    // as one, rather than a dump of the stored JSON.
    expect(await within(dialog).findByRole('heading', { name: 'Agenda' })).toBeInTheDocument()
    expect(within(dialog).getByText('Review last week')).toBeInTheDocument()
    expect(within(dialog).getByText('To do:')).toBeInTheDocument()
    expect(within(dialog).queryByText(/"type"/)).not.toBeInTheDocument()

    // The title pattern is shown resolved, because that is what the note gets.
    expect(within(dialog).getByText(`“Meeting — ${today()}”`)).toBeInTheDocument()

    expect(
      within(dialog).getByText(/Built-in templates cannot be changed/i),
    ).toBeInTheDocument()
  })

  it('starts a note from a template and opens it', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(within(await card('Weekly review')).getByRole('button', { name: 'Use template' }))

    await waitFor(() => expect(screen.getByText('the note editor')).toBeInTheDocument())

    const [url, init] = writes('POST')[0]
    expect(url).toContain('/templates/t-weekly/create-note')
    // The timezone travels with the request, or `{{date}}` resolves in UTC.
    expect(JSON.parse(String(init.body))).toHaveProperty('timezone')
  })

  it('disables a template whose note type this deployment has switched off, and says why', async () => {
    const user = userEvent.setup()
    renderPage()

    const canvas = await card('Launch canvas')
    expect(within(canvas).getByRole('button', { name: 'Use template' })).toBeDisabled()
    expect(within(canvas).getByText(/Canvas notes are switched off/i)).toBeInTheDocument()

    // Nothing was sent: the button is off, not merely styled as off.
    await user.click(within(canvas).getByRole('button', { name: 'Use template' }))
    expect(writes('POST')).toHaveLength(0)
  })

  it('names the two title tokens the server substitutes, and shows the result', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Actions for Weekly review' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Edit template' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('{{date}}')).toBeInTheDocument()
    expect(within(dialog).getByText('{{time}}')).toBeInTheDocument()
    expect(
      within(dialog).getByText(`A note made now would be called “Weekly review — week of ${today()}”.`),
    ).toBeInTheDocument()
  })

  it('saves an existing note as a template without overruling what the note decides', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'New template' }))
    await user.type(await screen.findByLabelText('Name'), 'Board pack')

    const source = await screen.findByLabelText('Start from')
    await waitFor(() =>
      expect(within(source).getByRole('option', { name: 'Board pack' })).toBeInTheDocument(),
    )
    await user.selectOptions(source, 'note-1')

    await user.click(screen.getByRole('button', { name: 'Create template' }))

    await waitFor(() => expect(writes('POST')).toHaveLength(1))
    const body = JSON.parse(String(writes('POST')[0][1].body)) as Record<string, unknown>

    expect(body.from_note_id).toBe('note-1')
    expect(body.scope).toBe('user')
    // Left out so the note's own type and tags survive the copy.
    expect(body).not.toHaveProperty('note_type')
    expect(body).not.toHaveProperty('default_tags')
  })

  it('will not offer a private note as the source, because the server cannot read one', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'New template' }))
    const source = await screen.findByLabelText('Start from')

    await waitFor(() =>
      expect(
        within(source).getByRole('option', { name: /Salary review — private, cannot be copied/ }),
      ).toBeDisabled(),
    )
  })

  it('asks before deleting, and says what deleting does not take with it', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Actions for Weekly review' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Delete template' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Notes already started from this template keep/i)).toBeInTheDocument()
    expect(writes('DELETE')).toHaveLength(0)

    await user.click(within(dialog).getByRole('button', { name: 'Delete template' }))

    await waitFor(() => expect(writes('DELETE')).toHaveLength(1))
    expect(writes('DELETE')[0][0]).toContain('/templates/t-weekly')
  })

  it('shows the server’s own message when the list cannot be read, with a way to retry', async () => {
    fetchMock.mockImplementation(async () =>
      failure(503, 'DATABASE_NOT_CONFIGURED', 'Templates are unavailable on this deployment.'),
    )
    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Templates are unavailable on this deployment.',
    )
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  })

  it('files the note in the notebook the user chose, exactly as the picker does', async () => {
    const user = userEvent.setup()
    window.localStorage.setItem(DEFAULT_NOTEBOOK_KEY, 'nb-inbox')
    renderPage()

    await user.click(within(await card('Weekly review')).getByRole('button', { name: 'Use template' }))
    await waitFor(() => expect(writes('POST')).toHaveLength(1))

    expect(JSON.parse(String(writes('POST')[0][1].body))).toMatchObject({ notebook_id: 'nb-inbox' })
  })

  it('shows a failed start inside the preview, not on the page behind it', async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(async (input: unknown, init?: RequestInit) => {
      if (String(input).includes('/create-note')) {
        return failure(403, 'FEATURE_DISABLED', 'Notes cannot be created right now.')
      }
      return respond()(input, init)
    })
    renderPage()

    await user.click(within(await card('Weekly review')).getByRole('button', { name: 'Preview' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Use template' }))

    // Inside the dialog: a notice under the overlay is a notice nobody reads.
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Notes cannot be created right now.',
    )
  })

  it('reports a rejection this form has no field to put it beside', async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(async (input: unknown, init?: RequestInit) => {
      const url = String(input)
      if ((init?.method ?? 'GET') === 'POST' && url.endsWith('/templates')) {
        return {
          ok: false,
          status: 422,
          text: async () =>
            JSON.stringify({
              success: false,
              error: {
                code: 'VALIDATION_FAILED',
                message: 'That template could not be saved.',
                details: { fields: { note_type: 'Unknown note type.' } },
              },
            }),
        } as unknown as Response
      }
      return respond()(input, init)
    })
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'New template' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Name'), 'Weekly')
    await user.click(within(dialog).getByRole('button', { name: 'Create template' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'That template could not be saved.',
    )
  })

  it('still saves a canvas template where canvas is off, because the server does', async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      respond([
        template({
          id: 't-canvas',
          name: 'Launch canvas',
          scope: 'user',
          note_type: 'canvas',
          editable: true,
        }),
      ]),
    )
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Actions for Launch canvas' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Edit template' }))

    const dialog = await screen.findByRole('dialog')
    // The flag is a fact about what the template can do, not a reason to
    // refuse the rename: NoteTemplateService does not check feature flags.
    expect(
      within(dialog).getByText(/no note can be started from it until canvas is switched on/i),
    ).toBeInTheDocument()

    const save = within(dialog).getByRole('button', { name: 'Save template' })
    expect(save).toBeEnabled()

    await user.clear(within(dialog).getByLabelText('Name'))
    await user.type(within(dialog).getByLabelText('Name'), 'Launch board')
    await user.click(save)

    await waitFor(() => expect(writes('PATCH')).toHaveLength(1))
    expect(writes('PATCH')[0][0]).toContain('/templates/t-canvas')
    expect(JSON.parse(String(writes('PATCH')[0][1].body))).toMatchObject({ name: 'Launch board' })
  })

  it('offers a first template when there are none', async () => {
    fetchMock.mockImplementation(respond([]))
    renderPage()

    expect(await screen.findByText('No templates yet')).toBeInTheDocument()

    const empty = screen.getByText('No templates yet').closest('.empty-state') as HTMLElement
    await userEvent.setup().click(within(empty).getByRole('button', { name: 'New template' }))

    expect(await screen.findByRole('dialog')).toHaveAccessibleName('New template')
  })
})

/** The date the server would put in a title, in the browser's own timezone. */
function today(): string {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}
