/**
 * React's half of a suggestion menu.
 *
 * Owns the open/closed state and the keyboard, for both `/` and `@`. The
 * plugin pushes snapshots in through the bridge and this hook decides what is
 * highlighted — which is why the menu component itself is a pure list that
 * takes props and can be tested without an editor anywhere near it.
 */

import { useCallback, useEffect, useRef, useState } from 'react'

import type { SuggestionBridge, SuggestionMenuSnapshot } from './extensions/suggestionBridge'

export interface SuggestionMenuController {
  snapshot: SuggestionMenuSnapshot
  activeIndex: number
  setActiveIndex: (index: number) => void
  select: (id: string) => void
}

export function useSuggestionMenu(bridge: SuggestionBridge): SuggestionMenuController | null {
  const [snapshot, setSnapshot] = useState<SuggestionMenuSnapshot | null>(null)
  const [activeIndex, setActiveIndex] = useState(0)

  const latest = useRef({ snapshot, activeIndex })
  useEffect(() => {
    latest.current = { snapshot, activeIndex }
  })

  useEffect(() => {
    bridge.render = (next) => {
      setSnapshot(next)
      // The list changed under the cursor, so the highlight goes back to the
      // top rather than pointing at whatever now happens to sit at index 3.
      setActiveIndex(0)
    }

    bridge.keydown = (event) => {
      const current = latest.current.snapshot
      const index = latest.current.activeIndex
      const count = current?.items.length ?? 0
      if (!current || count === 0) return false

      switch (event.key) {
        case 'ArrowDown':
          setActiveIndex((index + 1) % count)
          return true
        case 'ArrowUp':
          setActiveIndex((index - 1 + count) % count)
          return true
        case 'Home':
          setActiveIndex(0)
          return true
        case 'End':
          setActiveIndex(count - 1)
          return true
        case 'Enter':
        case 'Tab': {
          const item = current.items[index]
          if (!item) return false
          current.select(item.id)
          return true
        }
        default:
          // Escape included: the suggestion plugin closes itself, and letting
          // it do so keeps one exit path instead of two that can disagree.
          return false
      }
    }

    return () => {
      bridge.render = null
      bridge.keydown = null
    }
  }, [bridge])

  const select = useCallback(
    (id: string) => {
      snapshot?.select(id)
    },
    [snapshot],
  )

  if (!snapshot) return null

  return { snapshot, activeIndex, setActiveIndex, select }
}
