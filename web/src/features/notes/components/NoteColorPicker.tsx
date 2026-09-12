/**
 * The nine note tints, plus "no colour".
 *
 * A tint is a scanning aid, not a label, so the control is a small grid of
 * swatches rather than a named list. The selected swatch is marked with a tick
 * as well as a ring: hue on its own is not a signal a colour-blind user can
 * read, and every swatch carries its name for a screen reader.
 */

import { Icon } from '../../../shared/ui/Icon'
import type { NoteColor } from '../../../shared/api/types'

const COLOURS: { value: NoteColor | null; label: string }[] = [
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

export interface NoteColorPickerProps {
  value: NoteColor | null
  onSelect: (colour: NoteColor | null) => void
  /** Set while the change is in flight, so a second click cannot race the first. */
  busy?: boolean
}

export function NoteColorPicker({ value, onSelect, busy = false }: NoteColorPickerProps) {
  return (
    <div className="note-colours" role="group" aria-label="Note colour">
      {COLOURS.map((colour) => {
        const selected = value === colour.value

        return (
          <button
            key={colour.value ?? 'none'}
            type="button"
            className={`note-colour ${colour.value ? `note-colour--${colour.value}` : ''}`.trim()}
            aria-pressed={selected}
            aria-label={colour.label}
            title={colour.label}
            disabled={busy}
            onClick={() => onSelect(colour.value)}
          >
            {selected ? <Icon name="check" size={15} /> : colour.value === null ? <Icon name="close" size={13} /> : null}
          </button>
        )
      })}
    </div>
  )
}
