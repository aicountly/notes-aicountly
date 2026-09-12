/**
 * Who else is looking at this note right now, as a small stack of initials.
 *
 * Deliberately not names spelled out in the header — that competes with the
 * note's own title for space, and a title bar that grows and shrinks as
 * people come and go is its own kind of distraction. Each circle carries the
 * full name as its accessible name and its tooltip; nothing here is only
 * conveyed by colour.
 */

import type { PresenceViewer } from '../hooks/usePresence'

/** Beyond this many, the rest collapse into one "+N" circle. */
const MAX_SHOWN = 3

function initials(name: string): string {
  const trimmed = name.trim()
  if (trimmed === '') return '?'

  const parts = trimmed.split(/\s+/)
  const first = parts[0]?.[0] ?? ''
  const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : ''

  return (first + last).toUpperCase() || '?'
}

export function PresenceAvatars({ viewers }: { viewers: PresenceViewer[] }) {
  if (viewers.length === 0) return null

  const shown = viewers.slice(0, MAX_SHOWN)
  const overflow = viewers.length - shown.length

  return (
    <div
      className="presence-avatars"
      role="group"
      aria-label={`${viewers.length} other ${viewers.length === 1 ? 'person is' : 'people are'} viewing this note`}
    >
      {shown.map((viewer) => (
        <span key={viewer.user_id} className="presence-avatars__item" title={viewer.display_name}>
          {initials(viewer.display_name)}
        </span>
      ))}
      {overflow > 0 ? (
        <span className="presence-avatars__item presence-avatars__item--overflow" title={`${overflow} more`}>
          +{overflow}
        </span>
      ) : null}
    </div>
  )
}
