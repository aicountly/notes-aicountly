/**
 * Inline tag entry.
 *
 * The whole point is that adding a tag costs one gesture: type, press Enter.
 * Everything else follows from that.
 *
 *   - **`#gst` and `gst` are the same thing.** The leading hash is how a tag is
 *     written inline, so it is accepted and stripped rather than becoming part
 *     of the name.
 *   - **Case does not create a second tag.** "GST" and "gst" fold to one slug
 *     here exactly as they do on the server, so the list never offers both and
 *     the note never ends up carrying both.
 *   - **A name that does not exist yet is not an error.** It becomes a tag —
 *     the picker hands the name back, and saving the note (or the folder rule)
 *     is what makes the row. There is no "create tag first" step.
 *   - **Backspace on an empty field removes the last tag**, because that is
 *     what every chip field does and a user will try it before looking for
 *     a remove button.
 *
 * The component owns no server state: it takes the names it should show and
 * reports the names it should now show.
 */

import { useId, useMemo, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import type { Tag } from '../../../shared/api/types'
import { MAX_TAGS_PER_NOTE, tagDisplayName, tagSlug, uniqueTagNames, useTags } from '../hooks/useTags'
import '../tags.css'

export interface TagPickerProps {
  /** The tag names currently applied, in the order they should be shown. */
  value: string[]
  onChange: (names: string[]) => void
  /** Visible label. Always rendered — a chip field with no label is a mystery. */
  label?: string
  placeholder?: string
  disabled?: boolean
  /** How many tags may be applied. One is how a single-tag rule uses this. */
  max?: number
}

type Suggestion = { kind: 'tag'; tag: Tag } | { kind: 'create'; name: string }

const SUGGESTION_LIMIT = 8

export function TagPicker({
  value,
  onChange,
  label = 'Tags',
  placeholder = 'Add a tag…',
  disabled = false,
  max = MAX_TAGS_PER_NOTE,
}: TagPickerProps) {
  const tags = useTags()
  const inputRef = useRef<HTMLInputElement>(null)
  const inputId = useId()
  const listId = useId()
  const optionId = useId()

  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)

  const full = value.length >= max
  const appliedSlugs = useMemo(() => new Set(value.map(tagSlug)), [value])

  const suggestions = useMemo<Suggestion[]>(() => {
    const needle = tagSlug(query)
    const matches = (tags.data ?? [])
      .filter((tag) => !appliedSlugs.has(tag.slug))
      .filter((tag) => needle === '' || tag.slug.includes(needle) || tag.name.toLowerCase().includes(needle))
      .slice(0, SUGGESTION_LIMIT)
      .map<Suggestion>((tag) => ({ kind: 'tag', tag }))

    // Offered only when the typed name is not already a tag and not already
    // applied — "Create gst" under an existing gst is a lie.
    const exists = (tags.data ?? []).some((tag) => tag.slug === needle)
    if (needle !== '' && !exists && !appliedSlugs.has(needle)) {
      return [{ kind: 'create', name: tagDisplayName(query) }, ...matches]
    }

    return matches
  }, [tags.data, query, appliedSlugs])

  const apply = (name: string) => {
    const next = uniqueTagNames([...value, name]).slice(0, max)
    onChange(next)
    setQuery('')
    setActive(0)
    setOpen(false)
    inputRef.current?.focus()
  }

  const remove = (name: string) => {
    const slug = tagSlug(name)
    onChange(value.filter((applied) => tagSlug(applied) !== slug))
    inputRef.current?.focus()
  }

  const commit = () => {
    const chosen = suggestions[active]
    if (chosen) {
      apply(chosen.kind === 'tag' ? chosen.tag.name : chosen.name)
      return
    }
    if (tagSlug(query) !== '') apply(query)
  }

  const onKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Backspace' && query === '' && value.length > 0) {
      event.preventDefault()
      remove(value[value.length - 1])
      return
    }
    if (event.key === 'Enter' || event.key === ',') {
      // Enter inside a dialog would otherwise submit the form behind it.
      event.preventDefault()
      commit()
      return
    }
    if (event.key === 'Escape' && open) {
      event.stopPropagation()
      setOpen(false)
      return
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (suggestions.length === 0) return
      event.preventDefault()
      setOpen(true)
      setActive((current) => {
        const next = event.key === 'ArrowDown' ? current + 1 : current - 1
        return (next + suggestions.length) % suggestions.length
      })
    }
  }

  return (
    <div className="org-field tag-picker">
      <label className="org-label" htmlFor={inputId}>
        {label}
      </label>

      <div className={`tag-picker__box ${disabled ? 'tag-picker__box--disabled' : ''}`.trim()}>
        <ul className="tag-picker__chips">
          {value.map((name) => (
            <li key={tagSlug(name)}>
              <span className="tag-chip">
                <Icon name="tag" size={12} />
                {tagDisplayName(name)}
                <button
                  type="button"
                  className="tag-chip__remove"
                  aria-label={`Remove tag ${tagDisplayName(name)}`}
                  disabled={disabled}
                  onClick={() => remove(name)}
                >
                  <Icon name="close" size={11} />
                </button>
              </span>
            </li>
          ))}
        </ul>

        {full ? (
          <p className="org-hint">
            {max === 1 ? 'Remove the tag above to choose another.' : `That is the limit of ${max} tags.`}
          </p>
        ) : (
          <input
            ref={inputRef}
            id={inputId}
            className="tag-picker__input"
            type="text"
            role="combobox"
            autoComplete="off"
            aria-expanded={open && suggestions.length > 0}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={open && suggestions[active] ? `${optionId}-${active}` : undefined}
            placeholder={placeholder}
            value={query}
            disabled={disabled}
            onChange={(event) => {
              setQuery(event.target.value)
              setActive(0)
              setOpen(true)
            }}
            onFocus={() => setOpen(true)}
            // A blur that beats the click would close the list before the
            // option under the pointer ever fires.
            onBlur={() => window.setTimeout(() => setOpen(false), 120)}
            onKeyDown={onKeyDown}
          />
        )}
      </div>

      {open && !full ? (
        <ul className="tag-picker__list" id={listId} role="listbox" aria-label="Matching tags">
          {tags.isPending ? (
            <li className="tag-picker__status">Loading tags…</li>
          ) : tags.isError ? (
            <li className="tag-picker__status tag-picker__status--error" role="alert">
              {tags.error.message}
            </li>
          ) : suggestions.length === 0 ? (
            <li className="tag-picker__status">
              {query.trim() === '' ? 'No tags yet — type one to make it.' : 'Already added.'}
            </li>
          ) : (
            suggestions.map((suggestion, index) => (
              <li
                key={suggestion.kind === 'tag' ? suggestion.tag.id : `create-${suggestion.name}`}
                id={`${optionId}-${index}`}
                role="option"
                aria-selected={index === active}
                className={`tag-picker__option ${index === active ? 'tag-picker__option--active' : ''}`.trim()}
                onMouseEnter={() => setActive(index)}
                onMouseDown={(event) => {
                  // Keeps focus in the field so the blur timer never runs.
                  event.preventDefault()
                  apply(suggestion.kind === 'tag' ? suggestion.tag.name : suggestion.name)
                }}
              >
                <Icon name={suggestion.kind === 'create' ? 'plus' : 'tag'} size={13} />
                <span className="tag-picker__option-label">
                  {suggestion.kind === 'create' ? `Create “${suggestion.name}”` : suggestion.tag.name}
                </span>
                {suggestion.kind === 'tag' && suggestion.tag.note_count !== undefined ? (
                  <span className="tag-picker__option-count">{suggestion.tag.note_count}</span>
                ) : null}
              </li>
            ))
          )}
        </ul>
      ) : null}
    </div>
  )
}
