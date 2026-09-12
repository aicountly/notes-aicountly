/**
 * What a save invalidates, and what it must not.
 *
 * `['notes']` is a prefix of `['notes','detail',id]`, so invalidating it to
 * refresh the sidebar also refetches whichever note is open. On an autosave —
 * every 1.2 seconds of typing — that discards the fresh copy the mutation was
 * just handed and asks the server for it again, for the note being typed into.
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import type { ReactNode } from 'react'

import { useUpdateNote } from './useNotes'
import { queryKeys } from '../../../shared/query/queryClient'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

const fetchMock = vi.fn()

function envelope(data: unknown) {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ success: true, data }),
  } as unknown as Response
}

const NOTE = { id: 'note-1', title: 'Saved', version: 4, display_title: 'Saved' }

beforeEach(() => {
  fetchMock.mockReset().mockImplementation(async () => envelope(NOTE))
  vi.stubGlobal('fetch', fetchMock)
})

describe('useUpdateNote', () => {
  it('refreshes the lists without refetching the note it just saved', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })
    const invalidated: unknown[] = []
    const original = client.invalidateQueries.bind(client)
    vi.spyOn(client, 'invalidateQueries').mockImplementation((filters) => {
      invalidated.push((filters as { queryKey?: unknown } | undefined)?.queryKey)
      return original(filters)
    })

    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    )
    const { result } = renderHook(() => useUpdateNote(), { wrapper })

    await result.current.mutateAsync({ id: 'note-1', title: 'Saved', version: 4 })
    await waitFor(() => expect(invalidated.length).toBeGreaterThan(0))

    expect(invalidated).toContainEqual(queryKeys.notes.lists)
    // `['notes']` would take the open note's detail with it.
    expect(invalidated).not.toContainEqual(queryKeys.notes.all)

    // And the fresh copy is in the cache rather than thrown away.
    expect(client.getQueryData(queryKeys.notes.detail('note-1'))).toMatchObject({ version: 4 })
  })
})
