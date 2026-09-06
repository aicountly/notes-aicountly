/**
 * The advanced filters.
 *
 * Two groups, and the split is not cosmetic. The first is sent to
 * `GET /search/notes` and narrows the search itself. The second — pinned and
 * shared — is not a filter the endpoint accepts, so it refines the results that
 * came back and says so. Presenting the two as one list would promise a search
 * of the whole library that only ever looked at one page of it.
 */

import { useId } from 'react'

import { Button } from '../../../shared/ui/primitives'
import type { ApiError } from '../../../shared/api/client'
import type { NoteType, Tag } from '../../../shared/api/types'
import { NO_FILTERS, activeFilterCount } from '../hooks/useSearch'
import type { NotebookOption, SearchFilterState } from '../hooks/useSearch'

const DATE_RANGES: ReadonlyArray<{ value: number | null; label: string }> = [
  { value: null, label: 'Any time' },
  { value: 1, label: 'Today' },
  { value: 7, label: 'Last 7 days' },
  { value: 30, label: 'Last 30 days' },
  { value: 90, label: 'Last 3 months' },
  { value: 365, label: 'Last year' },
]

const NOTE_TYPES: ReadonlyArray<{ value: NoteType; label: string }> = [
  { value: 'document', label: 'Document' },
  { value: 'checklist', label: 'Checklist' },
  { value: 'meeting', label: 'Meeting' },
  { value: 'voice', label: 'Voice' },
  { value: 'scan', label: 'Scan' },
  { value: 'drawing', label: 'Drawing' },
  { value: 'canvas', label: 'Canvas' },
]

export interface SearchFiltersProps {
  id: string
  value: SearchFilterState
  onChange: (next: SearchFilterState) => void
  notebooks: NotebookOption[]
  tags: Tag[]
  loading: boolean
  /** A lookup that failed still leaves every other filter usable. */
  lookupError: ApiError | null
}

export function SearchFilters({
  id,
  value,
  onChange,
  notebooks,
  tags,
  loading,
  lookupError,
}: SearchFiltersProps) {
  const dateId = useId()
  const notebookId = useId()
  const tagId = useId()
  const typeId = useId()

  const set = <K extends keyof SearchFilterState>(key: K, next: SearchFilterState[K]) =>
    onChange({ ...value, [key]: next })

  const count = activeFilterCount(value)

  /**
   * Why a picker is disabled, in the picker itself.
   *
   * "Any notebook" above an empty, greyed-out list reads as a bug. It matters
   * which of the three reasons applies: still arriving, the lookup failed, or
   * there genuinely are none to pick from.
   */
  const placeholder = (kind: 'notebook' | 'tag', empty: boolean): string => {
    if (loading) return kind === 'notebook' ? 'Loading notebooks…' : 'Loading tags…'
    if (lookupError) return 'Unavailable'
    if (empty) return kind === 'notebook' ? 'No notebooks yet' : 'No tags yet'
    return kind === 'notebook' ? 'Any notebook' : 'Any tag'
  }

  return (
    <div className="search-filters" id={id}>
      <div className="search-filters__grid">
        <div className="search-field">
          <label className="search-field__label" htmlFor={dateId}>
            Updated
          </label>
          <select
            id={dateId}
            className="search-field__control"
            value={value.withinDays === null ? '' : String(value.withinDays)}
            onChange={(event) =>
              set('withinDays', event.target.value === '' ? null : Number(event.target.value))
            }
          >
            {DATE_RANGES.map((range) => (
              <option key={range.label} value={range.value === null ? '' : String(range.value)}>
                {range.label}
              </option>
            ))}
          </select>
        </div>

        <div className="search-field">
          <label className="search-field__label" htmlFor={notebookId}>
            Notebook
          </label>
          <select
            id={notebookId}
            className="search-field__control"
            value={value.notebookId ?? ''}
            disabled={loading || notebooks.length === 0}
            onChange={(event) => set('notebookId', event.target.value === '' ? null : event.target.value)}
          >
            <option value="">{placeholder('notebook', notebooks.length === 0)}</option>
            {notebooks.map((notebook) => (
              <option key={notebook.id} value={notebook.id}>
                {'— '.repeat(notebook.depth)}
                {notebook.name}
              </option>
            ))}
          </select>
        </div>

        <div className="search-field">
          <label className="search-field__label" htmlFor={tagId}>
            Tag
          </label>
          <select
            id={tagId}
            className="search-field__control"
            value={value.tagSlug ?? ''}
            disabled={loading || tags.length === 0}
            onChange={(event) => set('tagSlug', event.target.value === '' ? null : event.target.value)}
          >
            <option value="">{placeholder('tag', tags.length === 0)}</option>
            {tags.map((tag) => (
              <option key={tag.id} value={tag.slug}>
                {tag.name}
              </option>
            ))}
          </select>
        </div>

        <div className="search-field">
          <label className="search-field__label" htmlFor={typeId}>
            Note type
          </label>
          <select
            id={typeId}
            className="search-field__control"
            value={value.noteType ?? ''}
            onChange={(event) =>
              set('noteType', event.target.value === '' ? null : (event.target.value as NoteType))
            }
          >
            <option value="">Any type</option>
            {NOTE_TYPES.map((type) => (
              <option key={type.value} value={type.value}>
                {type.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      {lookupError ? (
        <p className="search-filters__error" role="status">
          {lookupError.isOffline
            ? 'Notebooks and tags are unavailable offline.'
            : lookupError.message}
        </p>
      ) : null}

      <div className="search-filters__groups">
        <fieldset className="search-filters__group">
          <legend className="search-field__label">Search</legend>
          <label className="search-check">
            <input
              type="checkbox"
              checked={value.hasAttachment}
              onChange={(event) => set('hasAttachment', event.target.checked)}
            />
            <span>Has an attachment</span>
          </label>
          <label className="search-check">
            <input
              type="checkbox"
              checked={value.includeArchived}
              onChange={(event) => set('includeArchived', event.target.checked)}
            />
            <span>Include archived notes</span>
          </label>
        </fieldset>

        <fieldset className="search-filters__group">
          <legend className="search-field__label">Refine these results</legend>
          <label className="search-check">
            <input
              type="checkbox"
              checked={value.pinnedOnly}
              onChange={(event) => set('pinnedOnly', event.target.checked)}
            />
            <span>Pinned only</span>
          </label>
          <label className="search-check">
            <input
              type="checkbox"
              checked={value.sharedOnly}
              onChange={(event) => set('sharedOnly', event.target.checked)}
            />
            <span>Shared only</span>
          </label>
          <p className="search-filters__hint">Applied to the results shown, not to the search.</p>
        </fieldset>
      </div>

      <div className="search-filters__footer">
        <Button
          variant="ghost"
          size="sm"
          icon="undo"
          disabled={count === 0}
          onClick={() => onChange(NO_FILTERS)}
        >
          Clear filters
        </Button>
      </div>
    </div>
  )
}
