/**
 * The palette, driven the way it is meant to be driven: from the keyboard.
 *
 * These assert what someone operating it would notice — which row is
 * highlighted, what Enter does, and that a command for a capability this
 * deployment does not have is simply not offered.
 */

import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { ThemeProvider } from '../../../shared/ui/ThemeProvider'
import { CommandPalette } from './CommandPalette'

function LocationProbe() {
  const location = useLocation()
  return <span data-testid="location">{location.pathname}</span>
}

function renderPalette() {
  const onClose = vi.fn()
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={client}>
      <ThemeProvider>
        <MemoryRouter initialEntries={['/']}>
          <LocationProbe />
          <CommandPalette open onClose={onClose} />
        </MemoryRouter>
      </ThemeProvider>
    </QueryClientProvider>,
  )

  return { onClose, input: screen.getByRole('combobox') }
}

/** The row the palette would run if Enter were pressed now. */
function highlighted(): string {
  const option = screen
    .getAllByRole('option')
    .find((element) => element.getAttribute('aria-selected') === 'true')

  return option?.textContent ?? ''
}

describe('CommandPalette', () => {
  it('opens with the first command highlighted, under its group', () => {
    renderPalette()

    expect(screen.getByText('Create')).toBeInTheDocument()
    expect(screen.getByText('Go to')).toBeInTheDocument()
    expect(highlighted()).toContain('New note')
  })

  it('moves the highlight with the arrow keys and wraps at the ends', () => {
    const { input } = renderPalette()

    fireEvent.keyDown(input, { key: 'ArrowDown' })
    expect(highlighted()).toContain('New checklist')

    fireEvent.keyDown(input, { key: 'ArrowDown' })
    expect(highlighted()).toContain('New meeting note')

    fireEvent.keyDown(input, { key: 'ArrowUp' })
    expect(highlighted()).toContain('New checklist')

    // Up from the first row lands on the last one rather than sticking.
    fireEvent.keyDown(input, { key: 'ArrowUp' })
    fireEvent.keyDown(input, { key: 'ArrowUp' })
    const options = screen.getAllByRole('option')
    expect(highlighted()).toBe(options[options.length - 1].textContent)
  })

  it('points assistive technology at the highlighted row', () => {
    const { input } = renderPalette()

    const first = screen.getAllByRole('option')[0]
    expect(input).toHaveAttribute('aria-activedescendant', first.id)

    fireEvent.keyDown(input, { key: 'ArrowDown' })
    expect(input).toHaveAttribute('aria-activedescendant', screen.getAllByRole('option')[1].id)
  })

  it('filters as you type and runs the match on Enter', () => {
    const { input, onClose } = renderPalette()

    fireEvent.change(input, { target: { value: 'remind' } })

    // Fuzzy matching is loose by design, so what matters is that the closest
    // match is the one Enter would run.
    expect(screen.getAllByRole('option')[0]).toHaveTextContent('Reminders')
    expect(highlighted()).toContain('Reminders')

    fireEvent.keyDown(input, { key: 'Enter' })

    expect(onClose).toHaveBeenCalled()
    expect(screen.getByTestId('location')).toHaveTextContent('/reminders')
  })

  it('matches on letters that are not next to each other', () => {
    const { input } = renderPalette()

    fireEvent.change(input, { target: { value: 'nmn' } })

    expect(highlighted()).toContain('New meeting note')
  })

  it('says so when nothing matches, instead of showing an empty list', () => {
    const { input } = renderPalette()

    fireEvent.change(input, { target: { value: 'zzzz' } })

    expect(screen.queryByRole('option')).not.toBeInTheDocument()
    expect(screen.getByText(/No command matches/)).toBeInTheDocument()
  })

  it('runs a command when its row is clicked', () => {
    const { onClose } = renderPalette()

    const templates = screen
      .getAllByRole('option')
      .find((option) => option.textContent?.includes('Templates'))

    fireEvent.click(templates as HTMLElement)

    expect(onClose).toHaveBeenCalled()
    expect(screen.getByTestId('location')).toHaveTextContent('/templates')
  })

  it('does not offer Ask Pulse where the deployment has no AI', () => {
    renderPalette()

    expect(screen.queryByText('Ask Pulse')).not.toBeInTheDocument()
  })

  it('enters the notebook picker, and Backspace on an empty field leaves it', () => {
    const { input } = renderPalette()

    fireEvent.change(input, { target: { value: 'notebook' } })
    fireEvent.keyDown(input, { key: 'Enter' })

    expect(screen.getByRole('heading', { name: 'Go to notebook' })).toBeInTheDocument()

    fireEvent.keyDown(input, { key: 'Backspace' })

    expect(screen.getByRole('heading', { name: 'Commands' })).toBeInTheDocument()
  })

  /**
   * The rows are pointed at, not focused. Clicking one must therefore not take
   * the caret out of the field, or a command that keeps the palette open —
   * every picker — hands back a dialog with nowhere to type.
   */
  it('keeps the caret in the field when a row is clicked into a picker', async () => {
    const user = userEvent.setup()
    const { input } = renderPalette()

    const picker = screen
      .getAllByRole('option')
      .find((option) => option.textContent?.includes('Go to notebook'))

    await user.click(picker as HTMLElement)

    expect(screen.getByRole('heading', { name: 'Go to notebook' })).toBeInTheDocument()
    expect(input).toHaveFocus()
  })

  it('closes on Escape', () => {
    const { input, onClose } = renderPalette()

    fireEvent.keyDown(input, { key: 'Escape' })

    expect(onClose).toHaveBeenCalled()
  })
})
