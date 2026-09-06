/**
 * The small components every screen reaches for.
 *
 * Kept together because each is a handful of lines and splitting them into
 * eleven files would mean eleven imports to render one dialog. Anything that
 * grows past a screenful moves out.
 */

import { useEffect, useId, useRef } from 'react'
import type { ButtonHTMLAttributes, HTMLAttributes, ReactNode } from 'react'
import { Icon } from './Icon'
import type { IconName } from './Icon'

// ---------------------------------------------------------------------------
// Button
// ---------------------------------------------------------------------------

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  size?: 'sm' | 'md' | 'lg'
  icon?: IconName
  iconOnly?: boolean
  loading?: boolean
}

export function Button({
  variant = 'secondary',
  size = 'md',
  icon,
  iconOnly = false,
  loading = false,
  children,
  className = '',
  disabled,
  ...rest
}: ButtonProps) {
  return (
    <button
      type="button"
      className={`btn btn--${variant} btn--${size} ${iconOnly ? 'btn--icon' : ''} ${className}`.trim()}
      disabled={disabled || loading}
      // A disabled button says nothing about *why*; the caller sets aria-label
      // or a title where the reason matters (a feature that is switched off).
      aria-busy={loading || undefined}
      {...rest}
    >
      {loading ? <Spinner size={size === 'sm' ? 12 : 14} /> : icon ? <Icon name={icon} size={size === 'sm' ? 15 : 17} /> : null}
      {!iconOnly && children ? <span>{children}</span> : null}
    </button>
  )
}

export function Spinner({ size = 16 }: { size?: number }) {
  return (
    <span
      className="spinner"
      style={{ width: size, height: size }}
      role="status"
      aria-label="Loading"
    />
  )
}

// ---------------------------------------------------------------------------
// Empty and loading states
// ---------------------------------------------------------------------------

export function EmptyState({
  icon,
  title,
  description,
  action,
}: {
  icon: IconName
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div className="empty-state">
      <span className="empty-state__icon" aria-hidden>
        <Icon name={icon} size={26} />
      </span>
      <p className="empty-state__title">{title}</p>
      {description ? <p className="empty-state__description">{description}</p> : null}
      {action ? <div className="empty-state__action">{action}</div> : null}
    </div>
  )
}

/**
 * A placeholder shaped like the thing that is loading.
 *
 * A spinner in the middle of the screen tells the user to wait; a skeleton
 * tells them what is arriving, and the layout does not jump when it does.
 */
export function Skeleton({
  width = '100%',
  height = 14,
  radius = 6,
  className = '',
}: {
  width?: string | number
  height?: string | number
  radius?: number
  className?: string
}) {
  return (
    <span
      className={`skeleton ${className}`.trim()}
      style={{ width, height, borderRadius: radius }}
      aria-hidden
    />
  )
}

// ---------------------------------------------------------------------------
// Dialog
// ---------------------------------------------------------------------------

export interface DialogProps {
  open: boolean
  onClose: () => void
  title: string
  description?: string
  children: ReactNode
  footer?: ReactNode
  width?: number
}

/**
 * A modal that behaves like one.
 *
 * Escape closes it, focus moves inside on open and returns to the trigger on
 * close, and Tab is trapped — the three things a div-with-a-shadow is missing
 * and the reason a keyboard user gets lost behind an overlay.
 */
export function Dialog({ open, onClose, title, description, children, footer, width = 480 }: DialogProps) {
  const panelRef = useRef<HTMLDivElement>(null)
  const previouslyFocused = useRef<HTMLElement | null>(null)
  const titleId = useId()
  const descriptionId = useId()

  useEffect(() => {
    if (!open) return undefined

    previouslyFocused.current = document.activeElement as HTMLElement | null

    // `offsetParent` is the tempting visibility test and the wrong one: it is
    // null for any position:fixed element, and null everywhere in an engine
    // without layout. Either way the trap would quietly include nothing and
    // stop trapping. `checkVisibility` answers the actual question where it
    // exists; otherwise an element is assumed focusable unless it says
    // otherwise, which fails open rather than silently disabling the trap.
    const isFocusable = (element: HTMLElement): boolean => {
      if (element.hasAttribute('hidden') || element.getAttribute('aria-hidden') === 'true') return false
      if (typeof element.checkVisibility === 'function') return element.checkVisibility()
      return true
    }

    const focusables = () =>
      Array.from(
        panelRef.current?.querySelectorAll<HTMLElement>(
          'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]),' +
            ' select:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ) ?? [],
      ).filter(isFocusable)

    /**
     * Where focus lands on open.
     *
     * Not simply the first focusable: that is the Close button in the header,
     * so the dialog opens with Enter wired to dismissing it. Focus goes to the
     * first control in the body — the field the user came here to fill in —
     * and only falls back to the panel when the body has none.
     */
    const initialFocus = (): HTMLElement | null => {
      const explicit = panelRef.current?.querySelector<HTMLElement>('[data-autofocus]')
      if (explicit && isFocusable(explicit)) return explicit

      const body = panelRef.current?.querySelector<HTMLElement>('.dialog__body')
      const inBody = focusables().find((element) => body?.contains(element))

      return inBody ?? panelRef.current
    }

    const timer = window.setTimeout(() => initialFocus()?.focus(), 0)

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
        return
      }
      if (event.key !== 'Tab') return

      const items = focusables()
      if (items.length === 0) return

      const first = items[0]
      const last = items[items.length - 1]
      const active = document.activeElement

      if (event.shiftKey && active === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && active === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      window.clearTimeout(timer)
      document.removeEventListener('keydown', onKeyDown, true)
      document.body.style.overflow = previousOverflow
      previouslyFocused.current?.focus?.()
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="dialog-backdrop" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div
        ref={panelRef}
        className="dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        style={{ maxWidth: width }}
        tabIndex={-1}
      >
        <header className="dialog__header">
          <h2 id={titleId} className="dialog__title">{title}</h2>
          <Button icon="close" iconOnly variant="ghost" size="sm" aria-label="Close" onClick={onClose} />
        </header>
        {description ? <p id={descriptionId} className="dialog__description">{description}</p> : null}
        <div className="dialog__body">{children}</div>
        {footer ? <footer className="dialog__footer">{footer}</footer> : null}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Misc
// ---------------------------------------------------------------------------

export function Badge({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'primary' | 'warning' | 'danger' }) {
  return <span className={`badge badge--${tone}`}>{children}</span>
}

export function Divider(props: HTMLAttributes<HTMLHRElement>) {
  return <hr className="divider" {...props} />
}

/**
 * A live region for status the user should hear but not be interrupted by —
 * "Saved", "Offline", "Syncing". `polite` on purpose: a save confirmation must
 * not cut across what a screen reader is currently reading out.
 */
export function LiveStatus({ children }: { children: ReactNode }) {
  return (
    <span className="sr-only" role="status" aria-live="polite">
      {children}
    </span>
  )
}
