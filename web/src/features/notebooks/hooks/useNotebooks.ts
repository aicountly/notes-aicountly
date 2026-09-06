/**
 * Reading and reshaping the notebook tree.
 *
 * Three things worth knowing, because they are the difference between a tree
 * that feels solid and one that argues with the server:
 *
 *   - **The tree is one query.** `GET /notebooks` returns every notebook the
 *     caller can see, already nested and already counted, so the sidebar
 *     renders from one cache entry instead of one request per level.
 *   - **Archived notebooks are a second query, not a filter.** They are a
 *     different list from the server's point of view (`include_archived`), and
 *     giving them their own key under `notebooks` means invalidating the
 *     section still refreshes both.
 *   - **Nothing here queues offline.** A notebook is shared structure: applying
 *     a rename or a delete an hour later, against a tree someone else has since
 *     moved, is worse than saying "you are offline" now. Notes queue; places do
 *     not.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { QueryClient, UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { Notebook, NoteMember, NoteRole } from '../../../shared/api/types'

/**
 * How deep the tree may go, counted in levels.
 *
 * Mirrors `NotebookService::MAX_DEPTH`. Held here as well so the UI can
 * disable "New sub-notebook" at the bottom of the tree instead of offering it
 * and letting the server refuse.
 */
export const NOTEBOOK_MAX_DEPTH = 8

/** The deepest `depth` a stored notebook can have. */
export const NOTEBOOK_MAX_LEVEL = NOTEBOOK_MAX_DEPTH - 1

/**
 * What the caller may do with one notebook.
 *
 * The server sends this on every node (`NotebookService::present`). It is not
 * in the shared wire types yet, so it is optional here and derived from `role`
 * when absent — see {@link notebookCapabilities}.
 */
export interface NotebookCapabilities {
  view: boolean
  edit: boolean
  add_notes: boolean
  move: boolean
  /**
   * Archiving and reordering are listed apart from `edit` because the server
   * lists them apart: archiving hides the branch from everyone it is shared
   * with, and reordering renumbers siblings the caller may not be able to see.
   * Both are owner-only. A UI that gated them on `edit` would offer an editor
   * two controls that answer 403.
   */
  archive: boolean
  reorder: boolean
  delete: boolean
  manage_members: boolean
}

/** A tree node, with the fields the presenter sends beyond {@link Notebook}. */
export interface NotebookNode extends Notebook {
  /** Partial on purpose: a deployment older than a capability simply omits it. */
  capabilities?: Partial<NotebookCapabilities>
  /** This notebook's notes plus every descendant's. */
  total_note_count?: number
  owner_user_id?: string
  children: NotebookNode[]
}

const ROLE_RANK: Record<NoteRole, number> = { viewer: 1, commenter: 2, editor: 3, owner: 4 }

/**
 * What this user may do here — the server's answer, or the same rules applied
 * to `role` when an older server omits it.
 */
