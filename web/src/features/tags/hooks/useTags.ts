/**
 * Reading and editing tags.
 *
 * A tag is a label, not a place: a note can carry many, and they are shared
 * across the whole library. The one rule that shapes everything here is that
 * **the server owns the identity of a tag, and it is case-insensitive**.
 * "GST", "gst" and "#GST" all fold to the slug `gst` and resolve to one row
 * (`Str::tagSlug`, `TagService::resolve`).
 *
 * {@link tagSlug} mirrors that folding in the browser. It is used only to
 * decide whether the user has already typed a tag — never to send a slug where
 * a name belongs — so a picker does not show "gst" and "GST" as two choices
 * and then quietly send one tag. Where the two implementations could ever
 * disagree, the server wins, because it is the one that stores the row.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { QueryClient, UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { Tag } from '../../../shared/api/types'

/** A note may not carry more than this many tags. Mirrors `TagService`. */
export const MAX_TAGS_PER_NOTE = 50

const MAX_TAG_NAME = 80

/**
 * The server's slug rule, in the browser.
 *
 * Lower-cased, whitespace and underscores folded to a hyphen, everything that
 * is not a letter, a number or a hyphen removed. A leading `#` is stripped
 * first, so typing `#gst` inline reaches the same tag as typing `gst`.
 */
export function tagSlug(value: string): string {
  return value
    .trim()
    .replace(/^#+/, '')
    .toLowerCase()
    .replace(/[\s_]+/gu, '-')
    .replace(/[^\p{L}\p{N}-]+/gu, '')
    .replace(/^-+|-+$/g, '')
}

/** What the user typed, cleaned up enough to send as a name. */
export function tagDisplayName(value: string): string {
  return value.trim().replace(/^#+/, '').slice(0, MAX_TAG_NAME)
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

export function useTags(): UseQueryResult<Tag[], ApiError> {
  return useQuery<Tag[], ApiError>({
    queryKey: queryKeys.tags,
    queryFn: () => api.get<Tag[]>('/tags'),
    staleTime: 60_000,
  })
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

/**
 * A tag change moves rows in every list that shows a tag, so the note lists go
 * with it — a rename that left "gst" on forty cached cards would look like it
 * had failed.
 */
function invalidateTags(client: QueryClient): void {
  void client.invalidateQueries({ queryKey: queryKeys.tags })
  void client.invalidateQueries({ queryKey: queryKeys.notes.all })
}

export function useCreateTag() {
  const client = useQueryClient()

  return useMutation<Tag, ApiError, { name: string; color?: string | null }>({
    mutationFn: ({ name, color }) => api.post<Tag>('/tags', { name: tagDisplayName(name), color }),
    onSuccess: () => invalidateTags(client),
  })
}

/**
 * Rename, recolour, or both.
 *
 * Renaming onto a name that already exists is a *merge* on the server, not an
 * error — the two tags become one and the notes keep both sets of labels. The
 * UI says so rather than pretending it is a rename that might fail.
 */
export function useUpdateTag() {
  const client = useQueryClient()

  return useMutation<Tag, ApiError, { id: string; name?: string; color?: string | null }>({
    mutationFn: ({ id, name, color }) =>
      api.patch<Tag>(`/tags/${id}`, {
        ...(name === undefined ? {} : { name: tagDisplayName(name) }),
        ...(color === undefined ? {} : { color }),
      }),
    onSuccess: () => invalidateTags(client),
  })
}

export function useDeleteTag() {
  const client = useQueryClient()

  return useMutation<void, ApiError, string>({
    // The notes keep their content and simply lose the label; `note_tags`
    // cascades, nothing else does.
    mutationFn: (id) => api.delete(`/tags/${id}`),
    onSuccess: () => invalidateTags(client),
  })
}

export function useMergeTags() {
  const client = useQueryClient()

  return useMutation<Tag, ApiError, { sourceIds: string[]; targetId: string }>({
    mutationFn: ({ sourceIds, targetId }) =>
      api.post<Tag>('/tags/merge', { source_ids: sourceIds, target_id: targetId }),
    onSuccess: () => invalidateTags(client),
  })
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Fold a list of typed names to the set the server would store. */
export function uniqueTagNames(names: string[]): string[] {
  const seen = new Set<string>()
  const out: string[] = []

  for (const name of names) {
    const slug = tagSlug(name)
    if (slug === '' || seen.has(slug)) continue
    seen.add(slug)
    out.push(tagDisplayName(name))
  }

  return out.slice(0, MAX_TAGS_PER_NOTE)
}
