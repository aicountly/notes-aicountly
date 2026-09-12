/**
 * What the notebook tree promises.
 *
 * A tree is only worth having if nesting is visible and staying open is
 * remembered, so those are the two things pinned down here, plus the third
 * thing an organising surface owes a new user: an empty tree that offers a way
 * out of being empty rather than a blank space.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { NotebookTree } from './NotebookTree'
import type { NotebookNode } from '../hooks/useNotebooks'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

function notebook(overrides: Partial<NotebookNode> & { id: string; name: string }): NotebookNode {
  return {
    parent_id: null,
    description: null,
    icon: null,
    color: null,
    position: 0,
    depth: 0,
    is_archived: false,
    note_count: 0,
    role: 'owner',
    children: [],
    ...overrides,
  }
}

const TREE: NotebookNode[] = [
  notebook({
    id: 'nb-clients',
    name: 'Clients',
    note_count: 4,
    children: [
      notebook({ id: 'nb-acme', name: 'Acme Ltd', parent_id: 'nb-clients', depth: 1, note_count: 2 }),
    ],
  }),
  notebook({ id: 'nb-personal', name: 'Personal', note_count: 1, role: 'viewer' }),
]

const fetchMock = vi.fn()

function envelope(data: unknown) {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ success: true, data }),
  } as unknown as Response
}

function renderTree() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/notes']}>
        <NotebookTree />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  window.localStorage.clear()
  fetchMock.mockImplementation(async () => envelope(TREE))
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('NotebookTree', () => {
  it('shows a top-level notebook with its note count, and hides its children until asked', async () => {
    renderTree()

    expect(await screen.findByRole('link', { name: /Clients/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Clients/ })).toHaveTextContent('4')
    expect(screen.queryByRole('link', { name: /Acme Ltd/ })).not.toBeInTheDocument()
  })

  it('expands and collapses a branch, and remembers that it was open', async () => {
    const user = userEvent.setup()
    renderTree()

    const expand = await screen.findByRole('button', { name: 'Expand Clients' })
    expect(expand).toHaveAttribute('aria-expanded', 'false')

    await user.click(expand)

    expect(await screen.findByRole('link', { name: /Acme Ltd/ })).toBeInTheDocument()
    const collapse = screen.getByRole('button', { name: 'Collapse Clients' })
    expect(collapse).toHaveAttribute('aria-expanded', 'true')
    // Remembered on this device, so a reload does not shut every branch.
    expect(window.localStorage.getItem('notes:notebooks:expanded')).toContain('nb-clients')

    await user.click(collapse)
    expect(screen.queryByRole('link', { name: /Acme Ltd/ })).not.toBeInTheDocument()
  })

  it('has no disclosure on a notebook with nothing inside it', async () => {
    renderTree()

    await screen.findByRole('link', { name: /Personal/ })
    expect(screen.queryByRole('button', { name: /Expand Personal/ })).not.toBeInTheDocument()
  })

  it('says why a viewer cannot rename a notebook instead of hiding the option', async () => {
    const user = userEvent.setup()
    renderTree()

    await screen.findByRole('link', { name: /Personal/ })
    await user.click(screen.getByRole('button', { name: 'Actions for Personal' }))

    const rename = await screen.findByRole('menuitem', { name: /Rename/ })
    expect(rename).toHaveAttribute('aria-disabled', 'true')
    expect(rename).toHaveTextContent('View only')
  })

  it('warns where the notes go before a notebook is deleted', async () => {
    const user = userEvent.setup()
    renderTree()

    await screen.findByRole('link', { name: /Clients/ })
    await user.click(screen.getByRole('button', { name: 'Actions for Clients' }))
    await user.click(await screen.findByRole('menuitem', { name: /Delete notebook/ }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/4 notes move to no notebook/)).toBeInTheDocument()
    expect(within(dialog).getByText(/sub-notebook moves up one level/)).toBeInTheDocument()
  })

  it('offers a way out of an empty tree', async () => {
    fetchMock.mockImplementation(async () => envelope([]))
    renderTree()

    const empty = (await screen.findByText('No notebooks yet')).parentElement as HTMLElement
    expect(within(empty).getByRole('button', { name: 'New notebook' })).toBeInTheDocument()
  })

  it("reports a failed load in the server's own words, and offers a retry", async () => {
    fetchMock.mockImplementation(
      async () =>
        ({
          ok: false,
          status: 503,
          text: async () =>
            JSON.stringify({ success: false, error: { code: 'DATABASE_NOT_CONFIGURED', message: 'The notes database is not configured.' } }),
        }) as unknown as Response,
    )
    renderTree()

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('The notes database is not configured.'))
    expect(screen.getByRole('button', { name: /Try again/ })).toBeInTheDocument()
  })
})
