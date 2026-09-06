/**
 * The floating list behind `/` and `@`.
 *
 * A pure renderer: it is given items, which one is highlighted and where the
 * caret is, and it draws them. It holds no editor, no registry and no keyboard
 * state, which is what makes both the grouping and the empty case testable.
 *
 * The caret keeps DOM focus the whole time — this is a listbox the editor
 * drives, not a menu that steals focus — so the highlighted row is published
 * through `aria-activedescendant` on the editor itself (see NoteEditor).
 */

import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react'

import { Icon } from '../../shared/ui/Icon'
import type { SuggestionMenuItem } from './extensions/suggestionBridge'
import './editor.css'

export interface SlashCommandMenuProps {
  /** Ordered by group; headings are inserted where the group changes. */
  items: SuggestionMenuItem[]
  activeIndex: number
  loading: boolean
  /** The caret, in viewport coordinates. */
  rect: DOMRect | null
  /** Prefix for option ids, so the editor can point at the active one. */
  idPrefix: string
  label: string
  emptyMessage: string
  onHover: (index: number) => void
  onSelect: (id: string) => void
}

const EDGE_GAP = 8

export function SlashCommandMenu({
  items,
  activeIndex,
  loading,
  rect,
  idPrefix,
  label,
  emptyMessage,
  onHover,
  onSelect,
}: SlashCommandMenuProps) {
  const panel = useRef<HTMLDivElement>(null)
  const [placement, setPlacement] = useState<{ top: number; left: number } | null>(null)

  useLayoutEffect(() => {
    const element = panel.current
    if (!element || !rect) {
      setPlacement(null)
      return
    }

    const { width, height } = element.getBoundingClientRect()
    const left = Math.max(
      EDGE_GAP,
      Math.min(rect.left, window.innerWidth - width - EDGE_GAP),
    )
    const below = rect.bottom + EDGE_GAP
    // Flip above the caret when the list would run off the bottom, rather than
    // letting the page scroll out from under the writer.
    const top =
      below + height > window.innerHeight - EDGE_GAP
        ? Math.max(EDGE_GAP, rect.top - height - EDGE_GAP)
        : below

    setPlacement({ top, left })
  }, [rect, items.length, loading])

  /**
   * Keep the highlighted row on screen.
   *
   * The panel is capped at a few hundred pixels and there are more blocks than
   * that fits, so arrowing past the fold would otherwise highlight a row the
   * writer cannot see — the keyboard path would be driving a list blind.
   * `block: 'nearest'` scrolls the panel only when the row is actually out of
   * view, and never moves the page behind it.
   */
  useEffect(() => {
    panel.current
      ?.querySelector<HTMLElement>('[data-active="true"]')
      ?.scrollIntoView({ block: 'nearest' })
  }, [activeIndex, items])

  const activeId = items[activeIndex] ? `${idPrefix}-option-${activeIndex}` : undefined

  // Consecutive runs of one group, so the heading can wrap the rows it names.
  // A bare `role="presentation"` heading is invisible to a screen reader, which
  // would leave "Heading 1" and "Table" in one undifferentiated list.
  const groups: { heading: string | null; entries: { item: SuggestionMenuItem; index: number }[] }[] =
    []
  items.forEach((item, index) => {
    const heading = item.group ?? null
    const last = groups[groups.length - 1]
    if (last && last.heading === heading) last.entries.push({ item, index })
    else groups.push({ heading, entries: [{ item, index }] })
  })

  const option = (item: SuggestionMenuItem, index: number) => (
    <button
      key={item.id}
      type="button"
      id={`${idPrefix}-option-${index}`}
      role="option"
      aria-selected={index === activeIndex}
      data-active={index === activeIndex}
      // Focus belongs to the caret for as long as this is open; a row that
      // could be tabbed to would take the selection out of the note.
      tabIndex={-1}
      className={`slash-menu__item ${index === activeIndex ? 'slash-menu__item--active' : ''}`}
      onMouseEnter={() => onHover(index)}
      // The caret must not leave the editor, so the press is taken on
      // mousedown and the default focus move is prevented.
      onMouseDown={(event) => {
        event.preventDefault()
        onSelect(item.id)
      }}
    >
      {item.icon ? (
        <span className="slash-menu__icon" aria-hidden>
          <Icon name={item.icon} size={16} />
        </span>
      ) : null}
      <span className="slash-menu__text">
        <span className="slash-menu__label">{item.label}</span>
        {item.hint ? <span className="slash-menu__hint">{item.hint}</span> : null}
      </span>
    </button>
  )

  return (
    <div
      ref={panel}
      id={idPrefix}
      className="slash-menu editor-popover"
      role="listbox"
      aria-label={label}
      aria-activedescendant={activeId}
      // Hidden rather than unmounted until it has been measured: it has to be
      // laid out to know its own height, and showing it at 0,0 for a frame
      // reads as a flicker in the corner of the screen.
      style={{
        top: placement?.top ?? 0,
        left: placement?.left ?? 0,
        visibility: placement ? 'visible' : 'hidden',
      }}
    >
      {items.length === 0 ? (
        <p className="slash-menu__empty">{loading ? 'Searching…' : emptyMessage}</p>
      ) : (
        groups.map((group) =>
          group.heading ? (
            <div
              key={`group-${group.entries[0].index}`}
              role="group"
              aria-label={group.heading}
            >
              {/* The name is on the group; repeating it as a text node would
                  have a screen reader read every heading twice. */}
              <p className="slash-menu__group-label" aria-hidden="true">
                {group.heading}
              </p>
              {group.entries.map(({ item, index }) => option(item, index))}
            </div>
          ) : (
            <Fragment key={`group-${group.entries[0].index}`}>
              {group.entries.map(({ item, index }) => option(item, index))}
            </Fragment>
          ),
        )
      )}
    </div>
  )
}
