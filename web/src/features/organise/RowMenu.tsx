/**
 * The "…" menu on a sidebar row.
 *
 * One implementation for notebooks, smart folders and tags, because three
 * copies of a focus trap is three places for the keyboard to quietly stop
 * working. It behaves like a menu rather than looking like one: arrow keys
 * move between items, Escape closes it and puts focus back on the trigger, a
 * pointer press anywhere else dismisses it, and it flips above the row when
 * there is no room below.
 *
 * An action the caller may not perform is shown disabled *with the reason*,
 * never hidden and never left to fail on click.
 */

import { Fragment, useCallback, useEffect, useImperativeHandle, useLayoutEffect, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent, Ref } from 'react'

import { Icon } from '../../shared/ui/Icon'
import type { IconName } from '../../shared/ui/Icon'
import './organise.css'

export interface RowMenuItem {
  key: string
  label: string
  icon: IconName
  danger?: boolean
  /** Renders a rule above this item, for the destructive tail of a menu. */
  separated?: boolean
  /** Present when the item cannot be used; the text is shown and announced. */
  disabledReason?: string
  onSelect: () => void
}

export interface RowMenuHandle {
  /** Opens the menu — how a right-click on the row reaches it. */
  open: () => void
}

export interface RowMenuProps {
  /** Names the trigger and the popup, e.g. "Actions for Projects". */
  label: string
  items: RowMenuItem[]
  /** Set while an action is in flight, so a second click cannot race the first. */
  busy?: boolean
  ref?: Ref<RowMenuHandle>
}

/** Roughly the tallest this menu gets; below that it opens upwards. */
const POPUP_HEIGHT = 260

export function RowMenu({ label, items, busy = false, ref }: RowMenuProps) {
  const [open, setOpen] = useState(false)
  const [above, setAbove] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const popupRef = useRef<HTMLDivElement>(null)

  useImperativeHandle(ref, () => ({ open: () => setOpen(true) }), [])

  const close = useCallback(() => {
    setOpen(false)
    triggerRef.current?.focus()
  }, [])

  // Capture phase, so pressing another row's trigger closes this menu before
  // opening that one rather than leaving two open at once.
  useEffect(() => {
    if (!open) return undefined

    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('pointerdown', onPointerDown, true)
    return () => document.removeEventListener('pointerdown', onPointerDown, true)
  }, [open])

  // A menu that renders past the end of a scrolling sidebar is a menu the
  // user cannot reach.
  useLayoutEffect(() => {
    if (!open) return
    const rect = triggerRef.current?.getBoundingClientRect()
    if (rect) setAbove(window.innerHeight - rect.bottom < POPUP_HEIGHT && rect.top > POPUP_HEIGHT)
  }, [open])

  useEffect(() => {
    if (open) popupRef.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
  }, [open])

  const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.stopPropagation()
      close()
      return
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return

    const entries = Array.from(popupRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])
    if (entries.length === 0) return

    event.preventDefault()
    const index = entries.indexOf(document.activeElement as HTMLElement)
    const next = event.key === 'ArrowDown' ? index + 1 : index - 1
    entries[(next + entries.length) % entries.length].focus()
  }

  return (
    <div
      className="org-menu"
      ref={containerRef}
      // Tabbing out of a menu leaves it hanging over the row behind it, which
      // is how a keyboard user ends up with two menus on screen. A pointer
      // press elsewhere is handled above; this is the same thing for focus.
      onBlur={(event) => {
        if (!containerRef.current?.contains(event.relatedTarget)) setOpen(false)
      }}
    >
      <button
        ref={triggerRef}
        type="button"
        className="org-menu__trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={label}
        onClick={() => setOpen((current) => !current)}
      >
        <Icon name="more" size={16} />
      </button>

      {open ? (
        <div
          ref={popupRef}
          className={`org-menu__popup ${above ? 'org-menu__popup--above' : ''}`.trim()}
          role="menu"
          aria-label={label}
          onKeyDown={onKeyDown}
        >
          {items.map((item) => (
            // A menu's children are menu items and separators; a wrapper
            // element between them is a wrapper a screen reader reads as
            // "group of one".
            <Fragment key={item.key}>
              {item.separated ? <hr className="org-menu__separator" /> : null}
              <button
                type="button"
                role="menuitem"
                className={`org-menu__item ${item.danger ? 'org-menu__item--danger' : ''}`.trim()}
                // aria-disabled rather than `disabled`: a disabled button drops
                // out of the tab order, so the reason it is unavailable becomes
                // unreachable for exactly the user who most needs to hear it.
                aria-disabled={item.disabledReason !== undefined || busy}
                onClick={() => {
                  if (item.disabledReason !== undefined || busy) return
                  // Closed *before* the action runs, so focus is on the trigger
                  // when a dialog opens over it — which is where the dialog
                  // then puts it back on close.
                  close()
                  item.onSelect()
                }}
              >
                <Icon name={item.icon} size={15} />
                <span className="org-menu__label">{item.label}</span>
                {item.disabledReason !== undefined ? (
                  <span className="org-menu__reason">{item.disabledReason}</span>
                ) : null}
              </button>
            </Fragment>
          ))}
        </div>
      ) : null}
    </div>
  )
}
