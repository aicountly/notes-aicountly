/**
 * Light / dark / system.
 *
 * The three states are a data attribute and its *absence*, which is easy to get
 * wrong by storing "system" as a value and stamping it. These check that
 * "follow the system" stays a real choice rather than a frozen snapshot of what
 * the system happened to be.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, act } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { ThemeProvider, useTheme } from './ThemeProvider'

type MediaListener = (event: MediaQueryListEvent) => void

/** A matchMedia whose value can be changed, the way an OS setting changes. */
function stubSystemTheme(initial: 'light' | 'dark') {
  let matches = initial === 'dark'
  const listeners = new Set<MediaListener>()

  vi.stubGlobal(
    'matchMedia',
    (query: string) => ({
      get matches() {
        return matches
      },
      media: query,
      onchange: null,
      addEventListener: (_: string, listener: MediaListener) => listeners.add(listener),
      removeEventListener: (_: string, listener: MediaListener) => listeners.delete(listener),
      addListener: () => undefined,
      removeListener: () => undefined,
      dispatchEvent: () => false,
    }),
  )

  return {
    set(next: 'light' | 'dark') {
      matches = next === 'dark'
      act(() => {
        for (const listener of listeners) listener({ matches } as MediaQueryListEvent)
      })
    },
  }
}

function Probe() {
  const { preference, resolved, setPreference } = useTheme()
  return (
    <div>
      <span data-testid="preference">{preference}</span>
      <span data-testid="resolved">{resolved}</span>
      <button onClick={() => setPreference('dark')}>Dark</button>
      <button onClick={() => setPreference('light')}>Light</button>
      <button onClick={() => setPreference('system')}>System</button>
    </div>
  )
}

beforeEach(() => {
  window.localStorage.clear()
  document.documentElement.removeAttribute('data-theme')
})

describe('ThemeProvider', () => {
  it('follows the system by default and stamps nothing', () => {
    stubSystemTheme('dark')
    render(<ThemeProvider><Probe /></ThemeProvider>)

    expect(screen.getByTestId('preference')).toHaveTextContent('system')
    expect(screen.getByTestId('resolved')).toHaveTextContent('dark')
    // Nothing stamped: the CSS falls through to prefers-color-scheme, which is
    // what makes "system" a live choice rather than a snapshot.
    expect(document.documentElement.hasAttribute('data-theme')).toBe(false)
  })

  it('tracks the system changing while the app is open', () => {
    const system = stubSystemTheme('light')
    render(<ThemeProvider><Probe /></ThemeProvider>)

    expect(screen.getByTestId('resolved')).toHaveTextContent('light')
    system.set('dark')
    expect(screen.getByTestId('resolved')).toHaveTextContent('dark')
  })

  it('stamps an explicit choice so it wins in both directions', async () => {
    stubSystemTheme('dark')
    render(<ThemeProvider><Probe /></ThemeProvider>)

    // Choosing light while the system is dark must actually produce light.
    await userEvent.click(screen.getByRole('button', { name: 'Light' }))
    expect(document.documentElement.getAttribute('data-theme')).toBe('light')
    expect(screen.getByTestId('resolved')).toHaveTextContent('light')
  })

  it('stops tracking the system once a choice is made', async () => {
    const system = stubSystemTheme('light')
    render(<ThemeProvider><Probe /></ThemeProvider>)

    await userEvent.click(screen.getByRole('button', { name: 'Dark' }))
    system.set('light')

    expect(screen.getByTestId('resolved')).toHaveTextContent('dark')
  })

  it('goes back to following the system when asked', async () => {
    stubSystemTheme('dark')
    render(<ThemeProvider><Probe /></ThemeProvider>)

    await userEvent.click(screen.getByRole('button', { name: 'Light' }))
    await userEvent.click(screen.getByRole('button', { name: 'System' }))

    expect(document.documentElement.hasAttribute('data-theme')).toBe(false)
    expect(screen.getByTestId('resolved')).toHaveTextContent('dark')
  })

  it('remembers the choice across a reload', async () => {
    stubSystemTheme('light')
    const { unmount } = render(<ThemeProvider><Probe /></ThemeProvider>)

    await userEvent.click(screen.getByRole('button', { name: 'Dark' }))
    expect(window.localStorage.getItem('notes:theme')).toBe('dark')
    unmount()

    render(<ThemeProvider><Probe /></ThemeProvider>)
    expect(screen.getByTestId('preference')).toHaveTextContent('dark')
  })

  it('still renders when storage is unavailable', () => {
    stubSystemTheme('light')
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('private mode')
    })

    // Private-mode Safari throws on access. Losing the remembered theme is
    // acceptable; failing to render is not.
    expect(() => render(<ThemeProvider><Probe /></ThemeProvider>)).not.toThrow()
    expect(screen.getByTestId('preference')).toHaveTextContent('system')

    getItem.mockRestore()
  })
})
