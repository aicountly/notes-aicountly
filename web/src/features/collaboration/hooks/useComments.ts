/**
 * Comments on a note.
 *
 * Three things the server decided that this file simply carries (see
 * `CommentService`):
 *
 *   - **A comment is never lost to an edit.** When the block it was anchored
 *     to is rewritten away, the comment comes back flagged `orphaned` with the
 *     text it was written against, and the panel shows it rather than dropping
 *     somebody's words because somebody else edited a paragraph.
 *   - **Threads are one level deep.** A reply carries no replies of its own,
 *     so nothing here recurses.
 *   - **Every comment says what the reader may do to it.** `capabilities` is
 *     per comment, because "may I delete this" depends on who wrote it.
 *
 * The whole note's comments are fetched in one request and filtered in the
 * browser. The server can filter by resolved state, but doing it there would
 * mean a cache entry per filter — and then flipping "show resolved" is a round
 * trip, and a comment resolved in one view is stale in the other.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { NoteComment } from '../../../shared/api/types'

/** What the reader may do to one comment. Mirrors `CommentService::present()`. */
export interface CommentCapabilities {
  edit: boolean
  delete: boolean
  resolve: boolean
  reply: boolean
}

/**
 * A comment as the presenter actually sends it.
 *
 * Derived from the shared `NoteComment` rather than copied. Two differences,
 * both deliberate on the server's side: `note_id` is not sent (the caller
 * asked about a note, so it already knows), and the four fields below are.
 */
export interface CommentThread extends Omit<NoteComment, 'note_id' | 'replies'> {
  is_resolved: boolean
  /** True when the body has been changed since it was written. */
  edited: boolean
  capabilities: CommentCapabilities
  /** Always present, always empty on a reply. */
  replies: CommentThread[]
}

export interface CommentPage {
  threads: CommentThread[]
  total: number
  /** Open threads, counted before any filter — the number the header shows. */
  unresolved: number
  /** True when this note has more comments than one page carries. */
  hasMore: boolean
}

export function useNoteComments(noteId: string | null): UseQueryResult<CommentPage, ApiError> {
  return useQuery<CommentPage, ApiError>({
    queryKey: queryKeys.notes.comments(noteId ?? ''),
    enabled: noteId !== null,
    queryFn: async () => {
      const { data, meta } = await api.getWithMeta<CommentThread[]>(`/notes/${noteId}/comments`)

      return {
        threads: data,
        total: typeof meta.total === 'number' ? meta.total : data.length,
        unresolved:
          typeof meta.unresolved === 'number'
            ? meta.unresolved
            : data.filter((thread) => !thread.is_resolved).length,
        hasMore: Boolean(meta.has_more),
      }
    },
  })
}

/**
 * Threads are re-read rather than patched in place.
 *
 * Resolving a reply resolves its whole thread, and deleting a thread takes its
 * replies with it: both are server-side rules about rows this cache does not
 * hold, so a hand-patched cache would drift from what the note actually says.
 * The activity trail moves with them.
 */
function invalidateComments(
  client: ReturnType<typeof useQueryClient>,
  noteId: string,
): void {
  void client.invalidateQueries({ queryKey: queryKeys.notes.comments(noteId) })
  void client.invalidateQueries({ queryKey: queryKeys.notes.activity(noteId) })
}

export interface CreateCommentInput {
  noteId: string
  body: string
  /** The thread this answers. Absent starts a new one. */
  parentId?: string | null
  /** The block this is about. Absent comments on the note as a whole. */
  blockId?: string | null
  /** What that block said when the comment was written; kept if it is edited away. */
  anchorText?: string | null
}

export function useCreateComment() {
  const client = useQueryClient()

  return useMutation<CommentThread, ApiError, CreateCommentInput>({
    mutationFn: ({ noteId, body, parentId, blockId, anchorText }) =>
      api.post<CommentThread>(`/notes/${noteId}/comments`, {
        body,
        parent_id: parentId ?? null,
        block_id: blockId ?? null,
        anchor_text: anchorText ?? null,
      }),
    onSuccess: (_thread, { noteId }) => invalidateComments(client, noteId),
  })
}

export interface ResolveCommentInput {
  noteId: string
  commentId: string
  resolved: boolean
}

export function useResolveComment() {
  const client = useQueryClient()

  return useMutation<CommentThread, ApiError, ResolveCommentInput>({
    mutationFn: ({ commentId, resolved }) =>
      api.post<CommentThread>(`/comments/${commentId}/${resolved ? 'resolve' : 'reopen'}`),
    onSuccess: (_thread, { noteId }) => invalidateComments(client, noteId),
  })
}

export function useDeleteComment() {
  const client = useQueryClient()

  return useMutation<void, ApiError, { noteId: string; commentId: string }>({
    mutationFn: ({ commentId }) => api.delete(`/comments/${commentId}`),
    onSuccess: (_result, { noteId }) => invalidateComments(client, noteId),
  })
}
