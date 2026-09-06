/**
 * What the picker promises.
 *
 * It is the fast path into a new note, so the tests are about the two things
 * that make it fast and the one thing that makes it honest: the list stays in
 * scope order however it is filtered, the whole dialog can be driven from the
 * keyboard without ever reaching for the mouse, and a template this deployment
 * could not actually turn into a note is not offered.
 */

import { useState } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { TemplatePicker } from './TemplatePicker'
import type { NoteTemplate } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

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
  template({ id: 't-meeting', name: 'Meeting notes', note_type: 'meeting', title_template: 'Meeting — {{date}}' }),
  template({ id: 't-canvas', name: 'Launch canvas', note_type: 'canvas' }),
  template({ id: 't-call', name: 'Client call', scope: 'tenant', description: 'What the firm agreed on.' }),
  template({ id: 't-weekly', name: 'Weekly review', scope: 'user', editable: true, default_tags: ['review'] }),
]

function envelope(data: unknown, status = 200) {
  return {
    ok: status < 400,
    status,
    text: async () => JSON.stringify({ success: status < 400, data }),
  } as unknown as Response
}

const fetchMock = vi.fn()

beforeEach(() => {
  features.canvas = false

  fetchMock.mockImplementation(async (input: unknown, init?: RequestInit) => {
    const url = String(input)
    if (url.includes('/create-note')) return envelope({ id: 'note-42' }, 201)
    if (url.includes('/templates') && (init?.method ?? 'GET') === 'GET') return envelope(TEMPLATES)
    return envelope(null)
  })
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

function Harness() {
  const [open, setOpen] = useState(true)
  return <TemplatePicker open={open} onClose={() => setOpen(false)} />
}

function renderPicker() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/templates']}>
        <Routes>
          <Route path="/templates" element={<Harness />} />
          <Route path="/notes/:noteId" element={<p>the note editor</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The name on each option row, in the order they are offered. */
async function optionNames(): Promise<string[]> {
  const list = await screen.findByRole('listbox', { name: 'Templates' })
  return within(list)
    .getAllByRole('option')
    .map((option) => option.querySelector('.tpl-picker__name')?.textContent ?? '')
}

describe('TemplatePicker', () => {
  it('groups templates the way the page does: built in, company, then yours', async () => {
    renderPicker()

    const list = await screen.findByRole('listbox', { name: 'Templates' })
    const rows = within(list).getAllByRole('presentation')

    expect(rows.map((row) => row.textContent)).toEqual([
      'Built in',
      'Shared with your company',
      'Yours',
    ])
    expect(await optionNames()).toEqual(['Meeting notes', 'Client call', 'Weekly review'])
  })

  it('leaves out a template this deployment could not turn into a note, and says so', async () => {
    renderPicker()

    expect(await optionNames()).not.toContain('Launch canvas')
    expect(
      screen.getByText(/1 template is not listed: canvas notes are switched off/i),
    ).toBeInTheDocument()

    // Switched on, it is simply there — the picker is not hiding it on a whim.
    features.canvas = true
    renderPicker()
    await waitFor(() => expect(screen.getAllByText('Launch canvas').length).toBeGreaterThan(0))
  })

  it('filters on what a person would search by, keeping the groups in order', async () => {
    const user = userEvent.setup()
    renderPicker()
    await screen.findByRole('listbox', { name: 'Templates' })

    await user.type(screen.getByLabelText('Find a template'), 'firm')
    // Matched on the description, not just the name.
    await waitFor(async () => expect(await optionNames()).toEqual(['Client call']))

    await user.clear(screen.getByLabelText('Find a template'))
    await user.type(screen.getByLabelText('Find a template'), 'e')
    await waitFor(async () =>
      expect(await optionNames()).toEqual(['Meeting notes', 'Client call', 'Weekly review']),
    )
  })

  it('says so when nothing matches, and offers the way back', async () => {
    const user = userEvent.setup()
    renderPicker()
    await screen.findByRole('listbox', { name: 'Templates' })

    await user.type(screen.getByLabelText('Find a template'), 'zzz')

    expect(await screen.findByText('No template matches “zzz”')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Clear search' }))
    await waitFor(async () => expect(await optionNames()).toHaveLength(3))
  })

  it('is driven entirely from the keyboard: arrows move, Enter starts the note', async () => {
    const user = userEvent.setup()
    renderPicker()

    const input = await screen.findByLabelText('Find a template')
    input.focus()

    await user.keyboard('{ArrowDown}')
    const list = screen.getByRole('listbox', { name: 'Templates' })
    const options = within(list).getAllByRole('option')

    expect(options[1]).toHaveAttribute('aria-selected', 'true')
    expect(input).toHaveAttribute('aria-activedescendant', options[1].id)

    // Wraps rather than sticking at the end, so a long list is reachable both ways.
    await user.keyboard('{ArrowUp}{ArrowUp}')
    expect(within(list).getAllByRole('option')[2]).toHaveAttribute('aria-selected', 'true')

    await user.keyboard('{ArrowDown}{Enter}')

    await waitFor(() => expect(screen.getByText('the note editor')).toBeInTheDocument())

    const post = fetchMock.mock.calls.find(
      (call) => (call[1] as RequestInit | undefined)?.method === 'POST',
    ) as [string, RequestInit] | undefined
    expect(post?.[0]).toContain('/templates/t-meeting/create-note')
  })

  it('starts the note the row was for, and takes the user to it', async () => {
    const user = userEvent.setup()
    renderPicker()

    const list = await screen.findByRole('listbox', { name: 'Templates' })
    await user.click(within(list).getByText('Weekly review'))

    await waitFor(() => expect(screen.getByText('the note editor')).toBeInTheDocument())

    const post = fetchMock.mock.calls.find(
      (call) => (call[1] as RequestInit | undefined)?.method === 'POST',
    ) as [string, RequestInit] | undefined
    expect(post?.[0]).toContain('/templates/t-weekly/create-note')
  })

  it('shows the server’s message when the note could not be started', async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(async (input: unknown, init?: RequestInit) => {
      const url = String(input)
      if (url.includes('/create-note')) {
        return {
          ok: false,
          status: 403,
          text: async () =>
            JSON.stringify({
              success: false,
              error: { code: 'FEATURE_DISABLED', message: 'Canvas notes are not enabled here.' },
            }),
        } as unknown as Response
      }
      if (url.includes('/templates') && (init?.method ?? 'GET') === 'GET') return envelope(TEMPLATES)
      return envelope(null)
    })

    renderPicker()
    const list = await screen.findByRole('listbox', { name: 'Templates' })
    await user.click(within(list).getByText('Meeting notes'))

    expect(await screen.findByRole('alert')).toHaveTextContent('Canvas notes are not enabled here.')
    // Still open: the dialog does not navigate away from a failure.
    expect(screen.getByRole('listbox', { name: 'Templates' })).toBeInTheDocument()
  })

  it('reports a list it could not load, with a way to try again', async () => {
    fetchMock.mockImplementation(async () => ({
      ok: false,
      status: 500,
      text: async () =>
        JSON.stringify({
          success: false,
          error: { code: 'SERVER_ERROR', message: 'Templates are unavailable right now.' },
        }),
    }))

    renderPicker()

    expect(await screen.findByRole('alert')).toHaveTextContent('Templates are unavailable right now.')
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  })
})
