/**
 * Global shortcuts.
 *
 * The rule these protect: nothing fires while someone is typing. A notes app
 * where pressing "/" mid-sentence opens a search dialog is an app that fights
 * its user, and it is the easiest thing in the world to ship by accident.
 */

import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route, useLocation } from 'react-router-dom'

import { useKeyboardShortcuts } from './useKeyboardShortcuts'

function Harness({ onOpenPalette, onOpenSearch }: { onOpenPalette: () => void; onOpenSearch: () => void }) {
  useKeyboardShortcuts({ onOpenPalette, onOpenSearch })
  const location = useLocation()

  return (
    <div>
      <span data-testid="path">{location.pathname}</span>
      <input aria-label="A text field" />
      <div contentEditable aria-label="The note body" data-testid="body" />
      <button>Somewhere to focus</button>
    </div>
  )
}

function setup() {
  const onOpenPalette = vi.fn()
  const onOpenSearch = vi.fn()

  render(
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route path="*" element={<Harness onOpenPalette={onOpenPalette} onOpenSearch={onOpenSearch} />} />
      </Routes>
    </MemoryRouter>,
  )

  return { onOpenPalette, onOpenSearch, user: userEvent.setup() }
}

describe('useKeyboardShortcuts', () => {
  it('opens the command palette on Ctrl/Cmd+K', async () => {
    const { onOpenPalette, user } = setup()

    await user.keyboard('{Control>}k{/Control}')
    expect(onOpenPalette).toHaveBeenCalledTimes(1)
  })

  it('opens search on "/" outside a text field', async () => {
    const { onOpenSearch, user } = setup()

    await user.click(screen.getByRole('button', { name: 'Somewhere to focus' }))
    await user.keyboard('/')
    expect(onOpenSearch).toHaveBeenCalledTimes(1)
  })

  it('does not fire an unmodified shortcut while typing in a field', async () => {
    const { onOpenSearch, user } = setup()

    await user.click(screen.getByLabelText('A text field'))
    await user.keyboard('and/or')

    expect(onOpenSearch).not.toHaveBeenCalled()
    expect(screen.getByLabelText('A text field')).toHaveValue('and/or')
  })

  it('does not fire while typing in the note body', async () => {
    const { onOpenSearch, user } = setup()

    // contentEditable is the editor. A shortcut firing here would interrupt
    // the one thing this app exists for.
    await user.click(screen.getByTestId('body'))
    await user.keyboard('half a thought / and another')

    expect(onOpenSearch).not.toHaveBeenCalled()
  })

  it('still fires a modified shortcut while typing', async () => {
    const { onOpenPalette, user } = setup()

    await user.click(screen.getByLabelText('A text field'))
    await user.keyboard('{Control>}k{/Control}')

    expect(onOpenPalette).toHaveBeenCalledTimes(1)
  })

  it('navigates to a new note on Ctrl/Cmd+N', async () => {
    const { user } = setup()

    await user.keyboard('{Control>}n{/Control}')
    expect(screen.getByTestId('path')).toHaveTextContent('/notes/new')
  })

  it('opens the shortcut reference on Ctrl/Cmd+/', async () => {
    const { user } = setup()

    await user.keyboard('{Control>}/{/Control}')
    expect(screen.getByTestId('path')).toHaveTextContent('/help')
  })

  it('leaves the browser its own shortcuts', async () => {
    const { onOpenPalette, onOpenSearch, user } = setup()

    // Ctrl+P, Ctrl+S, Ctrl+F belong to the browser; claiming them surprises
    // people for no gain.
    await user.keyboard('{Control>}p{/Control}')
    await user.keyboard('{Control>}s{/Control}')
    await user.keyboard('{Control>}f{/Control}')

    expect(onOpenPalette).not.toHaveBeenCalled()
    expect(onOpenSearch).not.toHaveBeenCalled()
  })

  it('opens global search on Ctrl/Cmd+Shift+F', async () => {
    const { onOpenSearch, user } = setup()

    await user.keyboard('{Control>}{Shift>}f{/Shift}{/Control}')
    expect(onOpenSearch).toHaveBeenCalledTimes(1)
  })
})
