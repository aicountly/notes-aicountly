/**
 * The handful of choices that live on the device rather than on the server.
 *
 * There is no preferences endpoint in this API, and inventing one client-side —
 * a "settings" note, a synced blob — would be a second source of truth for
 * something as small as which layout a list opens in. So these are stored in
 * `localStorage`, per device, and every one of them is read by something that
 * already exists:
 *
 *   - `notes:view` is the note list's layout. `NotesPage` reads it on mount,
 *     which is what makes the control in Settings do something rather than
 *     record an opinion.
 *   - `notes:sort` is that list's order, read the same way.
 *   - `notes:default-notebook` is where a note goes when it is created without
 *     a notebook in mind — today, from a template.
 *
 * Storage that throws is the normal case in a private window, so every read
 * falls back and every write is allowed to fail. No preference is worth an
 * exception during render.
 */

import { useCallback, useState } from 'react'

/** Owned by `features/notes/pages/NotesPage.tsx`; changing it here changes nothing there. */
export const VIEW_KEY = 'notes:view'
export const SORT_KEY = 'notes:sort'
export const DEFAULT_NOTEBOOK_KEY = 'notes:default-notebook'

export type NoteView = 'grid' | 'list' | 'compact'

/** The same three the list itself offers, with the words it uses for them. */
export const NOTE_VIEWS: { value: NoteView; label: string; description: string }[] = [
  { value: 'grid', label: 'Cards', description: 'A wall of notes, colour and all.' },
  { value: 'list', label: 'List', description: 'One line each, with the first line of the body.' },
  { value: 'compact', label: 'Compact', description: 'Titles only — the most notes on screen.' },
]

export const NOTE_SORTS: { value: string; label: string }[] = [
  { value: 'updated_desc', label: 'Recently updated' },
  { value: 'created_desc', label: 'Recently created' },
  { value: 'title_asc', label: 'Title A–Z' },
  { value: 'updated_asc', label: 'Oldest first' },
]

export function readPreference(key: string): string | null {
  try {
    return window.localStorage.getItem(key)
  } catch {
    return null
  }
}

export function writePreference(key: string, value: string | null): void {
  try {
    if (value === null) window.localStorage.removeItem(key)
    else window.localStorage.setItem(key, value)
  } catch {
    /* Private mode. The choice applies for this session and is not remembered. */
  }
}

/**
 * A stored preference as React state.
 *
 * The initial read happens once, in the state initialiser, so a render never
 * touches storage — and the setter writes through, so nothing has to remember
 * to persist it afterwards.
 */
export function usePreference<T extends string>(
  key: string,
  allowed: readonly T[],
  fallback: T,
): [T, (next: T) => void] {
  const [value, setValue] = useState<T>(() => {
    const stored = readPreference(key) as T | null
    return stored !== null && allowed.includes(stored) ? stored : fallback
  })

  const update = useCallback(
    (next: T) => {
      setValue(next)
      writePreference(key, next)
    },
    [key],
  )

  return [value, update]
}

/**
 * The notebook new notes land in, or null for "none in particular".
 *
 * Not validated against the notebook tree here: a notebook that has since been
 * deleted is the server's 404 to give, and silently clearing the preference
 * because a tree query has not answered yet would lose it on every cold start.
 */
export function useDefaultNotebook(): [string | null, (id: string | null) => void] {
  const [value, setValue] = useState<string | null>(() => readPreference(DEFAULT_NOTEBOOK_KEY))

  const update = useCallback((id: string | null) => {
    setValue(id)
    writePreference(DEFAULT_NOTEBOOK_KEY, id)
  }, [])

  return [value, update]
}