export function notebookCapabilities(notebook: NotebookNode): NotebookCapabilities {
  const rank = ROLE_RANK[notebook.role] ?? 0
  const owner = notebook.role === 'owner'

  const fromRole: NotebookCapabilities = {
    view: true,
    edit: rank >= ROLE_RANK.editor,
    add_notes: rank >= ROLE_RANK.editor,
    move: owner,
    archive: owner,
    reorder: owner,
    delete: owner,
    manage_members: owner,
  }

  // The server's answer wins for every key it sends, and the role-derived rules
  // fill the rest. Merging rather than choosing matters: a server that predates
  // one of these keys would otherwise hand back `undefined` for it, and a
  // control gated on that key would be dead for the owner who may use it.
  return { ...fromRole, ...notebook.capabilities }
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

/**
 * The whole visible tree.
 *
 * `includeArchived` gets its own cache entry rather than being filtered out of
 * one, because the two really are different responses; both sit under the
 * `notebooks` key so one invalidation refreshes whichever is mounted.
 */
export function useNotebooks(includeArchived = false): UseQueryResult<NotebookNode[], ApiError> {
  return useQuery<NotebookNode[], ApiError>({
    queryKey: includeArchived ? [...queryKeys.notebooks, 'archived'] : queryKeys.notebooks,
    queryFn: () =>
      api.get<NotebookNode[]>('/notebooks', {
        query: { include_archived: includeArchived ? true : undefined },
      }),
    staleTime: 60_000,
  })
}

export function useNotebookMembers(notebookId: string | null): UseQueryResult<NoteMember[], ApiError> {
  return useQuery<NoteMember[], ApiError>({
    queryKey: [...queryKeys.notebooks, notebookId ?? '', 'members'],
    enabled: notebookId !== null,
    queryFn: () => api.get<NoteMember[]>(`/notebooks/${notebookId}/members`),
  })
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

export interface CreateNotebookInput {
  name: string
  parent_id?: string | null
  description?: string | null
  icon?: string | null
  color?: string | null
}

export interface UpdateNotebookInput {
  id: string
  name?: string
  description?: string | null
  icon?: string | null
  color?: string | null
  is_archived?: boolean
}

/**
 * What deleting a notebook did.
 *
 * The server answers with a summary rather than 204 because the notes that
 * were inside have gone somewhere, and the user is owed a sentence saying
 * where. See `NotebookService::delete()`.
 */
export interface NotebookDeleteResult {
  id: string
  deleted: boolean
  moved_to_notebook_id: string | null
  notes_moved: number
  notebooks_moved: number
  message: string
}

/**
 * Both notebook lists, and the note lists a notebook change moves rows between.
 *
 * Deleting a notebook re-files every note inside it, so refreshing the tree
 * alone would leave the middle column showing notes in a notebook that is gone.
 */
function invalidateOrganisation(client: QueryClient): void {
  void client.invalidateQueries({ queryKey: queryKeys.notebooks })
  void client.invalidateQueries({ queryKey: queryKeys.notes.all })
}

export function useCreateNotebook() {
  const client = useQueryClient()

  return useMutation<NotebookNode, ApiError, CreateNotebookInput>({
    mutationFn: (input) => api.post<NotebookNode>('/notebooks', input),
    onSuccess: () => invalidateOrganisation(client),
  })
}

export function useUpdateNotebook() {
  const client = useQueryClient()

  return useMutation<NotebookNode, ApiError, UpdateNotebookInput>({
    mutationFn: ({ id, ...patch }) => api.patch<NotebookNode>(`/notebooks/${id}`, patch),
    onSuccess: () => invalidateOrganisation(client),
  })
}

export function useMoveNotebook() {
  const client = useQueryClient()

  return useMutation<NotebookNode, ApiError, { id: string; parent_id: string | null }>({
    mutationFn: ({ id, parent_id }) => api.post<NotebookNode>(`/notebooks/${id}/move`, { parent_id }),
    onSuccess: () => invalidateOrganisation(client),
  })
}

export function useDeleteNotebook() {
  const client = useQueryClient()

  return useMutation<NotebookDeleteResult, ApiError, string>({
    mutationFn: (id) => api.delete<NotebookDeleteResult>(`/notebooks/${id}`),
    onSuccess: () => invalidateOrganisation(client),
  })
}

export function useAddNotebookMember() {
  const client = useQueryClient()

  return useMutation<NoteMember, ApiError, { id: string; user_id: string; role: NoteRole }>({
    mutationFn: ({ id, user_id, role }) => api.post<NoteMember>(`/notebooks/${id}/members`, { user_id, role }),
    onSuccess: () => invalidateOrganisation(client),
  })
}

export function useRemoveNotebookMember() {
  const client = useQueryClient()

  return useMutation<void, ApiError, { id: string; userId: string }>({
    mutationFn: ({ id, userId }) => api.delete(`/notebooks/${id}/members/${encodeURIComponent(userId)}`),
    onSuccess: () => invalidateOrganisation(client),
  })
}

// ---------------------------------------------------------------------------
// Shape helpers
// ---------------------------------------------------------------------------

export interface NotebookOption {
  id: string
  name: string
  /** Nesting level in the rendered list, which is also the stored `depth`. */
  depth: number
  is_archived: boolean
}

/** The tree as a flat, indented list — what a `<select>` and a picker need. */
export function flattenNotebooks(nodes: NotebookNode[], depth = 0): NotebookOption[] {
  return nodes.flatMap((node) => [
    { id: node.id, name: node.name, depth, is_archived: node.is_archived },
    ...flattenNotebooks(node.children ?? [], depth + 1),
  ])
}

export function findNotebook(nodes: NotebookNode[], id: string | null): NotebookNode | null {
  if (id === null) return null

  for (const node of nodes) {
    if (node.id === id) return node
    const found = findNotebook(node.children ?? [], id)
    if (found) return found
  }

  return null
}

/** How many levels hang below this node — 0 for a leaf. */
export function subtreeHeight(node: NotebookNode): number {
  const children = node.children ?? []
  if (children.length === 0) return 0

  return 1 + Math.max(...children.map(subtreeHeight))
}

/**
 * The ids of everything above a notebook, outermost first.
 *
 * What lets the tree open itself onto the notebook the user is looking at:
 * a row three levels down is not "collapsed", it is missing.
 */
export function notebookAncestors(nodes: NotebookNode[], id: string): string[] {
  // `null` rather than an empty array for "not here": a root notebook has no
  // ancestors, and the two answers must not look the same to the caller.
  const walk = (list: NotebookNode[], trail: string[]): string[] | null => {
    for (const node of list) {
      if (node.id === id) return trail
      const found = walk(node.children ?? [], [...trail, node.id])
      if (found !== null) return found
    }

    return null
  }

  return walk(nodes, []) ?? []
}

/** Every id in this node's subtree, including its own. */
export function subtreeIds(node: NotebookNode): Set<string> {
  const ids = new Set<string>([node.id])
  for (const child of node.children ?? []) {
    for (const id of subtreeIds(child)) ids.add(id)
  }

  return ids
}
