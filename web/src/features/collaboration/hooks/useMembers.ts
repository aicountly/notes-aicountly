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
import { useAuth } from '../../../auth/AuthProvider'
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
 * Every cached note read, dropped.
 *
 * Broader than it looks — `queryKeys.notes.all` is the prefix of every note
 * key — and that is the point: a role change decides what the reader may see
 * of the note, its comments and its history, so leaving any of them cached
 * would show access that no longer exists. Only mounted queries actually
 * refetch.
 */
function invalidateSharing(client: QueryClient): void {
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
    onSuccess: () => invalidateSharing(client),
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
    onSuccess: () => invalidateSharing(client),
  })
}

export function useRemoveNoteMember() {
  const client = useQueryClient()

  return useMutation<void, ApiError, { noteId: string; userId: string }>({
    mutationFn: ({ noteId, userId }) =>
      api.delete(`/notes/${noteId}/members/${encodeURIComponent(userId)}`),
    onSuccess: () => invalidateSharing(client),
  })
}

/**
 * A way to put a name to a user id.
 *
 * Activity rows and comments carry ids and nothing else — deliberately, since
 * a name copied into a row is stale the moment somebody is renamed. The member
 * list is the only place a note holds identities, so it is what everything
 * else resolves against; an id belonging to nobody on the list (somebody whose
 * access was revoked after they wrote a comment) becomes "Someone" rather than
 * a raw id in the middle of a sentence.
 */
export function useMemberNames(noteId: string | null): (userId: string) => string {
  const { profile } = useAuth()
  const members = useNoteMembers(noteId)

  return (userId: string): string => {
    if (profile !== null && profile.user_id === userId) return 'You'

    const member = members.data?.find((candidate) => candidate.user_id === userId)

    return member ? memberLabel(member) : 'Someone'
  }
}
