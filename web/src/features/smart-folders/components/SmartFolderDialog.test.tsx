/**
 * What the rule builder promises.
 *
 * The builder's whole job is to make an invalid folder impossible to write, so
 * these tests are about the boundary between it and the server: only fields the
 * server knows are offered, the operator list narrows to the ones that field
 * accepts, the control matches the value, and a rule that would be refused is
 * refused here — beside the rule, with the reason.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { SmartFolderDialog } from './SmartFolderDialog'
import { RULE_FIELDS } from '../rules'
import type { SmartFolderNode } from '../hooks/useSmartFolders'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

const { features } = vi.hoisted(() => ({
  features: { drive: false, calendar: false, contacts: false, connect: false } as Record<string, boolean>,
}))

vi.mock('../../../app/AppConfigProvider', () => ({
  useAppConfig: () => ({
    app: 'Notes',
    env: 'test',
    features,
    limits: { max_attachment_bytes: 1024, trash_retention_days: 30 },
  }),
  useFeature: (flag: string) => features[flag] ?? false,
}))

const fetchMock = vi.fn()

function envelope(data: unknown) {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ success: true, data }),
  } as unknown as Response
}

function renderDialog(folder: SmartFolderNode | null = null, onSaved = vi.fn()) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SmartFolderDialog open folder={folder} onClose={vi.fn()} onSaved={onSaved} />
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return onSaved
}

beforeEach(() => {
  features.drive = false
  features.calendar = false
  features.contacts = false
  features.connect = false

  fetchMock.mockImplementation(async (input: unknown) => {
    const url = String(input)
    if (url.includes('/notebooks')) {
      return envelope([
        {
          id: '8b1c0c2e-0000-4000-8000-000000000001',
          parent_id: null,
          name: 'Clients',
          description: null,
          icon: null,
          color: null,
          position: 0,
          depth: 0,
          is_archived: false,
          note_count: 3,
          role: 'owner',
          children: [],
        },
      ])
    }
    if (url.includes('/tags')) return envelope([{ id: 't1', name: 'gst', slug: 'gst', color: null, note_count: 2 }])
    return envelope({ id: 'sf-1', name: 'GST, recent', icon: null, color: null, position: 0, rules: { match: 'all', conditions: [] } })
  })
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('SmartFolderDialog', () => {
  it('offers exactly the fields the server can execute', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.click(screen.getByRole('button', { name: 'Add rule' }))

    const field = screen.getByLabelText('Rule 1')
    const offered = within(field)
      .getAllByRole('option')
      .map((option) => (option as HTMLOptionElement).value)

    expect(offered).toEqual(RULE_FIELDS.map((rule) => rule.field))
  })

  it('narrows the operators to the ones the chosen field accepts', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.click(screen.getByRole('button', { name: 'Add rule' }))
    await user.selectOptions(screen.getByLabelText('Rule 1'), 'note_type')

    const operators = within(screen.getByLabelText('Compare'))
      .getAllByRole('option')
      .map((option) => (option as HTMLOptionElement).value)

    // `note_type` takes is / is not, and nothing else — no "contains".
    expect(operators).toEqual(['is', 'is_not'])
  })

  it('gives each field the control its value actually needs', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.click(screen.getByRole('button', { name: 'Add rule' }))

    // A notebook is picked from the tree, never typed as an id.
    await user.selectOptions(screen.getByLabelText('Rule 1'), 'notebook')
    await waitFor(() => expect(screen.getByLabelText('Notebook')).toBeInTheDocument())
    expect(within(screen.getByLabelText('Notebook')).getByRole('option', { name: /Clients/ })).toBeInTheDocument()

    // "within the last" is a number of days, not a date.
    await user.selectOptions(screen.getByLabelText('Rule 1'), 'updated_at')
    await user.selectOptions(screen.getByLabelText('Compare'), 'within_days')
    expect(screen.getByLabelText('Days')).toHaveAttribute('type', 'number')

    // before/after is a date, not a number.
    await user.selectOptions(screen.getByLabelText('Compare'), 'before')
    expect(screen.getByLabelText('Date')).toHaveAttribute('type', 'date')

    // A flag is a toggle.
    await user.selectOptions(screen.getByLabelText('Rule 1'), 'is_pinned')
    expect(screen.getByRole('checkbox')).toBeChecked()
  })

  it('refuses to save a rule the server would reject, and says which one', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.type(screen.getByLabelText('Name'), 'Untitled search')
    await user.click(screen.getByRole('button', { name: 'Add rule' }))
    await user.selectOptions(screen.getByLabelText('Rule 1'), 'notebook')

    expect(await screen.findByRole('alert')).toHaveTextContent('Choose a notebook.')
    expect(screen.getByRole('button', { name: 'Create folder' })).toBeDisabled()

    await user.selectOptions(screen.getByLabelText('Notebook'), '8b1c0c2e-0000-4000-8000-000000000001')

    await waitFor(() => expect(screen.getByRole('button', { name: 'Create folder' })).toBeEnabled())
  })

  it('sends the rule tree the builder shows', async () => {
    const user = userEvent.setup()
    const onSaved = renderDialog()

    await user.type(screen.getByLabelText('Name'), 'GST, recent')
    await user.click(screen.getByRole('button', { name: 'Add rule' }))
    await user.selectOptions(screen.getByLabelText('Rule 1'), 'updated_at')
    await user.selectOptions(screen.getByLabelText('Compare'), 'within_days')
    await user.clear(screen.getByLabelText('Days'))
    await user.type(screen.getByLabelText('Days'), '30')

    await user.click(screen.getByRole('radio', { name: 'Match any rule' }))
    await user.click(screen.getByRole('button', { name: 'Create folder' }))

    await waitFor(() => expect(onSaved).toHaveBeenCalled())

    const write = fetchMock.mock.calls.find(
      (call) => (call[1] as RequestInit | undefined)?.method === 'POST',
    ) as [string, RequestInit] | undefined
    expect(write).toBeDefined()

    const body = JSON.parse(String(write?.[1].body)) as {
      name: string
      rules: { match: string; conditions: { field: string; operator: string; value: unknown }[] }
    }
    expect(body.name).toBe('GST, recent')
    expect(body.rules.match).toBe('any')
    expect(body.rules.conditions).toEqual([{ field: 'updated_at', operator: 'within_days', value: 30 }])
  })

  it('does not offer a linked record from a product this deployment has switched off', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.click(screen.getByRole('button', { name: 'Add rule' }))
    await user.selectOptions(screen.getByLabelText('Rule 1'), 'entity')

    const types = within(screen.getByLabelText('Record type'))
      .getAllByRole('option')
      .map((option) => (option as HTMLOptionElement).value)

    expect(types).not.toContain('drive_file')
    expect(types).toContain('invoice')
  })

  it('keeps a rule with its own row when the rule above it is removed', async () => {
    const user = userEvent.setup()
    renderDialog()

    await user.click(screen.getByRole('button', { name: 'Add rule' }))
    await user.click(screen.getByRole('button', { name: 'Add rule' }))
    await user.selectOptions(screen.getByLabelText('Rule 2'), 'tag')

    // A half-typed tag lives inside the picker, not in the rule tree, so it is
    // the thing that gets handed to the wrong rule when rows are keyed by
    // position rather than by identity.
    await user.type(screen.getByLabelText('Tag'), 'urg')
    expect(screen.getByLabelText('Tag')).toHaveValue('urg')

    await user.click(screen.getByRole('button', { name: 'Remove rule 1' }))

    expect(screen.getByLabelText('Rule 1')).toHaveValue('tag')
    expect(screen.getByLabelText('Tag')).toHaveValue('urg')
  })

  it('keeps showing a notebook the tree no longer lists instead of swapping the rule', async () => {
    renderDialog({
      id: 'sf-1',
      name: 'Archived clients',
      icon: null,
      color: null,
      position: 0,
      rules: {
        match: 'all',
        conditions: [{ field: 'notebook', operator: 'is', value: '8b1c0c2e-0000-4000-8000-0000000000ff' }],
      },
    })

    // The id is not in the tree the sidebar can see — archived, deleted, or
    // shared away. Falling back to "Choose…" would show a rule the folder does
    // not have, and saving would then send a rule nobody chose.
    const select = await screen.findByLabelText('Notebook')
    await waitFor(() => expect(select).toHaveValue('8b1c0c2e-0000-4000-8000-0000000000ff'))
    expect(within(select).getByRole('option', { name: /already names/ })).toBeInTheDocument()
  })

  it('caps the builder at the 25 rules the server allows', async () => {
    const user = userEvent.setup()
    renderDialog()

    const add = screen.getByRole('button', { name: 'Add rule' })
    for (let index = 0; index < 25; index += 1) await user.click(add)

    expect(screen.getByRole('button', { name: 'Add rule' })).toBeDisabled()
    expect(screen.getByText('That is the limit of 25 rules.')).toBeInTheDocument()
  })
})
