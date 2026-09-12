/**
 * The snippet parser.
 *
 * The important test here is the third one. The server sends delimiters rather
 * than HTML so that a note containing markup is rendered as characters, and the
 * only way that stays true is to keep asserting it.
 */

import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Highlight, parseHighlights } from './Highlight'

describe('parseHighlights', () => {
  it('splits a marked snippet into text and matches', () => {
    expect(parseHighlights('the [[hl]]invoice[[/hl]] is due')).toEqual([
      { text: 'the ', match: false },
      { text: 'invoice', match: true },
      { text: ' is due', match: false },
    ])
  })

  it('treats a snippet with no markers as one run of text', () => {
    expect(parseHighlights('nothing matched here')).toEqual([
      { text: 'nothing matched here', match: false },
    ])
  })

  it('drops an unclosed marker instead of highlighting the rest', () => {
    expect(parseHighlights('quarterly [[hl]]report')).toEqual([
      { text: 'quarterly report', match: false },
    ])
  })

  it('honours the delimiters the server reported', () => {
    const segments = parseHighlights('a <b>bold</b> claim', { open: '<b>', close: '</b>' })

    expect(segments).toEqual([
      { text: 'a ', match: false },
      { text: 'bold', match: true },
      { text: ' claim', match: false },
    ])
  })
})

describe('Highlight', () => {
  it('wraps each match in a mark element', () => {
    render(<Highlight text="pay the [[hl]]rent[[/hl]] on [[hl]]Friday[[/hl]]" />)

    const marks = screen.getAllByText(/rent|Friday/)
    expect(marks).toHaveLength(2)
    expect(marks[0].tagName).toBe('MARK')
    expect(marks[1].tagName).toBe('MARK')
  })

  it('renders markup inside a note as text, never as elements', () => {
    const hostile = 'before <script>alert(1)</script> <img src=x onerror=alert(2)> after'

    const { container } = render(<Highlight text={`[[hl]]${hostile}[[/hl]]`} />)

    expect(container.querySelector('script')).toBeNull()
    expect(container.querySelector('img')).toBeNull()
    expect(container.textContent).toBe(hostile)
  })
})
