/**
 * Who else has this note open, and whether it has moved since this device
 * last saw it — polled, not pushed.
 *
 * cPanel has no daemon to hold a WebSocket or an SSE stream open, and the
 * sibling product that already looked at this exact problem — Pulse's
 * `useNotifications.js` — reached the same conclusion for the same reason:
 * "SSE/WebSockets are deliberately avoided: shared cPanel hosting already
 * spends a PHP worker per streaming chat turn." So this polls
 * `POST /notes/{id}/presence` on a short interval, the way that hook polls
 * its own feed on a much longer one — paused while the tab is hidden, and
 * backing off when the server stops answering, so a missing or unhealthy
 * endpoint degrades to "no presence shown" rather than a request storm.
 *
 * This hook does not decide what to do with a newer version — it only
 * notices one exists. Applying it safely (never while the writer is focused
 * or has something unsent) is {@link NoteEditor}'s job, and that rule already
 * existed before this hook did: presence's whole contribution is refetching
 * the note promptly enough for that existing rule to run sooner than the
 * writer's own next save would have discovered the same thing as a 409.
 */

import { useEffect, useRef, useState } from 'react'

import { api } from '../../../shared/api/client'

const BASE_INTERVAL_MS = 8_000
const MAX_INTERVAL_MS = 60_000
/** After this many consecutive failures, stop showing viewers rather than stale ones. */
const FAILURES_BEFORE_HIDING = 3

export interface PresenceViewer {
  user_id: string
  display_name: string
}

export interface PresenceState {
  /** Everyone else currently on this note. Never includes the caller. */
  viewers: PresenceViewer[]
  /**
   * The note's version and `updated_at` as of the last successful poll, or
   * `null` before the first one has landed. Compare `version` against what
   * is in the query cache; when it is higher, something has been saved since
   * that cache entry was fetched.
   */
  version: number | null
  updatedAt: string | null
}

const EMPTY: PresenceState = { viewers: [], version: null, updatedAt: null }

/**
 * @param noteId The open note, or `undefined` for "nothing to report on" —
 *        a `/notes/new` in-progress create, say.
 * @param enabled Both the `realtime` flag and whatever else makes this note
 *        worth polling (the caller can read the note at all). Off means no
 *        requests are made at all, not merely that the result is ignored —
 *        the same honesty rule every other flag-gated control in this app
 *        follows.
 */
export function usePresence(noteId: string | undefined, enabled: boolean): PresenceState {
  const [state, setState] = useState<PresenceState>(EMPTY)
  const failuresRef = useRef(0)

  useEffect(() => {
    if (!noteId || !enabled) {
      setState(EMPTY)
      return
    }

    let cancelled = false
    let timer: ReturnType<typeof setTimeout> | null = null

    const poll = async () => {
      try {
        const response = await api.request<PresenceViewer[]>('POST', `/notes/${noteId}/presence`)
        if (cancelled) return

        failuresRef.current = 0
        const version = typeof response.meta.version === 'number' ? response.meta.version : null
        const updatedAt = typeof response.meta.updated_at === 'string' ? response.meta.updated_at : null
        setState({ viewers: response.data, version, updatedAt })
      } catch {
        if (cancelled) return

        failuresRef.current += 1
        // A quiet degrade, the same as the notification feed's: once a
        // deployment or a network is clearly not answering, an increasingly
        // stale "who's here" list is worse than admitting there is none.
        if (failuresRef.current >= FAILURES_BEFORE_HIDING) setState(EMPTY)
      }
    }

    const schedule = () => {
      if (cancelled) return
      const backoff = Math.min(
        BASE_INTERVAL_MS * 2 ** Math.max(0, failuresRef.current - 1),
        MAX_INTERVAL_MS,
      )
      timer = setTimeout(async () => {
        if (!document.hidden) await poll()
        schedule()
      }, backoff)
    }

    void poll()
    schedule()

    const onVisible = () => {
      if (!document.hidden) void poll()
    }
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      cancelled = true
      if (timer) clearTimeout(timer)
      document.removeEventListener('visibilitychange', onVisible)
      failuresRef.current = 0

      // Best-effort: closing the note (switching to another, navigating away,
      // the flag turning off) is the common case this actually reaches. A
      // hard tab close or refresh cannot run a cleanup fetch reliably either
      // way, and does not need to — the row ages out of every viewer list
      // within one active window regardless, and the worker's maintenance
      // sweep removes it for good shortly after.
      void api.delete(`/notes/${noteId}/presence`).catch(() => undefined)
    }
  }, [noteId, enabled])

  return state
}
