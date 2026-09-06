/**
 * What inline tag entry promises.
 *
 * The behaviours below are the ones a user discovers by trying rather than by
 * reading: Enter applies, an unknown name simply becomes a tag, Backspace on an
 * empty field takes the last one back, and case never quietly makes a second
 * tag out of one the user already has.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { TagPicker } from './TagPicker'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

const TAGS = [
  { id: 't1', name: 'gst', slug: 'gst', color: null, note_count: 12 },
  { id: 't2', name: 'urgent', slug: 'urgent', color: null, note_count: 3 },
]

const fetchMock = vi.fn()

function envelope(data: unknown) {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ success: true, data }),
  } as unknown as Response
}

function Harness({ initial = [] as string[], max }: { initial?: string[]; max?: number }) {
  const [value, setValue] = useState<string[]>(initial)

  return (
    <>
      <TagPicker value={value} onChange={setValue} max={max} />
      <output data-testid="applied">{value.join('|')}</output>
    </>
  )
}

function renderPicker(initial: string[] = [], max?: number) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <Harness initial={initial} max={max} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  fetchMock.mockImplementation(async () => envelope(TAGS))
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('TagPicker', () => {
  it('filters as you type and applies the match with Enter', async () => {
    const user = userEvent.setup()
    renderPicker()

    await user.click(screen.getByLabelText('Tags'))
    await waitFor(() => expect(screen.getByRole('option', { name: /gst/ })).toBeInTheDocument())

    await user.type(screen.getByLabelText('Tags'), 'urg')
    expect(screen.queryByRole('option', { name: /^gst/ })).not.toBeInTheDocument()

    await user.keyboard('{Enter}')
    expect(screen.getByTestId('applied')).toHaveTextContent('urgent')
  })

  it('makes a tag out of a name that does not exist yet', async () => {
    const user = userEvent.setup()
    renderPicker()

    await user.click(screen.getByLabelText('Tags'))
    await user.type(screen.getByLabelText('Tags'), 'section-44')
    expect(await screen.findByRole('option', { name: /Create/ })).toBeInTheDocument()

    await user.keyboard('{Enter}')
    expect(screen.getByTestId('applied')).toHaveTextContent('section-44')
  })

  it('treats GST and gst as one tag', async () => {
    const user = userEvent.setup()
    renderPicker()

    await user.click(screen.getByLabelText('Tags'))
    await user.type(screen.getByLabelText('Tags'), 'GST')
    await waitFor(() => expect(screen.getByRole('option', { name: /gst/ })).toBeInTheDocument())

    // The existing tag is offered; nothing invites the user to make a second one.
    expect(screen.queryByRole('option', { name: /Create/ })).not.toBeInTheDocument()

    await user.keyboard('{Enter}')
    expect(screen.getByTestId('applied')).toHaveTextContent('gst')

    // And it cannot be added twice under a different case.
    await user.type(screen.getByLabelText('Tags'), 'Gst')
    await user.keyboard('{Enter}')
    expect(screen.getByTestId('applied')).toHaveTextContent(/^gst$/)
  })

  it('accepts a tag written the way it is written inline', async () => {
    const user = userEvent.setup()
    renderPicker()

    await user.click(screen.getByLabelText('Tags'))
    await user.type(screen.getByLabelText('Tags'), '#gst')
    await user.keyboard('{Enter}')

    expect(screen.getByTestId('applied')).toHaveTextContent(/^gst$/)
  })

  it('removes the last tag when Backspace is pressed in an empty field', async () => {
    const user = userEvent.setup()
    renderPicker(['gst', 'urgent'])

    await user.click(screen.getByLabelText('Tags'))
    await user.keyboard('{Backspace}')

    expect(screen.getByTestId('applied')).toHaveTextContent(/^gst$/)

    // Typing first means Backspace is editing, not removing.
    await user.type(screen.getByLabelText('Tags'), 'ab')
    await user.keyboard('{Backspace}')
    expect(screen.getByTestId('applied')).toHaveTextContent(/^gst$/)
  })

  it('has a remove button on every tag, not only Backspace', async () => {
    const user = userEvent.setup()
    renderPicker(['gst'])

    await user.click(screen.getByRole('button', { name: 'Remove tag gst' }))
    expect(screen.getByTestId('applied')).toHaveTextContent('')
  })

  it('stays labelled and keyboard-reachable once the tag limit is reached', async () => {
    const user = userEvent.setup()
    renderPicker([], 1)

    await user.click(screen.getByLabelText('Tags'))
    await user.type(screen.getByLabelText('Tags'), 'gst')
    await user.keyboard('{Enter}')

    expect(screen.getByTestId('applied')).toHaveTextContent(/^gst$/)

    // The field is disabled, not gone: unmounting it would leave the visible
    // label pointing at nothing and drop focus to the top of the page.
    const input = screen.getByLabelText('Tags')
    expect(input).toBeDisabled()
    expect(input).toHaveAccessibleDescription('Remove the tag above to choose another.')

    // Focus lands on the only control still worth pressing.
    const removeTag = screen.getByRole('button', { name: 'Remove tag gst' })
    await waitFor(() => expect(removeTag).toHaveFocus())

    // And freeing the slot hands the field back rather than dropping focus.
    await user.click(removeTag)
    expect(screen.getByTestId('applied')).toHaveTextContent('')
    await waitFor(() => expect(screen.getByLabelText('Tags')).toHaveFocus())
  })

  it('says so when the tag list cannot be loaded', async () => {
    fetchMock.mockImplementation(
      async () =>
        ({
          ok: false,
          status: 500,
          text: async () =>
            JSON.stringify({ success: false, error: { code: 'HTTP_500', message: 'The tag list is unavailable.' } }),
        }) as unknown as Response,
    )
    const user = userEvent.setup()
    renderPicker()

    await user.click(screen.getByLabelText('Tags'))
    expect(await screen.findByRole('alert')).toHaveTextContent('The tag list is unavailable.')
  })
})
