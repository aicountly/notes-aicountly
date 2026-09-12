/**
 * The properties worth protecting in a poller nobody watches directly:
 * it must be silent when it should be (disabled, or the tab is hidden), it
 * must eventually stop pretending to know who is here once the server has
 * stopped answering, and closing a note must not leave a stray heartbeat
 * running against the next one that opens.
 *
 * Fake timers throughout, and no `waitFor` anywhere: `waitFor`'s own retry
 * loop runs on real timers, which never tick while fake ones are installed.
 * `vi.advanceTimersByTimeAsync` is what both fires the interval *and* lets
 * the mocked request's promise resolve before the next assertion runs.
 */

import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../../shared/api/client', () => ({
  api: { request: vi.fn(), delete: vi.fn() },
}))

const { api } = await import('../../../shared/api/client')
const { usePresence } = await import('./usePresence')

const mockedRequest = api.request as unknown as ReturnType<typeof vi.fn>
const mockedDelete = api.delete as unknown as ReturnType<typeof vi.fn>

function response(viewers: Array<{ user_id: string; display_name: string }>, version = 1) {
  return { data: viewers, meta: { version, updated_at: '2026-01-01T00:00:00Z' } }
}

async function flush(ms = 0) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms)
  })
}

beforeEach(() => {
  vi.useFakeTimers()
  mockedRequest.mockReset().mockResolvedValue(response([]))
  mockedDelete.mockReset().mockResolvedValue(undefined)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('usePresence', () => {
  it('makes no request at all when disabled — not merely an ignored one', async () => {
    renderHook(() => usePresence('note-1', false))
    await flush()

    expect(mockedRequest).not.toHaveBeenCalled()
  })

  it('makes no request without a note to report on', async () => {
    renderHook(() => usePresence(undefined, true))
    await flush()

    expect(mockedRequest).not.toHaveBeenCalled()
  })

  it('heartbeats immediately and reports who else is here', async () => {
    mockedRequest.mockResolvedValue(response([{ user_id: 'user-b', display_name: 'Bob' }], 4))

    const { result } = renderHook(() => usePresence('note-1', true))
    await flush()

    expect(mockedRequest).toHaveBeenCalledWith('POST', '/notes/note-1/presence')
    expect(result.current.viewers).toEqual([{ user_id: 'user-b', display_name: 'Bob' }])
    expect(result.current.version).toBe(4)
    expect(result.current.updatedAt).toBe('2026-01-01T00:00:00Z')
  })

  it('keeps polling on an interval', async () => {
    renderHook(() => usePresence('note-1', true))
    await flush()
    expect(mockedRequest).toHaveBeenCalledTimes(1)

    await flush(8_000)
    expect(mockedRequest).toHaveBeenCalledTimes(2)

    await flush(8_000)
    expect(mockedRequest).toHaveBeenCalledTimes(3)
  })

  it('pauses while the tab is hidden, and polls again the moment it is not', async () => {
    renderHook(() => usePresence('note-1', true))
    await flush()
    expect(mockedRequest).toHaveBeenCalledTimes(1)

    Object.defineProperty(document, 'hidden', { configurable: true, value: true })
    await flush(30_000)
    // A request storm from a background tab is exactly what pausing exists to
    // avoid; several interval lengths must pass with nothing sent.
    expect(mockedRequest).toHaveBeenCalledTimes(1)

    Object.defineProperty(document, 'hidden', { configurable: true, value: false })
    await act(async () => {
      document.dispatchEvent(new Event('visibilitychange'))
      await vi.advanceTimersByTimeAsync(0)
    })
    expect(mockedRequest).toHaveBeenCalledTimes(2)
  })

  it('stops showing viewers once the server has clearly stopped answering', async () => {
    mockedRequest
      .mockResolvedValueOnce(response([{ user_id: 'user-b', display_name: 'Bob' }]))
      .mockRejectedValue(new Error('offline'))

    const { result } = renderHook(() => usePresence('note-1', true))
    await flush()
    expect(result.current.viewers).toHaveLength(1)

    // One failure is a blip; a poller that hid the list on the first miss
    // would flicker empty on every ordinary network hiccup.
    await flush(8_000)
    expect(result.current.viewers).toHaveLength(1)

    await flush(16_000)
    await flush(32_000)

    expect(result.current.viewers).toEqual([])
  })

  it('says goodbye for the note it was polling, not the one that replaces it', async () => {
    const { rerender, unmount } = renderHook(({ id }) => usePresence(id, true), {
      initialProps: { id: 'note-1' },
    })
    await flush()
    expect(mockedRequest).toHaveBeenCalledWith('POST', '/notes/note-1/presence')

    act(() => rerender({ id: 'note-2' }))
    expect(mockedDelete).toHaveBeenCalledWith('/notes/note-1/presence')
    await flush()
    expect(mockedRequest).toHaveBeenCalledWith('POST', '/notes/note-2/presence')

    act(() => unmount())
    expect(mockedDelete).toHaveBeenCalledWith('/notes/note-2/presence')
  })

  it('never lets a leave request block on the network', async () => {
    mockedDelete.mockRejectedValue(new Error('gone'))
    const { unmount } = renderHook(() => usePresence('note-1', true))
    await flush()

    expect(() => act(() => unmount())).not.toThrow()
  })
})
