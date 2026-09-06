/**
 * Rendering a search snippet.
 *
 * The server marks matches with plain-text delimiters — `[[hl]]…[[/hl]]` by
 * default, and whatever `meta.highlight` reports otherwise — precisely so that
 * no client has to put note text through `innerHTML`. A snippet is a fragment
 * of something a user wrote, or of something a collaborator wrote into a note
 * they shared; the markers exist so it can be split and rendered as text nodes.
 *
 * Hence the rule this file exists to keep: **no `dangerouslySetInnerHTML`**.
 * A note whose body is `<script>alert(1)</script>` renders as those twenty-four
 * characters, and the worst a hostile note can do is show square brackets.
 */

import { Fragment, useMemo } from 'react'

export interface HighlightMarkers {
  open: string
  close: string
}

/** What the server uses unless `meta.highlight` says otherwise. */
export const DEFAULT_MARKERS: HighlightMarkers = { open: '[[hl]]', close: '[[/hl]]' }

export interface HighlightSegment {
  text: string
  /** True for the part of the snippet the query matched. */
  match: boolean
}

/** Remove markers from text that is not being parsed as a highlight. */
function stripMarkers(text: string, markers: HighlightMarkers): string {
  return text.split(markers.open).join('').split(markers.close).join('')
}

/**
 * Split a marked snippet into text and matched runs.
 *
 * Only a *balanced* pair produces a match. A stray opening marker is dropped
 * rather than highlighting everything after it: a highlight is a claim about
 * where the query matched, and a claim made by a broken snippet is worse than
 * no highlight at all.
 */
export function parseHighlights(
  snippet: string,
  markers: HighlightMarkers = DEFAULT_MARKERS,
): HighlightSegment[] {
  if (snippet === '') return []
  if (markers.open === '' || markers.close === '') return [{ text: snippet, match: false }]

  const segments: HighlightSegment[] = []
  let cursor = 0

  while (cursor < snippet.length) {
    const start = snippet.indexOf(markers.open, cursor)
    if (start === -1) break

    const end = snippet.indexOf(markers.close, start + markers.open.length)
    if (end === -1) break

    if (start > cursor) segments.push({ text: snippet.slice(cursor, start), match: false })

    const inner = snippet.slice(start + markers.open.length, end)
    if (inner !== '') segments.push({ text: inner, match: true })

    cursor = end + markers.close.length
  }

  if (cursor < snippet.length) {
    const rest = stripMarkers(snippet.slice(cursor), markers)
    if (rest !== '') segments.push({ text: rest, match: false })
  }

  return segments
}

export interface HighlightProps {
  text: string
  markers?: HighlightMarkers
  className?: string
}

export function Highlight({ text, markers, className }: HighlightProps) {
  const segments = useMemo(() => parseHighlights(text, markers), [text, markers])

  if (segments.length === 0) return null

  return (
    <span className={className}>
      {segments.map((segment, index) =>
        segment.match ? (
          <mark key={index} className="search-hit__mark">
            {segment.text}
          </mark>
        ) : (
          <Fragment key={index}>{segment.text}</Fragment>
        ),
      )}
    </span>
  )
}
