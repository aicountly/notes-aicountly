/**
 * The promises the action menu makes about somebody's note.
 *
 * Two of them matter more than the rest, and both are here first: with the
 * feature off there is nothing on screen to press, and with it on, picking an
 * action never writes anything into the note — the result is a preview until
 * somebody chooses to apply it.
 *
 * The menu itself is asserted against the catalogue the server sent, because
 * the whole point of `GET /pulse/actions` is that the browser does not keep its
 * own copy of that list.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { PulseActionMenu } from './PulseActionMenu'
import type { NoteCapabilities, PulseActionDefinition } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

const { features } = vi.hoisted(() => ({ features: { ai: true } as Record<string, boolean> }))

vi.mock('../../../app/AppConfigProvider', () => ({
  useFeature: (flag: string) => features[flag] ?? false,
}))

const NOTE_ID = '8b1c0c2e-0000-4000-8000-0000000000aa'

const EDITOR: NoteCapabilities = {
  view: true, comment: true, edit: true,
  share: true, delete: true, restore: true, manage_members: true,
}

const READER: NoteCapabilities = { ...EDITOR, edit: false }

/** What the server sends, including one action it has switched off. */
function catalogue(): PulseActionDefinition[] {
  return [
    { id: 'summarise', label: 'Summarise', group: 'understand', scope: 'selection', output: 'text', enabled: true },
    { id: 'translate', label: 'Translate', group: 'write', scope: 'selection', output: 'document_fragment', enabled: true },
    { id: 'create_table', label: 'Make a table', group: 'transform', scope: 'selection', output: 'table', enabled: false },
    { id: 'ask_note', label: 'Ask this note', group: 'ask', scope: 'note', output: 'text', enabled: true },
  ]
}

const fetchMock = vi.fn()

function ok(data: unknown) {
  return { ok: true, status: 200, text: async () => JSON.stringify({ success: true, data }) }
}

function failure(status: number, code: string, message: string) {
  return { ok: false, status, text: async () => JSON.stringify({ success: false, error: { code, message } }) }
}

/** The answer a run comes back with, unless a test says otherwise. */
let runResponse: () => unknown

function renderMenu(capabilities: NoteCapabilities = EDITOR) {
  const onReplaceNote = vi.fn()
  const onInsertBelow = vi.fn()
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  const view = render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <PulseActionMenu
          noteId={NOTE_ID}
          capabilities={capabilities}
          onReplaceNote={onReplaceNote}
          onInsertBelow={onInsertBelow}
        />
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return { ...view, onReplaceNote, onInsertBelow }
}

async function openMenu(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Pulse' }))
  return screen.findByRole('menu', { name: 'Pulse actions' })
}

