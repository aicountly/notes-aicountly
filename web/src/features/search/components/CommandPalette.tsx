/**
 * The command palette.
 *
 * Actions, not results. Cmd/Ctrl+K is muscle memory for "do the thing I am
 * about to name", and mixing note hits into it turns a list of verbs into a
 * list of nouns you have to read past — search has its own dialog, and one of
 * these commands opens it.
 *
 * Every entry here runs. A capability this deployment does not have does not
 * appear at all, because a palette that lists something and then answers 503
 * teaches people not to trust the palette.
 *
 * "Go to notebook…" and "Go to tag…" push the palette into a picker rather than
 * opening a second dialog: the input stays where the hands are, Backspace on an
 * empty field comes back, and the list under it is the only thing that changed.
 */

import { Fragment, useEffect, useId, useMemo, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'

import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { Button, Dialog, EmptyState, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { useTheme } from '../../../shared/ui/ThemeProvider'
import { ApiError, api } from '../../../shared/api/client'
import { useFeature } from '../../../app/AppConfigProvider'
import { useCreateNote } from '../../notes/hooks/useNotes'
import type { NoteType, PulseAnswer } from '../../../shared/api/types'
import { SearchDialog } from './SearchDialog'
import { useNotebookOptions, useTagOptions } from '../hooks/useSearch'
import '../search.css'

/**
 * How well `query` matches `text`, or null if it does not.
 *
 * A subsequence match, so "nmn" finds "New meeting note", with letters that
 * follow each other and letters that start a word scoring higher — otherwise
 * every long label matches everything and the ordering is noise.
 */
export function fuzzyScore(query: string, text: string): number | null {
  const needle = query.trim().toLowerCase()
  if (needle === '') return 0

  const haystack = text.toLowerCase()
  let score = 0
  let cursor = 0
  let previous = -2

  for (const char of needle) {
    if (char === ' ') continue

    const at = haystack.indexOf(char, cursor)
    if (at === -1) return null

    score += at === previous + 1 ? 3 : 1
    if (at === 0 || haystack[at - 1] === ' ' || haystack[at - 1] === '-') score += 2

    previous = at
    cursor = at + 1
  }

  return haystack.includes(needle) ? score + 6 : score
}

/**
 * The modifier this keyboard has, written the way it is printed on the key.
 *
 * The rest of the app (the top bar, the shortcut reference) already does this;
 * a palette that says "Ctrl N" on a Mac while the top bar next to it says "⌘ K"
 * makes the reader wonder which of the two is out of date.
 */
function modifierLabel(): string {
  const platform = typeof navigator === 'undefined' ? '' : (navigator.platform ?? '')
  if (platform !== '') return platform.toLowerCase().includes('mac') ? '⌘' : 'Ctrl'

  return /mac/i.test(navigator?.userAgent ?? '') ? '⌘' : 'Ctrl'
}

type PaletteMode = 'root' | 'notebook' | 'tag' | 'pulse'

interface PaletteItem {
  key: string
  label: string
  group: string
  icon: IconName
  /** Shown at the end of the row: a shortcut, or where the command leads. */
  hint?: string
  /** Extra text the filter matches on, so "dark" finds "Switch theme". */
  keywords?: string
  run: () => void
}

const GROUP_ORDER = ['Create', 'Go to', 'View', 'Assistant', 'Notebooks', 'Tags'] as const

function groupRank(group: string): number {
  const at = (GROUP_ORDER as readonly string[]).indexOf(group)
  return at === -1 ? GROUP_ORDER.length : at
}

/**
 * Filter, then order — by group, not by row.
 *
 * A pure score ordering interleaves groups and repeats their headings, and a
 * pure group ordering buries an exact match under a group that merely happens
 * to come first. So a group is ranked by its best row, and rows are ranked
 * inside it: typing "remind" puts Reminders at the top without scattering
 * Create's three commands through the list.
 */
function filterItems(items: PaletteItem[], query: string): PaletteItem[] {
  if (query.trim() === '') {
    return [...items].sort((a, b) => groupRank(a.group) - groupRank(b.group))
  }

  const scored = items
    .map((item) => ({ item, score: fuzzyScore(query, `${item.label} ${item.keywords ?? ''}`) }))
    .filter((entry): entry is { item: PaletteItem; score: number } => entry.score !== null)

  const best = new Map<string, number>()
  for (const entry of scored) {
    best.set(entry.item.group, Math.max(best.get(entry.item.group) ?? 0, entry.score))
  }

  return scored
    .sort((a, b) => {
      const byGroup = (best.get(b.item.group) ?? 0) - (best.get(a.item.group) ?? 0)
      if (byGroup !== 0) return byGroup
      if (a.item.group !== b.item.group) return groupRank(a.item.group) - groupRank(b.item.group)
      return b.score - a.score
    })
    .map((entry) => entry.item)
}

const MODE_TITLE: Record<PaletteMode, string> = {
  root: 'Commands',
  notebook: 'Go to notebook',
  tag: 'Go to tag',
  pulse: 'Ask Pulse',
}

const MODE_PLACEHOLDER: Record<PaletteMode, string> = {
  root: 'Type a command…',
  notebook: 'Find a notebook…',
  tag: 'Find a tag…',
  pulse: 'Ask a question about your notes…',
}

function describeError(error: unknown): string {
  if (error instanceof ApiError) return error.message
  return 'That did not work. Please try again.'
}

export interface CommandPaletteProps {
  open: boolean
  onClose: () => void
}

export function CommandPalette({ open, onClose }: CommandPaletteProps) {
  const navigate = useNavigate()
  const create = useCreateNote()
  const { resolved, setPreference } = useTheme()
  const aiEnabled = useFeature('ai')

  const [mode, setMode] = useState<PaletteMode>('root')
  const [query, setQuery] = useState('')
  const [selected, setSelected] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [searchOpen, setSearchOpen] = useState(false)
  const [question, setQuestion] = useState('')

  const listId = useId()
  const inputId = useId()
  const optionRefs = useRef<Array<HTMLLIElement | null>>([])

  const notebooks = useNotebookOptions(open && mode === 'notebook')
  const tags = useTagOptions(open && mode === 'tag')

  const ask = useMutation<PulseAnswer, ApiError, string>({
    mutationFn: (asked) => api.post<PulseAnswer>('/pulse/notes/ask', { question: asked }),
  })

  useEffect(() => {
    if (!open) return
    setMode('root')
    setQuery('')
    setSelected(0)
    setError(null)
    setQuestion('')
    ask.reset()
    // `ask` is a fresh object every render; depending on it would reset the
    // mutation on each keystroke rather than once per opening.
  }, [open])

  const startNote = async (noteType: NoteType, whenItFails: string) => {
    setError(null)
    try {
      const note = await create.mutateAsync({ note_type: noteType, source: 'command-palette' })
      onClose()
      navigate(`/notes/${note.id}`)
    } catch (reason) {
      setError(`${whenItFails}: ${describeError(reason)}`)
    }
  }

  const go = (path: string) => {
    onClose()
    navigate(path)
  }

  const commands = useMemo<PaletteItem[]>(() => {
    const modifier = modifierLabel()
    const items: PaletteItem[] = [
      {
        key: 'new-note',
        label: 'New note',
        group: 'Create',
        icon: 'plus',
        hint: `${modifier} N`,
        keywords: 'create write document blank',
        run: () => void startNote('document', 'That note could not be created'),
      },
      {
        key: 'new-checklist',
        label: 'New checklist',
        group: 'Create',
        icon: 'checklist',
        keywords: 'create todo task list',
        run: () => void startNote('checklist', 'That checklist could not be created'),
      },
      {
        key: 'new-meeting',
        label: 'New meeting note',
        group: 'Create',
        icon: 'meeting',
        keywords: 'create minutes agenda',
        run: () => void startNote('meeting', 'That meeting note could not be created'),
      },
      {
        key: 'search',
        label: 'Search notes',
        group: 'Go to',
        icon: 'search',
        hint: `${modifier} Shift F`,
        keywords: 'find query text',
        run: () => {
          onClose()
          setSearchOpen(true)
        },
      },
      {
        key: 'notebook',
        label: 'Go to notebook…',
        group: 'Go to',
        icon: 'notebook',
        keywords: 'open folder',
        run: () => {
          setMode('notebook')
          setQuery('')
          setSelected(0)
        },
      },
      {
        key: 'tag',
        label: 'Go to tag…',
        group: 'Go to',
        icon: 'tag',
        keywords: 'open label',
        run: () => {
          setMode('tag')
          setQuery('')
          setSelected(0)
        },
      },
      {
        key: 'reminders',
        label: 'Reminders',
        group: 'Go to',
        icon: 'bell',
        keywords: 'due alerts snooze',
        run: () => go('/reminders'),
      },
      {
        key: 'templates',
        label: 'Templates',
        group: 'Go to',
        icon: 'template',
        keywords: 'starter boilerplate',
        run: () => go('/templates'),
      },
      {
        key: 'theme',
        label: resolved === 'dark' ? 'Switch to the light theme' : 'Switch to the dark theme',
        group: 'View',
        icon: resolved === 'dark' ? 'sun' : 'moon',
        keywords: 'theme dark light appearance colour color',
        run: () => {
          setPreference(resolved === 'dark' ? 'light' : 'dark')
          onClose()
        },
      },
    ]

    // Pulse exists only where the deployment says it does.
    if (aiEnabled) {
      items.push({
        key: 'pulse',
        label: 'Ask Pulse',
        group: 'Assistant',
        icon: 'pulse',
        keywords: 'ai question answer summarise',
        run: () => {
          setMode('pulse')
          setQuery('')
          setSelected(0)
        },
      })
    }

    // Rebuilt when the theme or the deployment's flags change; the handlers it
    // closes over are stable for as long as the palette is mounted.
    return items
  }, [aiEnabled, resolved, setPreference, onClose])

  const items = useMemo<PaletteItem[]>(() => {
    if (mode === 'root') return filterItems(commands, query)

    if (mode === 'notebook') {
      return filterItems(
        (notebooks.data ?? []).map((notebook) => ({
          key: `notebook:${notebook.id}`,
          label: notebook.name,
          group: 'Notebooks',
          icon: 'notebook' as IconName,
          run: () => go(`/notebooks/${notebook.id}`),
        })),
        query,
      )
    }

    if (mode === 'tag') {
      return filterItems(
        (tags.data ?? []).map((tag) => ({
          key: `tag:${tag.id}`,
          label: tag.name,
          group: 'Tags',
          icon: 'tag' as IconName,
          keywords: tag.slug,
          run: () => go(`/tags/${tag.slug}`),
        })),
        query,
      )
    }

    return []
  }, [mode, query, commands, notebooks.data, tags.data])

  const index = items.length === 0 ? -1 : Math.min(selected, items.length - 1)

  useEffect(() => {
    if (index >= 0) optionRefs.current[index]?.scrollIntoView({ block: 'nearest' })
  }, [index, items.length])

  const move = (delta: number) => {
    if (items.length === 0) return
    setSelected((current) => {
      const from = Math.min(current, items.length - 1)
      return (from + delta + items.length) % items.length
    })
  }

  const back = () => {
    setMode('root')
    setQuery('')
    setSelected(0)
    ask.reset()
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
    if (event.key === 'Backspace' && query === '' && mode !== 'root') {
      event.preventDefault()
      back()
      return
    }
    if (event.key !== 'Enter') return

    if (mode === 'pulse') {
      event.preventDefault()
      if (query.trim() === '' || ask.isPending) return
      setQuestion(query.trim())
      ask.mutate(query.trim())
      return
    }

    const item = index >= 0 ? items[index] : undefined
    if (item) {
      event.preventDefault()
      item.run()
    }
  }

  const lookupError: ApiError | null =
    mode === 'notebook' ? notebooks.error : mode === 'tag' ? tags.error : null
  const lookupLoading =
    (mode === 'notebook' && notebooks.isLoading) || (mode === 'tag' && tags.isLoading)
  const busy = create.isPending

  return (
    <>
      <Dialog
        open={open}
        onClose={onClose}
        title={MODE_TITLE[mode]}
        width={620}
        footer={
          <p className="search__keys">
            <kbd>↑</kbd>
            <kbd>↓</kbd> to move <kbd>↵</kbd> to run <kbd>Esc</kbd> to close
          </p>
        }
      >
        <div className="palette">
          <div className="search__field">
            <label className="sr-only" htmlFor={inputId}>
              {MODE_PLACEHOLDER[mode]}
            </label>
            {mode === 'root' ? (
              <Icon name="search" size={17} />
            ) : (
              <Button
                icon="chevron-left"
                iconOnly
                variant="ghost"
                size="sm"
                aria-label="Back to commands"
                onClick={back}
              />
            )}
            <input
              id={inputId}
              data-autofocus
              className="search__input"
              type="text"
              role={mode === 'pulse' ? undefined : 'combobox'}
              autoComplete="off"
              placeholder={MODE_PLACEHOLDER[mode]}
              value={query}
              aria-expanded={mode === 'pulse' ? undefined : items.length > 0}
              aria-controls={mode === 'pulse' ? undefined : listId}
              aria-autocomplete={mode === 'pulse' ? undefined : 'list'}
              aria-activedescendant={index >= 0 ? `${listId}-option-${index}` : undefined}
              onChange={(event) => setQuery(event.target.value)}
              onKeyDown={onKeyDown}
            />
            {mode === 'pulse' ? (
              <Button
                variant="primary"
                size="sm"
                icon="pulse"
                loading={ask.isPending}
                disabled={query.trim() === ''}
                title={query.trim() === '' ? 'Type a question first' : undefined}
                onClick={() => {
                  setQuestion(query.trim())
                  ask.mutate(query.trim())
                }}
              >
                Ask
              </Button>
            ) : null}
          </div>

          {busy ? (
            <p className="palette__status" role="status">
              Creating your note…
            </p>
          ) : null}

          <div className="palette__body">
            {mode === 'pulse' ? (
              <PulseAnswerPanel
                question={question}
                pending={ask.isPending}
                error={ask.error}
                answer={ask.data}
                onOpenNote={(noteId) => go(`/notes/${noteId}`)}
              />
            ) : lookupLoading ? (
              <PaletteSkeleton />
            ) : lookupError ? (
              <PaletteLookupError
                error={lookupError}
                onBack={back}
                onRetry={() => void (mode === 'notebook' ? notebooks : tags).refetch()}
              />
            ) : items.length === 0 ? (
              <EmptyState
                icon="search"
                title={query.trim() === '' ? 'Nothing here yet' : `No command matches “${query}”`}
                description={
                  mode === 'root'
                    ? 'Try a word from what you want to do — “note”, “tag”, “theme”.'
                    : 'Nothing with that name.'
                }
                action={
                  mode === 'root' ? undefined : (
                    <Button variant="secondary" icon="chevron-left" onClick={back}>
                      Back to commands
                    </Button>
                  )
                }
              />
            ) : (
              <ul className="palette__list" role="listbox" id={listId} aria-label={MODE_TITLE[mode]}>
                {items.map((item, position) => {
                  const showGroup = position === 0 || items[position - 1].group !== item.group

                  return (
                    <Fragment key={item.key}>
                      {showGroup ? (
                        <li role="presentation" className="search__group">
                          {item.group}
                        </li>
                      ) : null}
                      <li
                        ref={(element) => {
                          optionRefs.current[position] = element
                        }}
                        id={`${listId}-option-${position}`}
                        role="option"
                        aria-selected={position === index}
                        className={`palette__item ${position === index ? 'palette__item--active' : ''}`}
                        onMouseMove={() => setSelected(position)}
                        // A row is not focusable — it is named by
                        // aria-activedescendant — so pressing the mouse on one
                        // would otherwise pull focus out of the field. That is
                        // invisible for a command that closes the palette and
                        // very visible for one that does not: clicking "Go to
                        // notebook…" would leave the picker with nowhere to type.
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => item.run()}
                      >
                        <span className="palette__item-icon">
                          <Icon name={item.icon} size={16} />
                        </span>
                        <span className="palette__item-label">{item.label}</span>
                        {item.hint ? <kbd className="palette__item-hint">{item.hint}</kbd> : null}
                      </li>
                    </Fragment>
                  )
                })}
              </ul>
            )}

            {error ? (
              <p className="search__error" role="alert">
                {error}
              </p>
            ) : null}
          </div>

          {/* An answer that arrives in a panel nobody is looking at is an
              answer nobody hears; Pulse's states are announced as they land. */}
          <LiveStatus>
            {busy
              ? 'Creating your note'
              : mode !== 'pulse'
                ? ''
                : ask.isPending
                  ? 'Pulse is answering'
                  : ask.error
                    ? `Pulse could not answer. ${ask.error.message}`
                    : ask.data
                      ? `Pulse answered, citing ${ask.data.citations.length} ${
                          ask.data.citations.length === 1 ? 'note' : 'notes'
                        }.`
                      : ''}
          </LiveStatus>
        </div>
      </Dialog>

      {/* Opened by the "Search notes" command. The palette closes first, so the
          two dialogs are never on screen together. */}
      <SearchDialog open={searchOpen} onClose={() => setSearchOpen(false)} />
    </>
  )
}

// ---------------------------------------------------------------------------

function PulseAnswerPanel({
  question,
  pending,
  error,
  answer,
  onOpenNote,
}: {
  question: string
  pending: boolean
  error: ApiError | null
  answer: PulseAnswer | undefined
  onOpenNote: (noteId: string) => void
}) {
  if (pending) {
    return (
      <div className="palette__pulse" aria-busy>
        <p className="palette__pulse-question">{question}</p>
        <Skeleton width="92%" height={12} />
        <Skeleton width="84%" height={12} />
        <Skeleton width="60%" height={12} />
      </div>
    )
  }

  if (error) {
    return (
      <EmptyState
        icon={error.isOffline ? 'cloud-off' : 'alert'}
        title={error.isOffline ? 'Pulse needs a connection' : 'Pulse could not answer'}
        description={error.message}
      />
    )
  }

  if (!answer) {
    return (
      <EmptyState
        icon="pulse"
        title="Ask about your notes"
        description="Pulse answers from the notes you can read, and shows which ones it used."
      />
    )
  }

  return (
    <div className="palette__pulse">
      <p className="palette__pulse-question">{question}</p>
      {answer.answer.split(/\n{2,}/).map((paragraph, position) => (
        <p key={position} className="palette__pulse-answer">
          {paragraph}
        </p>
      ))}

      {!answer.grounded ? (
        <p className="search__notice" role="status">
          <Icon name="info" size={15} />
          Pulse answered without finding this in your notes.
        </p>
      ) : null}

      {answer.citations.length > 0 ? (
        <div className="palette__citations">
          <h3 className="search__recent-title">From your notes</h3>
          <ul className="palette__citation-list">
            {answer.citations.map((citation) => (
              <li key={`${citation.note_id}:${citation.block_id ?? ''}`}>
                <button
                  type="button"
                  className="palette__citation"
                  onClick={() => onOpenNote(citation.note_id)}
                >
                  <span className="palette__citation-title">{citation.title ?? 'Untitled note'}</span>
                  <span className="palette__citation-snippet">{citation.snippet}</span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </div>
  )
}

function PaletteLookupError({
  error,
  onBack,
  onRetry,
}: {
  error: ApiError
  onBack: () => void
  onRetry: () => void
}) {
  return (
    <EmptyState
      icon={error.isOffline ? 'cloud-off' : 'alert'}
      title={error.isOffline ? 'Unavailable offline' : 'That list could not be loaded'}
      description={error.message}
      action={
        <div className="palette__error-actions">
          {/* A dead end with only a way out is half an error state: the usual
              cause is a connection that has since come back. */}
          <Button variant="secondary" icon="refresh" onClick={onRetry}>
            Try again
          </Button>
          <Button variant="ghost" icon="chevron-left" onClick={onBack}>
            Back to commands
          </Button>
        </div>
      }
    />
  )
}

function PaletteSkeleton() {
  return (
    <div className="palette__skeleton" aria-hidden>
      {[0, 1, 2, 3, 4].map((row) => (
        <div key={row} className="palette__skeleton-row">
          <Skeleton width={18} height={18} radius={5} />
          <Skeleton width={`${60 - row * 6}%`} height={12} />
        </div>
      ))}
    </div>
  )
}
