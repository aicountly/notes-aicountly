/**
 * Citations, from the reader's side.
 *
 * The list exists so a sentence can be checked against the note behind it, so
 * what is tested is exactly that: every source is a real link to a real note,
 * the quotation is visible without being swallowed into the link's name, and a
 * note cited twice appears twice rather than collapsing into one.
 */

import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'

import { CitationList } from './CitationList'
import type { PulseCitation } from '../../../shared/api/types'

const NOTE_A = '8b1c0c2e-0000-4000-8000-0000000000a1'
const NOTE_B = '8b1c0c2e-0000-4000-8000-0000000000b2'

function citation(overrides: Partial<PulseCitation> = {}): PulseCitation {
  return {
    note_id: NOTE_A,
    title: 'Acme contract',
    block_id: 'block-1',
    snippet: 'Renewal falls due on 3 November.',
    ...overrides,
  }
}

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>
}

function renderList(citations: PulseCitation[], onNavigate?: (noteId: string) => void) {
  return render(
    <MemoryRouter initialEntries={['/notes/current']}>
      <Where />
      <CitationList citations={citations} onNavigate={onNavigate} />
    </MemoryRouter>,
  )
}

describe('CitationList', () => {
  it('draws nothing when an answer had no sources', () => {
    const { container } = renderList([])

    // The panel says "answered from general knowledge" above the answer; an
    // empty sources heading here would be a second, weaker way of saying it.
    expect(screen.queryByRole('region', { name: 'Sources for this answer' })).not.toBeInTheDocument()
    expect(container.querySelector('.pulse-sources')).toBeNull()
  })

  it('links each source to the note it came from and shows what was quoted', () => {
    renderList([citation()])

    const link = screen.getByRole('link', { name: /Acme contract/ })
    expect(link).toHaveAttribute('href', `/notes/${NOTE_A}`)
    // The quotation sits outside the link: a two-line snippet inside the
    // accessible name makes the link unreadable when it is announced.
    expect(link).not.toHaveTextContent('Renewal falls due')
    expect(screen.getByText('Renewal falls due on 3 November.')).toBeInTheDocument()
  })

  it('navigates to the cited note when a pill is followed', async () => {
    const user = userEvent.setup()
    const onNavigate = vi.fn()
    renderList([citation()], onNavigate)

    await user.click(screen.getByRole('link', { name: /Acme contract/ }))

    expect(onNavigate).toHaveBeenCalledWith(NOTE_A)
    expect(screen.getByTestId('where')).toHaveTextContent(`/notes/${NOTE_A}`)
  })

  it('keeps both entries when one note is cited from two places', () => {
    renderList([
      citation({ block_id: 'block-1', snippet: 'First mention.' }),
      citation({ block_id: 'block-9', snippet: 'Second mention.' }),
      citation({ note_id: NOTE_B, title: null, block_id: null, snippet: 'From an untitled note.' }),
    ])

    expect(screen.getAllByRole('link')).toHaveLength(3)
    expect(screen.getByText('First mention.')).toBeInTheDocument()
    expect(screen.getByText('Second mention.')).toBeInTheDocument()
    // A note with no title still has to be identifiable as a source.
    expect(screen.getByRole('link', { name: /Untitled note/ })).toHaveAttribute('href', `/notes/${NOTE_B}`)
  })

  it('counts its sources so an answer can be weighed before it is read', () => {
    renderList([citation(), citation({ note_id: NOTE_B, block_id: null })])

    expect(screen.getByText('2 sources in your notes')).toBeInTheDocument()
  })
})
