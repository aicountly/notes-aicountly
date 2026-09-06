/**
 * Who a note is shared with.
 *
 * Sharing is the act with the largest blast radius in a notes app, so the
 * client mirrors the two rules the server refuses to bend (`ShareService`):
 *
 *   - **`owner` is not grantable.** Only the three roles in
 *     {@link GRANTABLE_ROLES} may be handed out. Sharing widens who can read a
 *     note; it never hands the note over, and there is no endpoint here that
 *     would.
 *   - **Only the owner may change any of it.** `capabilities.manage_members`
 *     says so, and the dialog shows a read-only list to everybody else rather
 *     than a form that 403s on submit.
 *
 * A grant takes effect on the very next request — nothing caches an effective
 * role — so the caches are dropped on every write rather than patched.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { QueryClient, UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { NoteCapabilities, NoteMember, NoteRole } from '../../../shared/api/types'

/** The roles a share may grant. `owner` is absent on purpose. */
export const GRANTABLE_ROLES = ['viewer', 'commenter', 'editor'] as const

export type GrantableRole = (typeof GRANTABLE_ROLES)[number]

export const ROLE_LABEL: Record<NoteRole, string> = {
  owner: 'Owner',
  editor: 'Can edit',
  commenter: 'Can comment',
  viewer: 'Can view',
}

/**
 * One line each.
 *
 * "Commenter" is not a word anybody outside this codebase has a definition
 * for, and a role picker that does not say what it grants is a person guessing
 * about somebody else's access.
 */
export const ROLE_HINT: Record<NoteRole, string> = {
  owner: 'Full access, including sharing and deleting. Sharing cannot hand this over.',
  editor: 'Can change the note and comment on it.',
  commenter: 'Can read the note and leave comments, but cannot change a word of it.',
  viewer: 'Can read the note. Nothing else.',
}

/**
 * A member as `ShareService::present()` actually sends it.
 *
 * The two extra fields are not in the shared wire types yet, so they are
 * optional here and the component treats their absence as "unknown" rather
 * than as "not allowed". Derived from `NoteMember` rather than copied, so a
 * change to the shared type still reaches this file.
 */
export interface NoteMemberRow extends NoteMember {
  /** What this member may do, derived server-side from their role. */
  capabilities?: NoteCapabilities
  updated_at?: string | null
}

/** The name to put on screen. There is no directory to resolve an id against. */
export function memberLabel(member: NoteMemberRow): string {
  return member.display_name ?? member.email ?? member.user_id
}

export function useNoteMembers(noteId: string | null): UseQueryResult<NoteMemberRow[], ApiError> {
  return useQuery<NoteMemberRow[], ApiError>({
    queryKey: queryKeys.notes.members(noteId ?? ''),
    enabled: noteId !== null,
    queryFn: () => api.get<NoteMemberRow[]>(`/notes/${noteId}/members`),
  })
}

/**
 * Everything cached about this note, dropped.
 *
 * Broader than it looks — `queryKeys.notes.all` is the prefix of every note
 * key — and that is the point: a role change decides what the reader may see
 * of the note, its comments and its history, so leaving any of them cached
 * would show access that no longer exists. Only mounted queries actually
 * refetch.
 */
function invalidateSharing(client: QueryClient, noteId: string): void {
  void client.invalidateQueries({ queryKey: queryKeys.notes.members(noteId) })
  void client.invalidateQueries({ queryKey: queryKeys.notes.all })
}

export interface AddMemberInput {
  noteId: string
  /** A portal account id, or the email address that account signs in with. */
  user_id: string
  role: GrantableRole
}

/** POST doubles as "change this person's role" — the same as the sharing dialog does. */
export function useAddNoteMember() {
  const client = useQueryClient()

  return useMutation<NoteMemberRow, ApiError, AddMemberInput>({
    mutationFn: ({ noteId, user_id, role }) =>
      api.post<NoteMemberRow>(`/notes/${noteId}/members`, { user_id, role }),
    onSuccess: (_member, { noteId }) => invalidateSharing(client, noteId),
  })
}

export interface UpdateMemberInput {
  noteId: string
  userId: string
  role: GrantableRole
}

export function useUpdateNoteMemberRole() {
  const client = useQueryClient()

  return useMutation<NoteMemberRow, ApiError, UpdateMemberInput>({
    mutationFn: ({ noteId, userId, role }) =>
      api.patch<NoteMemberRow>(`/notes/${noteId}/members/${encodeURIComponent(userId)}`, { role }),
    onSuccess: (_member, { noteId }) => invalidateSharing(client, noteId),
  })
}

export function useRemoveNoteMember() {
  const client = useQueryClient()

  return useMutation<void, ApiError, { noteId: string; userId: string }>({
    mutationFn: ({ noteId, userId }) =>
      api.delete(`/notes/${noteId}/members/${encodeURIComponent(userId)}`),
    onSuccess: (_result, { noteId }) => invalidateSharing(client, noteId),
  })
}
