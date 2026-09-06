/**
 * Autosave, tested the way a writer would notice it failing: too many saves,
 * too few, or one that quietly overwrites somebody else's paragraph.
 */

import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '../../shared/api/client'
import { AUTOSAVE_IDLE_MS, AUTOSAVE_MAX_WAIT_MS, useAutosave } from './useAutosave'
import type { Note } from '../../shared/api/types'

function noteAt(version: number): Note {
  return {
    id: 'note-1',
    note_type: 'document',
    title: 'A note',
    display_title: 'A note',
    excerpt: '',
    notebook_id: null,
    color: null,
    is_pinned: false,
    is_favourite: false,
    is_archived: false,
    is_locked: false,
    privacy_mode: 'standard',
    version,
    word_count: 0,
    char_count: 0,
    owner_user_id: 'user-1',
    created_at: null,
    updated_at: null,
    deleted_at: null,
    role: 'owner',
    is_shared: false,
    attachment_count: 0,
    has_reminder: false,
    checklist: null,
    tags: [],
    document: { type: 'doc', content: [] },
    document_schema_version: 1,
    content_hash: '',
    source: null,
    language: null,
    template_key: null,
    capabilities: {
      view: true,
      comment: true,
      edit: true,
      share: true,
      delete: true,
      restore: true,
      manage_members: true,
    },
  }
}

describe('useAutosave', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  it('waits for typing to stop, then saves once with everything typed', async () => {
    const save = vi.fn().mockResolvedValue(noteAt(4))
    const { result } = renderHook(() =>
      useAutosave({ noteId: 'note-1', version: 3, enabled: true, save }),
    )

    await act(async () => {
      result.current.schedule({ title: 'Half a t' })
      vi.advanceTimersByTime(400)
      result.current.schedule({ title: 'Half a thought' })
      vi.advanceTimersByTime(400)
    })

    expect(save).not.toHaveBeenCalled()

    await act(async () => {
      vi.advanceTimersByTime(AUTOSAVE_IDLE_MS)
    })

    expect(save).toHaveBeenCalledTimes(1)
    expect(save).toHaveBeenCalledWith({ id: 'note-1', version: 3, title: 'Half a thought' })
  })

  it('saves during continuous typing rather than waiting for a pause', async () => {
    const save = vi.fn().mockResolvedValue(noteAt(4))
    const { result } = renderHook(() =>
      useAutosave({ noteId: 'note-1', version: 3, enabled: true, save }),
    )

    // Never idle long enough for the debounce to fire on its own.
    await act(async () => {
      for (let elapsed = 0; elapsed < AUTOSAVE_MAX_WAIT_MS; elapsed += 600) {
        result.current.schedule({ title: `draft ${elapsed}` })
        vi.advanceTimersByTime(600)
      }
    })

    expect(save).toHaveBeenCalledTimes(1)
  })

  it('carries the version the server last returned', async () => {
    const save = vi.fn().mockResolvedValueOnce(noteAt(9)).mockResolvedValueOnce(noteAt(10))
    const { result } = renderHook(() =>
      useAutosave({ noteId: 'note-1', version: 8, enabled: true, save }),
    )

    await act(async () => {
      result.current.schedule({ title: 'first' })
      vi.advanceTimersByTime(AUTOSAVE_IDLE_MS)
    })
    await act(async () => {
      result.current.schedule({ title: 'second' })
      vi.advanceTimersByTime(AUTOSAVE_IDLE_MS)
    })

    expect(save.mock.calls[0]?.[0]).toMatchObject({ version: 8 })
    expect(save.mock.calls[1]?.[0]).toMatchObject({ version: 9 })
  })

  it('stops saving on a version conflict instead of overwriting', async () => {
    const conflict = new ApiError('VERSION_CONFLICT', 'This note was changed somewhere else.', 409, {
      server_version: 12,
      note: noteAt(12),
    })
    const save = vi.fn().mockRejectedValue(conflict)
    const { result } = renderHook(() =>
      useAutosave({ noteId: 'note-1', version: 3, enabled: true, save }),
    )

    await act(async () => {
      result.current.schedule({ title: 'mine' })
      vi.advanceTimersByTime(AUTOSAVE_IDLE_MS)
    })

    expect(result.current.state).toBe('conflict')
    expect(result.current.conflict?.serverVersion).toBe(12)
    expect(result.current.conflict?.message).toContain('changed somewhere else')

    // Nothing further is sent, however long the user keeps typing.
    await act(async () => {
      result.current.schedule({ title: 'mine, still' })
      vi.advanceTimersByTime(AUTOSAVE_MAX_WAIT_MS * 2)
    })

    expect(save).toHaveBeenCalledTimes(1)
    // And the words are still there to be recovered.
    expect(result.current.hasPendingChanges).toBe(true)
  })

  it('sends again once the writer has resolved the conflict', async () => {
    const conflict = new ApiError('VERSION_CONFLICT', 'Changed elsewhere.', 409, {
      server_version: 12,
    })
    const save = vi.fn().mockRejectedValueOnce(conflict).mockResolvedValue(noteAt(13))
    const { result } = renderHook(() =>
      useAutosave({ noteId: 'note-1', version: 3, enabled: true, save }),
    )

    await act(async () => {
      result.current.schedule({ title: 'mine' })
      vi.advanceTimersByTime(AUTOSAVE_IDLE_MS)
    })

    await act(async () => {
      result.current.resume(12)
      await result.current.flush()
    })

    expect(save).toHaveBeenCalledTimes(2)
    expect(save.mock.calls[1]?.[0]).toMatchObject({ version: 12, title: 'mine' })
    expect(result.current.conflict).toBeNull()
  })

  it('never saves a note the reader may not edit', async () => {
    const save = vi.fn().mockResolvedValue(noteAt(4))
    const { result } = renderHook(() =>
      useAutosave({ noteId: 'note-1', version: 3, enabled: false, save }),
    )

    await act(async () => {
      result.current.schedule({ title: 'not mine to change' })
      vi.advanceTimersByTime(AUTOSAVE_MAX_WAIT_MS * 2)
      await result.current.flush()
    })

    expect(save).not.toHaveBeenCalled()
  })

  it('flushes what is pending when the tab is hidden', async () => {
    const save = vi.fn().mockResolvedValue(noteAt(4))
    const { result } = renderHook(() =>
      useAutosave({ noteId: 'note-1', version: 3, enabled: true, save }),
    )

    await act(async () => {
      result.current.schedule({ title: 'about to switch tabs' })
    })

    await act(async () => {
      vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
      document.dispatchEvent(new Event('visibilitychange'))
    })

    expect(save).toHaveBeenCalledTimes(1)
  })
})
