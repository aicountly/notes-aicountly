/**
 * Saved queries that look like folders.
 *
 * A smart folder never moves a note. It stores a rule tree and nothing else,
 * so one note can be in five folders at once and in none of them tomorrow
 * because someone removed a tag — which is why deleting a folder cannot lose
 * anything, and why the delete confirmation says so.
 *
 * The count beside each folder is live and comes from the server, capped at
 * 500 so the sidebar does not get slower as a library grows: past the cap the
 * server says it stopped counting and the UI renders "500+". A folder saved
 * against a rule vocabulary this release no longer supports comes back with
 * `rules_valid: false` rather than taking the sidebar down with it.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { QueryClient, UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { SmartFolder, SmartFolderRules } from '../../../shared/api/types'

/** How many folders one person may keep. Mirrors `SmartFolderService`. */
export const MAX_SMART_FOLDERS = 50

/** The ceiling the server counts to before it reports "capped". */
export const COUNT_CAP = 500

/**
 * A folder as the presenter actually sends it.
 *
 * The three extra fields are not in the shared wire types yet; they are
 * optional here so an older server that omits them still renders.
 */
export interface SmartFolderNode extends SmartFolder {
  /** True when the real number is at or above {@link COUNT_CAP}. */
  note_count_is_capped?: boolean
  /** False when the stored rules no longer compile — the badge is then unknown. */
  rules_valid?: boolean
  created_at?: string | null
  updated_at?: string | null
}

export const EMPTY_RULES: SmartFolderRules = { match: 'all', conditions: [] }

export function useSmartFolders(): UseQueryResult<SmartFolderNode[], ApiError> {
  return useQuery<SmartFolderNode[], ApiError>({
    queryKey: queryKeys.smartFolders,
    queryFn: () => api.get<SmartFolderNode[]>('/smart-folders'),
    staleTime: 60_000,
  })
}

export interface SmartFolderInput {
  name: string
  rules: SmartFolderRules
  icon?: string | null
  color?: string | null
}

/**
 * The folder list *and* the note lists it feeds.
 *
 * Changing a rule changes which notes a folder route shows, so leaving those
 * pages cached would show the old matches under the new rules.
 */
function invalidateFolders(client: QueryClient): void {
  void client.invalidateQueries({ queryKey: queryKeys.smartFolders })
  void client.invalidateQueries({ queryKey: queryKeys.notes.all })
}

export function useCreateSmartFolder() {
  const client = useQueryClient()

  return useMutation<SmartFolderNode, ApiError, SmartFolderInput>({
    mutationFn: (input) => api.post<SmartFolderNode>('/smart-folders', input),
    onSuccess: () => invalidateFolders(client),
  })
}

export function useUpdateSmartFolder() {
  const client = useQueryClient()

  return useMutation<SmartFolderNode, ApiError, SmartFolderInput & { id: string }>({
    mutationFn: ({ id, ...patch }) => api.patch<SmartFolderNode>(`/smart-folders/${id}`, patch),
    onSuccess: () => invalidateFolders(client),
  })
}

export function useDeleteSmartFolder() {
  const client = useQueryClient()

  return useMutation<void, ApiError, string>({
    mutationFn: (id) => api.delete(`/smart-folders/${id}`),
    onSuccess: () => invalidateFolders(client),
  })
}

/** How a match count is written, cap included. */
export function formatMatchCount(folder: SmartFolderNode): string | null {
  if (folder.rules_valid === false || folder.note_count === undefined || folder.note_count === null) return null

  return folder.note_count_is_capped ? `${COUNT_CAP}+` : String(folder.note_count)
}
