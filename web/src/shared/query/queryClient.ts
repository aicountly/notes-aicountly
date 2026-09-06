/**
 * TanStack Query configuration.
 *
 * The defaults here encode two product decisions:
 *
 *   1. **Notes are cheap to refetch and expensive to get wrong.** A short stale
 *      time keeps a second tab or a collaborator's edit from lingering, but the
 *      cache is kept long enough that navigating back to a note is instant.
 *   2. **Never retry a write.** A retried POST /notes is a duplicate note. Reads
 *      retry; mutations do not — the offline queue is what makes a failed write
 *      survive, not a retry loop.
 */

import { QueryClient } from '@tanstack/react-query'
import { ApiError } from '../api/client'

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 15 * 60_000,
      // Refetching on every window focus makes the list flicker while someone
      // alt-tabs between a note and a source document.
      refetchOnWindowFocus: false,
      refetchOnReconnect: true,
      retry: (failureCount, error) => {
        if (error instanceof ApiError) {
          // 4xx will not become a 2xx by asking again. Offline is handled by the
          // sync engine, not by hammering a dead connection.
          if (error.status >= 400 && error.status < 500) return false
          if (error.isOffline) return false
        }
        return failureCount < 2
      },
      retryDelay: (attempt) => Math.min(1000 * 2 ** attempt, 8000),
    },
    mutations: {
      retry: false,
    },
  },
})

/**
 * Query keys, in one place.
 *
 * Invalidation is only correct if the key that reads and the key that
 * invalidates are the same string, and the way that goes wrong is two files
 * each writing their own array literal.
 */
export const queryKeys = {
  config: ['config'] as const,
  session: ['session'] as const,

  notes: {
    all: ['notes'] as const,
    list: (filters: Record<string, unknown>) => ['notes', 'list', filters] as const,
    detail: (id: string) => ['notes', 'detail', id] as const,
    counts: ['notes', 'counts'] as const,
    versions: (id: string) => ['notes', id, 'versions'] as const,
    links: (id: string) => ['notes', id, 'links'] as const,
    activity: (id: string) => ['notes', id, 'activity'] as const,
    comments: (id: string) => ['notes', id, 'comments'] as const,
    members: (id: string) => ['notes', id, 'members'] as const,
    attachments: (id: string) => ['notes', id, 'attachments'] as const,
    actions: (id: string) => ['notes', id, 'actions'] as const,
    meeting: (id: string) => ['notes', id, 'meeting'] as const,
  },

  notebooks: ['notebooks'] as const,
  tags: ['tags'] as const,
  templates: ['templates'] as const,
  smartFolders: ['smart-folders'] as const,
  smartFolderNotes: (id: string) => ['smart-folders', id, 'notes'] as const,
  reminders: ['reminders'] as const,
  openActions: ['actions', 'open'] as const,
  search: (query: string, filters: Record<string, unknown>) => ['search', query, filters] as const,
  pulseActions: ['pulse', 'actions'] as const,
} as const
