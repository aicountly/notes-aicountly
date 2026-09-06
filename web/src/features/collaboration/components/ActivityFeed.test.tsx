/**
 * The trail, read as English.
 *
 * The rows the server writes are action codes and ids. These tests are about
 * the two things that turns into: a sentence with a name in it, and one line
 * for "shared this note with three people" rather than three.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { ActivityFeed, describeActivity, groupActivity } from './ActivityFeed'
import type { ActivityEntry } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

vi.mock('../../../auth/AuthProvider', () => ({
  useAuth: () => ({
    status: 'authenticated',
    message: null,
    profile: { user_id: 'u_me', tenant_id: null, display_name: 'Me', email: 'me@example.com' },
    signIn: () => undefined,
    signOut: () => undefined,
  }),
}))

function entry(overrides: Partial<ActivityEntry> & { id: string }): ActivityEntry {
  return {
    actor_user_id: 'u_priya',
    action: 'note.updated',
    context: {},
    created_at: new Date(Date.now() - 60_000).toISOString(),
    ...overrides,
  }
}

const NAMES = (userId: string): string =>
  userId === 'u_me' ? 'You' : userId === 'u_priya' ? 'Priya' : 'Someone'

function envelope(data: unknown, meta?: Record<string, unknown>): Response {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ success: true, data, meta }),
  } as unknown as Response
}

const fetchMock = vi.fn()

beforeEach(() => {
  fetchMock.mockImplementation(async (input: unknown) => {
    const url = String(input)
    if (url.includes('/members')) {
      return envelope([{ user_id: 'u_priya', role: 'editor', display_name: 'Priya' }])
    }
    return envelope(
      [
        entry({ id: 'a1', action: 'member.added', context: { member_user_id: 'u_sam', role: 'viewer' } }),
        entry({ id: 'a2', action: 'member.added', context: { member_user_id: 'u_lee', role: 'viewer' } }),
      ],
      { total: 2, has_more: false },
    )
  })
  globalThis.fetch = fetchMock as unknown as typeof fetch
})

describe('activity sentences', () => {
  it('collapses a run of the same act by the same person', () => {
    const groups = groupActivity([
      entry({ id: 'a1', action: 'member.added', context: { member_user_id: 'u_sam' } }),
      entry({ id: 'a2', action: 'member.added', context: { member_user_id: 'u_lee' } }),
      entry({ id: 'a3', action: 'comment.added' }),
    ])

    expect(groups).toHaveLength(2)
    expect(groups[0].count).toBe(2)
    expect(describeActivity(groups[0], NAMES)).toBe('Priya shared this note with 2 people')
    expect(describeActivity(groups[1], NAMES)).toBe('Priya commented')
  })

  it('names the person a note was shared with, and the role they were given', () => {
    const [added] = groupActivity([
      entry({ id: 'a1', action: 'member.added', context: { member_user_id: 'u_me' } }),
    ])
    // As the object of the sentence, "You" is not capitalised.
    expect(describeActivity(added, NAMES)).toBe('Priya shared this note with you')

    const [changed] = groupActivity([
      entry({
        id: 'a2',
        action: 'member.role_changed',
        context: { member_user_id: 'u_me', role: 'editor' },
      }),
    ])
    expect(describeActivity(changed, NAMES)).toBe('Priya made you an editor')
  })

  it('says something readable for an action it has never heard of', () => {
    const [unknownAction] = groupActivity([entry({ id: 'a1', action: 'note.exported_to_drive' })])

    expect(describeActivity(unknownAction, NAMES)).toBe('Priya note exported to drive')
  })

  it('renders the trail without ever showing what the note says', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={client}>
        <ActivityFeed noteId="note-1" />
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Priya shared this note with 2 people')).toBeInTheDocument()
  })

  /**
   * The server clamps `limit` to 200 and still reports `has_more` against the
   * whole trail, so at the cap "show earlier" would fetch the same page for
   * ever. It says so instead of offering a button that changes nothing.
   */
  it('stops offering more once the server will not return more', async () => {
    fetchMock.mockImplementation(async (input: unknown) => {
      const url = String(input)
      if (url.includes('/members')) {
        return envelope([{ user_id: 'u_priya', role: 'editor', display_name: 'Priya' }])
      }
      return envelope(
        [
          entry({ id: 'a1', action: 'member.added', context: { member_user_id: 'u_sam' } }),
          entry({ id: 'a2', action: 'member.added', context: { member_user_id: 'u_lee' } }),
        ],
        // What the server says at the cap: more exist, but not in this page.
        { total: 400, has_more: true },
      )
    })

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={client}>
        <ActivityFeed noteId="note-1" pageSize={200} />
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Priya shared this note with 2 people')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Show earlier activity/ })).not.toBeInTheDocument()
    expect(screen.getByText(/Showing the 200 most recent entries/)).toBeInTheDocument()
  })
})
