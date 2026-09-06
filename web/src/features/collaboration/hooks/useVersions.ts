/**
 * A note's earlier versions.
 *
 * The server checkpoints on save and coalesces a burst of autosaves by one
 * person into a single revision, so this list is a history somebody can read
 * rather than one row every four seconds.
 *
 * The thing worth knowing about restoring: it is an ordinary edit. The current
 * document becomes a revision *first* and the rollback is then just another
 * save, so restoring the wrong version loses nothing — the confirmation says
 * so, because a person who believes they are about to overwrite their morning
 * will not click it.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { Note, NoteRevision, NoteRevisionDetail } from '../../../shared/api/types'

/** Why a checkpoint exists, in words. */
export const REVISION_REASON: Record<NoteRevision['reason'], string> = {
  autosave: 'While writing',
  manual: 'Saved by hand',
  restore: 'Before a restore',
  import: 'Imported',
  template: 'From a template',
}

export interface VersionPage {
  entries: NoteRevision[]
  /** Every checkpoint this note has, not just the page that was fetched. */
  total: number
}

export function useNoteVersions(
  noteId: string | null,
  limit = 50,
): UseQueryResult<VersionPage, ApiError> {
  return useQuery<VersionPage, ApiError>({
    queryKey: queryKeys.notes.versions(noteId ?? ''),
    enabled: noteId !== null,
    queryFn: async () => {
      const { data, meta } = await api.getWithMeta<NoteRevision[]>(`/notes/${noteId}/versions`, {
        query: { limit },
      })

      return { entries: data, total: typeof meta.total === 'number' ? meta.total : data.length }
    },
  })
}

/**
 * One version's document, for the preview.
 *
 * Keyed under the list's own key so that invalidating a note's versions drops
 * the previews with it, and so a document fetched once is not fetched again
 * while somebody clicks up and down the list comparing them.
 */
export function useNoteVersion(
  noteId: string | null,
  versionId: string | null,
): UseQueryResult<NoteRevisionDetail, ApiError> {
  return useQuery<NoteRevisionDetail, ApiError>({
    queryKey: [...queryKeys.notes.versions(noteId ?? ''), versionId ?? ''],
    enabled: noteId !== null && versionId !== null,
    // A stored revision cannot change, so it is worth keeping for the session.
    staleTime: Infinity,
    queryFn: () => api.get<NoteRevisionDetail>(`/notes/${noteId}/versions/${versionId}`),
  })
}

export function useRestoreVersion() {
  const client = useQueryClient()

  return useMutation<Note, ApiError, { noteId: string; versionId: string }>({
    mutationFn: ({ noteId, versionId }) =>
      api.post<Note>(`/notes/${noteId}/versions/${versionId}/restore`),
    onSuccess: (note) => {
      // The restored note is the answer, so the editor can show it without a
      // second round trip; everything derived from the document — the lists,
      // the history it just gained a row in, the trail — is re-read.
      client.setQueryData(queryKeys.notes.detail(note.id), note)
      void client.invalidateQueries({ queryKey: queryKeys.notes.all })
    },
  })
}
