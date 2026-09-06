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

import { Fragment, useLayoutEffect, useRef, useState } from 'react'

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

  const activeId = items[activeIndex] ? `${idPrefix}-option-${activeIndex}` : undefined
  let renderedGroup: string | undefined

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
        items.map((item, index) => {
          const heading = item.group && item.group !== renderedGroup ? item.group : null
          if (heading) renderedGroup = item.group

          return (
            <Fragment key={item.id}>
              {heading ? (
                <p className="slash-menu__group-label" role="presentation">
                  {heading}
                </p>
              ) : null}
              <button
                type="button"
                id={`${idPrefix}-option-${index}`}
                role="option"
                aria-selected={index === activeIndex}
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
            </Fragment>
          )
        })
      )}
    </div>
  )
}
