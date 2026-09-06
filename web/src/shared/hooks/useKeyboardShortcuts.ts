/**
 * Global keyboard shortcuts.
 *
 * Two rules keep these from fighting the editor:
 *
 *   1. Nothing fires while the user is typing in a field or in the note body,
 *      unless it uses a modifier. Otherwise pressing "n" mid-sentence creates
 *      a note.
 *   2. Only shortcuts the browser does not already own are claimed. Cmd/Ctrl+K
 *      and Cmd/Ctrl+N are the exceptions, and both are preventDefault'ed
 *      deliberately — the palette and a new note are what a user means by them
 *      inside an app like this.
 */

import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'

export interface ShortcutHandlers {
  onOpenPalette: () => void
  onOpenSearch: () => void
}

function isTypingContext(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  if (target.isContentEditable) return true
  return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
}

export function useKeyboardShortcuts({ onOpenPalette, onOpenSearch }: ShortcutHandlers): void {
  const navigate = useNavigate()

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const modifier = event.metaKey || event.ctrlKey
      const key = event.key.toLowerCase()

      if (modifier && key === 'k' && !event.shiftKey) {
        event.preventDefault()
        onOpenPalette()
        return
      }

      if (modifier && event.shiftKey && key === 'f') {
        event.preventDefault()
        onOpenSearch()
        return
      }

      if (modifier && key === 'n' && !event.shiftKey) {
        event.preventDefault()
        navigate('/notes/new')
        return
      }

      if (modifier && key === '/') {
        event.preventDefault()
        navigate('/help')
        return
      }

      // Unmodified single keys are only shortcuts outside a text field.
      if (modifier || event.altKey || isTypingContext(event.target)) return

      if (key === '/') {
        event.preventDefault()
        onOpenSearch()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [navigate, onOpenPalette, onOpenSearch])
}
