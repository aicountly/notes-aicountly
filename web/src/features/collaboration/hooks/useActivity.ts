/**
 * A note's activity trail.
 *
 * The rows carry ids, roles and counts — never note content — because the
 * trail is visible to every collaborator on the note, including the viewer who
 * was added this morning. `ActivityFeed` turns them into sentences; this file
 * only fetches them.
 *
 * Offset paging rather than a cursor, matching the server: the trail is read
 * by a person scrolling a short panel, and recording re-dates the row it
 * collapses a burst into, which a cursor would then skip past.
 */

import { useQuery } from '@tanstack/react-query'
import type { UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { ActivityEntry } from '../../../shared/api/types'

export interface ActivityPage {
  entries: ActivityEntry[]
  total: number
  hasMore: boolean
}

export function useNoteActivity(
  noteId: string | null,
  limit = 25,
): UseQueryResult<ActivityPage, ApiError> {
  return useQuery<ActivityPage, ApiError>({
    // `limit` belongs in the key: "show more" re-reads the same trail at a
    // larger size, and reusing one key would hand the first page back from
    // cache and make the button look broken.
    queryKey: [...queryKeys.notes.activity(noteId ?? ''), limit],
    enabled: noteId !== null,
    queryFn: async () => {
      const { data, meta } = await api.getWithMeta<ActivityEntry[]>(`/notes/${noteId}/activity`, {
        query: { limit },
      })

      return {
        entries: data,
        total: typeof meta.total === 'number' ? meta.total : data.length,
        hasMore: Boolean(meta.has_more),
      }
    },
  })
}
