/**
 * The parts of Pulse that are decisions rather than markup.
 *
 * Two of them are worth pinning down away from a component. What a failure is
 * allowed to say — because "Something went wrong" in place of a rate limit
 * throws away the only actionable thing the server sent — and how much of a
 * model's structured answer is trusted, because a checklist that renders
 * whatever arrived in `data` is a checklist a malformed answer can fill with
 * anything.
 */

import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../shared/api/client'
import type { PulseActionDefinition } from '../../../shared/api/types'
import {
  checklistItems,
  describePulseFailure,
  groupPulseActions,
  tableData,
  wasCancelled,
} from './usePulse'
import type { PulseActionResult } from './usePulse'

function result(data: unknown): PulseActionResult {
  return { answer: 'prose', citations: [], grounded: false, data }
}

function abortError(): Error {
  const error = new Error('The operation was aborted.')
  error.name = 'AbortError'

  return error
}

describe('describePulseFailure', () => {
  it('is silent about a request the user cancelled', () => {
    expect(describePulseFailure(abortError())).toBeNull()
    expect(describePulseFailure(null)).toBeNull()
    expect(wasCancelled(abortError())).toBe(true)
  })

  it('keeps the server’s sentence for a switched-off deployment, and does not offer a retry', () => {
    const failure = describePulseFailure(
      new ApiError('FEATURE_DISABLED', 'This feature is not enabled on this deployment.', 503, { feature: 'ai' }),
    )

    expect(failure?.message).toBe('This feature is not enabled on this deployment.')
    // Asking again produces the same 503; a reload is what picks up the change.
    expect(failure?.retryable).toBe(false)
    expect(failure?.detail).toMatch(/Reload/)
  })

  it('turns a rate limit into a wait somebody can act on', () => {
    const soon = describePulseFailure(
      new ApiError('RATE_LIMITED', 'Too many requests — try again shortly.', 429, { retry_after: 45 }),
    )
    expect(soon?.message).toBe('Too many requests — try again shortly.')
    expect(soon?.detail).toBe('Pulse is free again in about 45 seconds.')

    const later = describePulseFailure(
      new ApiError('RATE_LIMITED', 'Too many requests — try again shortly.', 429, { retry_after: 90 }),
    )
    expect(later?.detail).toBe('Pulse is free again in about 2 minutes.')

    // A limiter that sent no window still produces a usable message.
    const bare = describePulseFailure(new ApiError('RATE_LIMITED', 'Too many requests.', 429))
    expect(bare?.detail).toBeNull()
  })

  it('treats being offline as its own state rather than a failure of Pulse', () => {
    const failure = describePulseFailure(new ApiError('OFFLINE', 'You appear to be offline.', 0))

    expect(failure?.icon).toBe('cloud-off')
    expect(failure?.retryable).toBe(true)
  })

  it('offers a retry for a server fault but not for a rejected request', () => {
    expect(describePulseFailure(new ApiError('UPSTREAM_UNAVAILABLE', 'Pulse did not answer.', 502))?.retryable)
      .toBe(true)
    expect(describePulseFailure(new ApiError('VALIDATION_FAILED', 'Ask Pulse something.', 422))?.retryable)
      .toBe(false)
  })
})

describe('checklistItems', () => {
  it('reads the items the server parsed out of the answer', () => {
    const items = checklistItems(
      result({ items: [{ text: 'Send the contract', due_at: '2026-11-03', assignee: 'Priya', priority: null }] }),
    )

    expect(items).toEqual([
      { text: 'Send the contract', due_at: '2026-11-03', assignee: 'Priya', priority: null },
    ])
  })

  it('drops anything that is not an item, rather than rendering a blank row', () => {
    expect(checklistItems(result({ items: [{ text: '   ' }, 'a string', 42, null, { due_at: 'today' }] }))).toEqual([])
    expect(checklistItems(result(null))).toEqual([])
    expect(checklistItems(result({ items: 'not a list' }))).toEqual([])
  })
})

describe('tableData', () => {
  it('takes the table only when the server actually shaped one', () => {
    expect(tableData(result({ columns: ['Supplier', 'Price'], rows: [['Acme', '£400']] }))).toEqual({
      columns: ['Supplier', 'Price'],
      rows: [['Acme', '£400']],
    })

    expect(tableData(result({ columns: [], rows: [] }))).toBeNull()
    expect(tableData(result({ rows: [['Acme']] }))).toBeNull()
    expect(tableData(result('a sentence'))).toBeNull()
  })
})

describe('groupPulseActions', () => {
  const action = (id: string, group: string): PulseActionDefinition => ({
    id,
    label: id,
    group,
    scope: 'selection',
    output: 'text',
    enabled: true,
  })

  it('keeps the server’s order and names the groups it knows', () => {
    const groups = groupPulseActions([
      action('summarise', 'understand'),
      action('rewrite', 'write'),
      action('explain', 'understand'),
    ])

    expect(groups.map((group) => group.label)).toEqual(['Understand', 'Write'])
    expect(groups[0].actions.map((item) => item.id)).toEqual(['summarise', 'explain'])
  })

  it('shows a group this release has no name for rather than losing its actions', () => {
    const groups = groupPulseActions([action('detect_risk', 'compliance')])

    expect(groups).toHaveLength(1)
    expect(groups[0].label).toBe('compliance')
    expect(groups[0].actions).toHaveLength(1)
  })
})
