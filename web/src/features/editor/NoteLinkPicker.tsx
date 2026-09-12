/**
 * Choosing the note to link to.
 *
 * Searches by title and body rather than offering a flat list: by the time a
 * link is worth making there are more notes than a dropdown can hold. The
 * chosen note is inserted by **id**, so renaming it later does not break the
 * link — see the `noteLink` extension.
 */

import { useEffect, useId, useState } from 'react'
import { useQuery } from '@tanstack/react-query'

import { ApiError, api } from '../../shared/api/client'
import { queryKeys } from '../../shared/query/queryClient'
import { Button, Dialog, EmptyState, Skeleton } from '../../shared/ui/primitives'
import { Icon } from '../../shared/ui/Icon'
import type { SearchHit } from '../../shared/api/types'
import './editor.css'

/** `SearchSnippet::HIGHLIGHT_OPEN` / `_CLOSE`, removed rather than rendered. */
function plainSnippet(snippet: string): string {
  return snippet.replaceAll('[[hl]]', '').replaceAll('[[/hl]]', '')
}

export interface NoteLinkPickerProps {
  open: boolean
  /** Excluded from the results: a note linking to itself is never what was meant. */
  currentNoteId: string
  onClose: () => void
  onSelect: (note: { noteId: string; label: string }) => void
}

export function NoteLinkPicker({ open, currentNoteId, onClose, onSelect }: NoteLinkPickerProps) {
  const searchId = useId()
  const [term, setTerm] = useState('')
  const [debounced, setDebounced] = useState('')

  // Both halves are cleared, not just the field: leaving `debounced` behind
  // would re-run the previous search the moment the dialog is reopened, and
  // show its hits under an empty search box.
  useEffect(() => {
    if (!open) {
      setTerm('')
      setDebounced('')
    }
  }, [open])

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(term.trim()), 200)
    return () => window.clearTimeout(timer)
  }, [term])

  const results = useQuery<SearchHit[], ApiError>({
    queryKey: queryKeys.search(debounced, { scope: 'note-link' }),
    enabled: open && debounced.length > 1,
    queryFn: () => api.get<SearchHit[]>('/search/notes', { query: { q: debounced, limit: 12 } }),
  })

  const hits = (results.data ?? []).filter((hit) => hit.note_id !== currentNoteId)

  return (
    <Dialog open={open} onClose={onClose} title="Link to a note" width={520}>
      <div className="editor-field">
        <label className="editor-field__label" htmlFor={searchId}>
          Search your notes
        </label>
        <input
          id={searchId}
          className="editor-field__input"
          type="search"
          autoComplete="off"
          placeholder="Title or anything in the text"
          value={term}
          onChange={(event) => setTerm(event.target.value)}
        />
      </div>

      <div className="note-link-picker__results">
        {debounced.length <= 1 ? (
          <p className="note-link-picker__hint">Type at least two characters.</p>
        ) : results.isPending ? (
          <ul className="note-link-picker__list">
            {[0, 1, 2].map((row) => (
              <li key={row} className="note-link-picker__row">
                <Skeleton width="60%" height={13} />
                <Skeleton width="85%" height={11} />
              </li>
            ))}
          </ul>
        ) : results.error ? (
          <EmptyState
            icon={results.error.isOffline ? 'cloud-off' : 'alert'}
            title={results.error.isOffline ? 'You are offline' : 'That search did not work'}
            description={results.error.message}
            action={
              <Button icon="refresh" onClick={() => void results.refetch()}>
                Try again
              </Button>
            }
          />
        ) : hits.length === 0 ? (
          <EmptyState
            icon="search"
            title="No notes match"
            description={`Nothing found for “${debounced}”. Try a different word.`}
          />
        ) : (
          <ul className="note-link-picker__list">
            {hits.map((hit) => (
              <li key={hit.note_id}>
                <button
                  type="button"
                  className="note-link-picker__row note-link-picker__row--button"
                  onClick={() => onSelect({ noteId: hit.note_id, label: hit.display_title })}
                >
                  <span className="note-link-picker__title">
                    <Icon name="note" size={15} aria-hidden />
                    {hit.display_title}
                  </span>
                  {/* The snippet arrives with the server's match delimiters
                      around each hit. They are text, not markup — and in a
                      list this short, plain text reads better than highlights. */}
                  <span className="note-link-picker__snippet">{plainSnippet(hit.snippet)}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Dialog>
  )
}
