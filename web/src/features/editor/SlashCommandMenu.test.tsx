/**
 * The menu behind `/`.
 *
 * Tested through what a user does with it: read the groups, see what is
 * highlighted, and pick something — with the mouse without losing the caret,
 * or with the keyboard without touching the mouse.
 */

import { act, render, renderHook, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { SlashCommandMenu } from './SlashCommandMenu'
import { createSuggestionBridge } from './extensions/suggestionBridge'
import type { SuggestionMenuItem } from './extensions/suggestionBridge'
import { useSuggestionMenu } from './useSuggestionMenu'

const ITEMS: SuggestionMenuItem[] = [
  { id: 'h1', label: 'Heading 1', group: 'Basic', icon: 'heading', hint: '#' },
  { id: 'h2', label: 'Heading 2', group: 'Basic', icon: 'heading', hint: '##' },
  { id: 'table', label: 'Table', group: 'Blocks', icon: 'table' },
]

function renderMenu(overrides: Partial<Parameters<typeof SlashCommandMenu>[0]> = {}) {
  const onSelect = vi.fn()
  const onHover = vi.fn()

  render(
    <SlashCommandMenu
      items={ITEMS}
      activeIndex={0}
      loading={false}
      rect={new DOMRect(10, 20, 0, 18)}
      idPrefix="slash"
      label="Blocks"
      emptyMessage="No blocks match what you typed."
      onHover={onHover}
      onSelect={onSelect}
      {...overrides}
    />,
  )

  return { onSelect, onHover }
}

describe('SlashCommandMenu', () => {
  it('shows each group once, above the items in it', () => {
    renderMenu()

    expect(screen.getAllByText('Basic')).toHaveLength(1)
    expect(screen.getAllByText('Blocks')).toHaveLength(1)
    expect(screen.getAllByRole('option')).toHaveLength(3)
  })

  it('marks the highlighted row for assistive technology', () => {
    renderMenu({ activeIndex: 2 })

    const options = screen.getAllByRole('option')
    expect(options[2]).toHaveAttribute('aria-selected', 'true')
    expect(options[0]).toHaveAttribute('aria-selected', 'false')
    expect(screen.getByRole('listbox')).toHaveAttribute('aria-activedescendant', 'slash-option-2')
  })

  it('chooses on mousedown so the caret never leaves the note', () => {
    const { onSelect } = renderMenu()

    const option = screen.getAllByRole('option')[1]
    const event = new MouseEvent('mousedown', { bubbles: true, cancelable: true })
    option.dispatchEvent(event)

    expect(onSelect).toHaveBeenCalledWith('h2')
    expect(event.defaultPrevented).toBe(true)
  })

  it('says so when nothing matches instead of showing an empty box', () => {
    renderMenu({ items: [] })

    expect(screen.getByText('No blocks match what you typed.')).toBeInTheDocument()
  })

  it('names each group, rather than drawing a heading only sighted users get', () => {
    renderMenu()

    expect(screen.getByRole('group', { name: 'Basic' })).toContainElement(
      screen.getAllByRole('option')[0],
    )
    expect(screen.getByRole('group', { name: 'Blocks' })).toContainElement(
      screen.getAllByRole('option')[2],
    )
  })

  it('keeps the rows out of the tab order so the caret stays in the note', () => {
    renderMenu()

    for (const option of screen.getAllByRole('option')) {
      expect(option).toHaveAttribute('tabindex', '-1')
    }
  })

  it('scrolls the highlighted row into view, since the list is taller than the panel', () => {
    const scrollIntoView = vi
      .spyOn(Element.prototype, 'scrollIntoView')
      .mockImplementation(() => undefined)

    renderMenu({ activeIndex: 2 })

    expect(scrollIntoView).toHaveBeenCalled()
    expect(scrollIntoView.mock.instances.at(-1)).toBe(screen.getAllByRole('option')[2])
  })
})

describe('useSuggestionMenu', () => {
  it('is driven entirely from the keyboard', () => {
    const bridge = createSuggestionBridge()
    const select = vi.fn()
    const { result } = renderHook(() => useSuggestionMenu(bridge))

    expect(result.current).toBeNull()

    act(() => {
      bridge.render?.({ items: ITEMS, query: '', loading: false, rect: null, select })
    })

    expect(result.current?.activeIndex).toBe(0)

    act(() => {
      bridge.keydown?.(new KeyboardEvent('keydown', { key: 'ArrowDown' }))
    })
    expect(result.current?.activeIndex).toBe(1)

    // Wraps rather than sticking at the end — a short list should not need
    // eight presses to get back to the top.
    act(() => {
      bridge.keydown?.(new KeyboardEvent('keydown', { key: 'ArrowUp' }))
      bridge.keydown?.(new KeyboardEvent('keydown', { key: 'ArrowUp' }))
    })
    expect(result.current?.activeIndex).toBe(2)

    act(() => {
      bridge.keydown?.(new KeyboardEvent('keydown', { key: 'Enter' }))
    })
    expect(select).toHaveBeenCalledWith('table')
  })

  it('leaves keys it does not use to the editor', () => {
    const bridge = createSuggestionBridge()
    renderHook(() => useSuggestionMenu(bridge))

    act(() => {
      bridge.render?.({
        items: ITEMS,
        query: '',
        loading: false,
        rect: null,
        select: vi.fn(),
      })
    })

    // Escape belongs to the suggestion plugin, which closes itself; a
    // character key belongs to the document.
    expect(bridge.keydown?.(new KeyboardEvent('keydown', { key: 'Escape' }))).toBe(false)
    expect(bridge.keydown?.(new KeyboardEvent('keydown', { key: 'a' }))).toBe(false)
  })

  it('closes when the plugin exits', () => {
    const bridge = createSuggestionBridge()
    const { result } = renderHook(() => useSuggestionMenu(bridge))

    act(() => {
      bridge.render?.({ items: ITEMS, query: '', loading: false, rect: null, select: vi.fn() })
    })
    expect(result.current).not.toBeNull()

    act(() => {
      bridge.render?.(null)
    })
    expect(result.current).toBeNull()
  })
})
