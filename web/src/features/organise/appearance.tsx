/**
 * Choosing a colour and an icon.
 *
 * Notebooks and smart folders style themselves the same way, from the same two
 * short lists, so the pickers live together. Two constraints shape them:
 *
 *   - **A colour is a palette token, never a colour.** The server stores
 *     `"sage"`, the theme resolves it, and light and dark stay coherent. That
 *     is also why nothing here can put a stored string into a `style`.
 *   - **An icon is a name from our own set.** The column is free text, so a
 *     value saved by another client — or an emoji — may come back; it is
 *     rendered as the section's default rather than trusted, because an emoji
 *     cannot take a colour and looks different on every platform.
 */

import { useId } from 'react'

import { Icon } from '../../shared/ui/Icon'
import type { IconName } from '../../shared/ui/Icon'
import './organise.css'

/** The nine note tints, plus "no colour". Same palette as a note's tint. */
export const ORGANISE_COLORS: { value: string | null; label: string }[] = [
  { value: null, label: 'No colour' },
  { value: 'coral', label: 'Coral' },
  { value: 'peach', label: 'Peach' },
  { value: 'sand', label: 'Sand' },
  { value: 'sage', label: 'Sage' },
  { value: 'mint', label: 'Mint' },
  { value: 'sky', label: 'Sky' },
  { value: 'lavender', label: 'Lavender' },
  { value: 'blush', label: 'Blush' },
  { value: 'graphite', label: 'Graphite' },
]

const COLOR_VALUES = new Set(
  ORGANISE_COLORS.map((colour) => colour.value).filter((value): value is string => value !== null),
)

/** The icons offered for a notebook or a folder, in the order they are shown. */
export const ORGANISE_ICONS: readonly IconName[] = [
  'notebook', 'sparkle-folder', 'note', 'checklist', 'clipboard', 'template',
  'star', 'pin', 'tag', 'bell', 'calendar', 'meeting',
  'mic', 'image', 'scan', 'draw', 'table', 'code',
  'lock', 'share', 'user', 'pulse', 'archive', 'grid',
]

const ICON_NAMES = new Set<string>(ORGANISE_ICONS)

/** The stored icon if it is one of ours, otherwise the section's default. */
export function iconOrDefault(icon: string | null | undefined, fallback: IconName): IconName {
  return icon !== null && icon !== undefined && ICON_NAMES.has(icon) ? (icon as IconName) : fallback
}

/** The modifier for a colour dot, empty for an unknown or absent token. */
export function colorDotClass(color: string | null | undefined): string {
  return color !== null && color !== undefined && COLOR_VALUES.has(color) ? `org-dot--${color}` : ''
}

export function ColorField({
  value,
  onChange,
  label = 'Colour',
  disabled = false,
}: {
  value: string | null
  onChange: (color: string | null) => void
  label?: string
  disabled?: boolean
}) {
  const labelId = useId()

  return (
    <div className="org-field">
      <span className="org-label" id={labelId}>
        {label}
      </span>
      <div className="org-swatches" role="group" aria-labelledby={labelId}>
        {ORGANISE_COLORS.map((colour) => {
          const selected = value === colour.value

          return (
            <button
              key={colour.value ?? 'none'}
              type="button"
              className={`org-swatch ${colour.value ? `org-swatch--${colour.value}` : ''}`.trim()}
              aria-pressed={selected}
              aria-label={colour.label}
              title={colour.label}
              disabled={disabled}
              onClick={() => onChange(colour.value)}
            >
              {/* A tick as well as a ring: hue alone is not a signal every
                  reader can see. */}
              {selected ? <Icon name="check" size={14} /> : colour.value === null ? <Icon name="close" size={12} /> : null}
            </button>
          )
        })}
      </div>
    </div>
  )
}

export function IconField({
  value,
  onChange,
  fallback,
  label = 'Icon',
  disabled = false,
  autoFocus = false,
}: {
  value: string | null
  onChange: (icon: string | null) => void
  /** Shown as the "Default" choice, and used when nothing is chosen. */
  fallback: IconName
  label?: string
  disabled?: boolean
  /** Takes the dialog's opening focus, for a caller who came here to restyle. */
  autoFocus?: boolean
}) {
  const labelId = useId()
  const current = value !== null && ICON_NAMES.has(value) ? value : null

  return (
    <div className="org-field">
      <span className="org-label" id={labelId}>
        {label}
      </span>
      <div className="org-icons" role="group" aria-labelledby={labelId}>
        <button
          type="button"
          className="org-icon-option"
          aria-pressed={current === null}
          aria-label="Default icon"
          title="Default"
          disabled={disabled}
          data-autofocus={autoFocus ? '' : undefined}
          onClick={() => onChange(null)}
        >
          <Icon name={fallback} size={17} />
        </button>
        {ORGANISE_ICONS.map((name) => (
          <button
            key={name}
            type="button"
            className="org-icon-option"
            aria-pressed={current === name}
            aria-label={name.replace(/-/g, ' ')}
            title={name.replace(/-/g, ' ')}
            disabled={disabled}
            onClick={() => onChange(name)}
          >
            <Icon name={name} size={17} />
          </button>
        ))}
      </div>
    </div>
  )
}