beforeEach(() => {
  features.ai = true
  runResponse = () =>
    ok({
      action: 'summarise',
      output: 'text',
      answer: 'Three suppliers were shortlisted.',
      data: null,
      citations: [
        { note_id: NOTE_ID, title: 'Supplier review', block_id: null, snippet: 'Shortlist: Acme, Borex, Cintra.' },
      ],
      grounded: true,
      model: 'test-model',
    })

  fetchMock.mockImplementation(async (url: string) =>
    url.includes('/pulse/actions') ? ok(catalogue()) : runResponse(),
  )
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('PulseActionMenu when Pulse is switched off', () => {
  it('renders nothing at all — no trigger, no menu, nothing to click', () => {
    features.ai = false
    const { container } = renderMenu()

    expect(container).toBeEmptyDOMElement()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('PulseActionMenu', () => {
  it('builds the menu from the catalogue the server sent', async () => {
    const user = userEvent.setup()
    renderMenu()

    const menu = await openMenu(user)

    expect(await within(menu).findByRole('menuitem', { name: /Summarise/ })).toBeInTheDocument()
    expect(within(menu).getByRole('menuitem', { name: /Make a table/ })).toBeInTheDocument()
    // Questions need a question, which is the panel's job — a menu cannot ask.
    expect(within(menu).queryByRole('menuitem', { name: /Ask this note/ })).not.toBeInTheDocument()
  })

  it('says why a switched-off action cannot run, and does not run it', async () => {
    const user = userEvent.setup()
    renderMenu()

    const menu = await openMenu(user)
    const table = await within(menu).findByRole('menuitem', { name: /Make a table/ })

    expect(table).toHaveAttribute('aria-disabled', 'true')
    expect(table).toHaveTextContent('Switched off on this server')

    await user.click(table)

    // The catalogue was fetched; nothing was asked of Pulse.
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('previews the result and leaves the note alone until it is applied', async () => {
    const user = userEvent.setup()
    const { onReplaceNote, onInsertBelow } = renderMenu()

    const menu = await openMenu(user)
    await user.click(await within(menu).findByRole('menuitem', { name: /Summarise/ }))

    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('Three suppliers were shortlisted.')).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: /Supplier review/ })).toHaveAttribute(
      'href',
      `/notes/${NOTE_ID}`,
    )

    // Nothing has been written yet: the answer exists only in the preview.
    expect(onReplaceNote).not.toHaveBeenCalled()
    expect(onInsertBelow).not.toHaveBeenCalled()

    await user.click(within(dialog).getByRole('button', { name: 'Replace note' }))

    expect(onReplaceNote).toHaveBeenCalledWith('Three suppliers were shortlisted.')
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('cannot write into a note the reader may only view, and says why', async () => {
    const user = userEvent.setup()
    const { onReplaceNote } = renderMenu(READER)

    const menu = await openMenu(user)
    await user.click(await within(menu).findByRole('menuitem', { name: /Summarise/ }))

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('Three suppliers were shortlisted.')

    expect(within(dialog).getByRole('button', { name: 'Replace note' })).toBeDisabled()
    expect(within(dialog).getByRole('button', { name: 'Insert below' })).toBeDisabled()
    // Disabled with the reason beside it, not a control that fails on click.
    expect(within(dialog).getByText(/view-only access to this note/)).toBeInTheDocument()
    // Copying is still theirs to do.
    expect(within(dialog).getByRole('button', { name: 'Copy' })).toBeEnabled()
    expect(onReplaceNote).not.toHaveBeenCalled()
  })

  it('asks for the target language before translating anything', async () => {
    const user = userEvent.setup()
    renderMenu()

    const menu = await openMenu(user)
    await user.click(await within(menu).findByRole('menuitem', { name: /Translate/ }))

    const dialog = await screen.findByRole('dialog')
    const field = within(dialog).getByLabelText('Translate this note into')

    // The server refuses a translation with no language, so nothing is sent
    // until there is one.
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(within(dialog).getByRole('button', { name: 'Translate' })).toBeDisabled()

    await user.type(field, 'Hindi')
    await user.click(within(dialog).getByRole('button', { name: 'Translate' }))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    const [, init] = fetchMock.mock.calls[1] as [string, RequestInit]
    expect(JSON.parse(String(init.body))).toMatchObject({ action: 'translate', language: 'Hindi' })
  })

  it('shows the server’s own words when Pulse is turned off mid-session', async () => {
    const user = userEvent.setup()
    runResponse = () => failure(503, 'FEATURE_DISABLED', 'This feature is not enabled on this deployment.')
    renderMenu()

    const menu = await openMenu(user)
    await user.click(await within(menu).findByRole('menuitem', { name: /Summarise/ }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('This feature is not enabled on this deployment.')
    // Nothing to apply, so nothing that applies is offered.
    expect(screen.queryByRole('button', { name: 'Replace note' })).not.toBeInTheDocument()
  })

  it('labels an answer that did not come from the notes, above the answer', async () => {
    const user = userEvent.setup()
    runResponse = () =>
      ok({
        action: 'summarise',
        output: 'text',
        answer: 'Supplier reviews usually cover price and lead time.',
        data: null,
        citations: [],
        grounded: false,
        model: 'test-model',
      })
    renderMenu()

    const menu = await openMenu(user)
    await user.click(await within(menu).findByRole('menuitem', { name: /Summarise/ }))

    const dialog = await screen.findByRole('dialog')
    const warning = await within(dialog).findByText(
      'Pulse answered from general knowledge, not from your notes.',
    )
    const prose = within(dialog).getByText('Supplier reviews usually cover price and lead time.')

    expect(warning.compareDocumentPosition(prose) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('closes on Escape and gives focus back to the trigger', async () => {
    const user = userEvent.setup()
    renderMenu()

    const menu = await openMenu(user)
    await within(menu).findByRole('menuitem', { name: /Summarise/ })

    await user.keyboard('{Escape}')

    await waitFor(() => expect(screen.queryByRole('menu')).not.toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Pulse' })).toHaveFocus()
  })
})
