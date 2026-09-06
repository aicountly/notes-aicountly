/**
 * "New note from template".
 *
 * The dialog that stands between wanting to write something and having a page
 * to write it on, so it is built to be crossed quickly: it opens with the
 * search field focused, arrow keys move, Enter starts the note, and Escape
 * leaves. A pointer is never required.
 *
 * It is grouped exactly like the Templates page — built in, then the company's,
 * then yours — because two orderings of the same list is how people lose track
 * of which template they use.
 *
 * A template whose note type this deployment has switched off is left out
 * rather than offered and refused on click, and the list says how many were
 * left out so the absence is explained rather than mysterious.
 */

import { Fragment, useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useNavigate } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import { Button, Dialog, EmptyState, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { useFeature } from '../../../app/AppConfigProvider'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { iconOrDefault } from '../../organise/appearance'
import { useDefaultNotebook } from '../../settings/preferences'
import type { Note, NoteTemplate } from '../../../shared/api/types'
import {
  NOTE_TYPE_LABELS,
  groupTemplates,
  renderTitlePreview,
  useCreateNoteFromTemplate,
  useTemplates,
} from '../hooks/useTemplates'
import '../templates.css'

/**
 * Whether a template answers what was typed.
 *
 * Plain contains, over the words a person would search by — the name, what it
 * is for, its tags and its type. Deliberately not fuzzy: the groups have to
 * stay in scope order, and a score-ordered list cannot.
 */
export function matchesTemplate(template: NoteTemplate, query: string): boolean {
  const needle = query.trim().toLowerCase()
  if (needle === '') return true

  const haystack = [
    template.name,
    template.description ?? '',
    template.default_tags.join(' '),
    NOTE_TYPE_LABELS[template.note_type],
  ]
    .join(' ')
    .toLowerCase()

  return haystack.includes(needle)
}

export interface TemplatePickerProps {
  open: boolean
  onClose: () => void
  /**
   * Where the new note should be filed. Omitted or null means the notebook the
   * user chose as their default in Settings, which is where that preference is
   * read.
   */
  notebookId?: string | null
  /** The created note, before the picker navigates to it. */
  onCreated?: (note: Note) => void
}

export function TemplatePicker({ open, onClose, notebookId = null, onCreated }: TemplatePickerProps) {
  const navigate = useNavigate()
  const templates = useTemplates()
  const start = useCreateNoteFromTemplate()
  const canvasEnabled = useFeature('canvas')
  const [defaultNotebook] = useDefaultNotebook()

  const [query, setQuery] = useState('')
  const [selected, setSelected] = useState(0)
  const inputId = useId()
  const listId = useId()
  const optionRefs = useRef<Array<HTMLLIElement | null>>([])

  useEffect(() => {
    if (!open) return
    setQuery('')
    setSelected(0)
    start.reset()
    // `start` is a new object every render; depending on it would reset the
    // mutation on each keystroke instead of once per opening.
  }, [open])

  const all = templates.data ?? []
  // A canvas template on a deployment without canvas cannot make a note, so it
  // is not offered. The page is where it is listed, with the reason on it.
  const usable = all.filter((template) => template.note_type !== 'canvas' || canvasEnabled)
  const hiddenCount = all.length - usable.length

  // A handful of rows filtered by a substring: memoising this would cost more
  // than it saves, and would need the list's identity to be stable to work.
  const groups = groupTemplates(usable.filter((template) => matchesTemplate(template, query)))

  const flat = groups.flatMap((group) => group.templates)
  const index = flat.length === 0 ? -1 : Math.min(selected, flat.length - 1)
  // The listbox is one flat sequence of options running across the group
  // headings, so each row needs to know where it sits in it — that is what the
  // arrow keys and aria-activedescendant address.
  const positions = new Map(flat.map((template, at) => [template.id, at]))

  useEffect(() => {
    if (index >= 0) optionRefs.current[index]?.scrollIntoView({ block: 'nearest' })
  }, [index, flat.length])

  const move = (delta: number) => {
    if (flat.length === 0) return
    setSelected((current) => {
      const from = Math.min(current, flat.length - 1)
      return (from + delta + flat.length) % flat.length
    })
  }

  const use = (template: NoteTemplate) => {
    if (start.isPending) return

    start
      .mutateAsync({ templateId: template.id, notebook_id: notebookId ?? defaultNotebook })
      .then((note) => {
        onCreated?.(note)
        onClose()
        navigate(`/notes/${note.id}`)
      })
      // The failure is on screen as `start.error`; no note was created, so
      // there is nowhere to navigate to.
      .catch(() => undefined)
  }

  const onKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      move(1)
      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      move(-1)
      return
    }
    if (event.key === 'Enter' && index >= 0) {
      event.preventDefault()
      use(flat[index])
    }
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="New note from template"
      width={640}
      footer={
        <p className="tpl-keys">
          <kbd>↑</kbd>
          <kbd>↓</kbd> to move <kbd>↵</kbd> to start the note <kbd>Esc</kbd> to close
        </p>
      }
    >
      <div className="tpl-picker">
        <div className="tpl-picker__field">
          <label className="sr-only" htmlFor={inputId}>
            Find a template
          </label>
          <Icon name="search" size={17} />
          <input
            id={inputId}
            data-autofocus
            className="tpl-picker__input"
            type="text"
            role="combobox"
            autoComplete="off"
            placeholder="Find a template…"
            value={query}
            disabled={start.isPending}
            aria-expanded={flat.length > 0}
            // Only while the listbox is actually in the document: a reference
            // to an id that is not there is a reference a screen reader
            // follows to nothing.
            aria-controls={flat.length > 0 ? listId : undefined}
            aria-autocomplete="list"
            aria-activedescendant={index >= 0 ? `${listId}-option-${index}` : undefined}
            onChange={(event) => {
              setQuery(event.target.value)
              setSelected(0)
            }}
            onKeyDown={onKeyDown}
          />
        </div>

        {start.error ? <ErrorNotice error={start.error} /> : null}

        <div className="tpl-picker__body">
          {templates.isPending ? (
            <PickerSkeleton />
          ) : templates.isError ? (
            <ErrorNotice error={templates.error} onRetry={() => void templates.refetch()} />
          ) : flat.length === 0 ? (
            <EmptyState
              icon="template"
              title={
                query.trim() !== ''
                  ? `No template matches “${query.trim()}”`
                  : hiddenCount > 0
                    ? 'No template can be used here'
                    : 'No templates yet'
              }
              description={
                query.trim() !== ''
                  ? 'Try a word from the name, or clear the search to see them all.'
                  : hiddenCount > 0
                    ? // "No templates yet" would be untrue: there are some, and
                      // the reason none is offered is on the line below.
                      'Every template this account can see needs a note type this workspace has switched off.'
                    : 'Templates you or your company make appear here, alongside the built-in ones.'
              }
              action={
                query.trim() === '' ? undefined : (
                  <Button icon="close" onClick={() => setQuery('')}>
                    Clear search
                  </Button>
                )
              }
            />
          ) : (
            <ul className="tpl-picker__list" role="listbox" id={listId} aria-label="Templates">
              {groups.map((group) => (
                <Fragment key={group.scope}>
                  <li role="presentation" className="tpl-picker__group">
                    {group.title}
                  </li>
                  {group.templates.map((template) => {
                    const at = positions.get(template.id) ?? 0
                    const title = renderTitlePreview(template.title_template)

                    return (
                      <li
                        key={template.id}
                        ref={(element) => {
                          optionRefs.current[at] = element
                        }}
                        id={`${listId}-option-${at}`}
                        role="option"
                        aria-selected={at === index}
                        className={`tpl-picker__item ${at === index ? 'tpl-picker__item--active' : ''}`.trim()}
                        onMouseMove={() => setSelected(at)}
                        onClick={() => use(template)}
                      >
                        <span className="tpl-picker__icon">
                          <Icon name={iconOrDefault(template.icon, 'template')} size={16} />
                        </span>
                        <span className="tpl-picker__text">
                          <span className="tpl-picker__name">{template.name}</span>
                          <span className="tpl-picker__meta">
                            {template.description ??
                              (title !== ''
                                ? `Starts a note called “${title}”`
                                : NOTE_TYPE_LABELS[template.note_type])}
                          </span>
                        </span>
                        <span className="tpl-picker__type">{NOTE_TYPE_LABELS[template.note_type]}</span>
                      </li>
                    )
                  })}
                </Fragment>
              ))}
            </ul>
          )}

          {hiddenCount > 0 ? (
            <p className="org-hint">
              {hiddenCount === 1 ? '1 template is' : `${hiddenCount} templates are`} not listed:
              canvas notes are switched off for this workspace.
            </p>
          ) : null}
        </div>

        <LiveStatus>{start.isPending ? 'Starting your note' : ''}</LiveStatus>
      </div>
    </Dialog>
  )
}

/** Shaped like the rows it is standing in for. */
function PickerSkeleton() {
  return (
    <div className="tpl-picker__list" aria-busy>
      {[0, 1, 2, 3].map((row) => (
        <div className="tpl-picker__item tpl-picker__item--loading" key={row}>
          <Skeleton width={26} height={26} radius={6} />
          <span className="tpl-picker__text">
            <Skeleton width="45%" height={13} />
            <Skeleton width="70%" height={11} />
          </span>
        </div>
      ))}
    </div>
  )
}
