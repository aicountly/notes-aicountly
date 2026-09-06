/**
 * The deployment's capabilities, written out for a person.
 *
 * `GET /api/config` answers a map of eleven booleans. On its own that is a
 * developer's answer to a user's question, so each flag gets two sentences
 * here: what having it means, and what its absence means. The "off" sentence is
 * the one that matters — someone reading this page is usually looking for
 * something they cannot find, and "not configured on this server" is a better
 * answer than a missing menu item.
 *
 * Every claim below is what the server actually does with the flag (see
 * `server-php/src/Features.php` and the services that call `Features::require`).
 * Nothing here describes a capability the API does not have.
 */

import { useQuery } from '@tanstack/react-query'

import { fetchAppConfig } from '../../shared/api/client'
import { queryKeys } from '../../shared/query/queryClient'
import type { FeatureFlags } from '../../shared/api/types'

export interface FeatureRow {
  flag: keyof FeatureFlags
  label: string
  /** What this deployment can do because the flag is on. */
  on: string
  /** What is absent because it is off. */
  off: string
}

export const FEATURE_ROWS: FeatureRow[] = [
  {
    flag: 'ai',
    label: 'Pulse',
    on: 'Ask questions of your notes, summarise, rewrite and pull out actions. Answers cite the notes they came from.',
    off: 'Pulse is not enabled on this deployment, so nothing in Notes sends your writing to a model.',
  },
  {
    flag: 'semantic_search',
    label: 'Search by meaning',
    on: 'Search also matches notes that mean the same thing, not only the words you typed.',
    off: 'Search matches words and phrases. It still searches everything you can read.',
  },
  {
    flag: 'ocr',
    label: 'Text in images',
    on: 'Photos and scans are read for text, so what is written in them turns up in search.',
    off: 'Images are stored and shown, but the words inside them are not searchable.',
  },
  {
    flag: 'transcription',
    label: 'Voice transcription',
    on: 'Voice notes and meeting recordings are turned into text you can search and edit.',
    off: 'Audio is stored and played back, but not transcribed.',
  },
  {
    flag: 'realtime',
    label: 'Live collaboration',
    on: 'Edits and presence from other people on a shared note arrive as they happen.',
    off: 'Shared notes still work; you will see other people’s changes when the note is reloaded.',
  },
  {
    flag: 'canvas',
    label: 'Canvas notes',
    on: 'Freeform canvas notes, alongside documents and checklists.',
    off: 'Canvas notes cannot be created here. Existing ones are still listed.',
  },
  {
    flag: 'private_notes',
    label: 'Private notes',
    on: 'A note can be marked private: it is left out of retrieval, so Pulse never reads it — and out of search results, including your own.',
    off: 'Notes cannot be marked private on this deployment.',
  },
  {
    flag: 'drive',
    label: 'Drive attachments',
    on: 'Attach files that live in Drive, and link a note to a file already there.',
    off: 'Attachments are uploaded to this server instead.',
  },
  {
    flag: 'calendar',
    label: 'Calendar',
    on: 'Meeting notes can be linked to an event in Calendar.',
    off: 'Meeting notes work, without a link to a calendar entry.',
  },
  {
    flag: 'contacts',
    label: 'Contacts',
    on: 'People mentioned in a note can be linked to their entry in Contacts.',
    off: 'Mentions stay as plain names.',
  },
  {
    flag: 'connect',
    label: 'Other AICOUNTLY apps',
    on: 'Notes can link out to the other products in this suite.',
    off: 'Notes runs on its own here.',
  },
]

/** The one-line explanation for a capability, given the deployment's answer. */
export function describeFeature(flag: keyof FeatureFlags, enabled: boolean): string {
  const row = FEATURE_ROWS.find((entry) => entry.flag === flag)
  if (!row) return enabled ? 'Enabled on this deployment.' : 'Not enabled on this deployment.'

  return enabled ? row.on : row.off
}

// ---------------------------------------------------------------------------
// Has the server actually answered?
// ---------------------------------------------------------------------------

export interface DeploymentAnswer {
  /** The server has not said yet, so nothing derived from the flags is a fact. */
  pending: boolean
  /** Why the question could not be answered, or null. */
  error: unknown
  /** Ask again. */
  retry: () => void
}

/**
 * The state of `GET /config`, as opposed to its contents.
 *
 * `useAppConfig()` hands back an all-off fallback while the request is in
 * flight and again when it fails. That is the right default for *hiding* a
 * control — a Pulse button that 503s on click is worse than no Pulse button —
 * and exactly the wrong one for the two pages whose job is to report what the
 * server said. Rendering eleven "Off" rows, an environment of "unknown" and a
 * 30-day retention nobody configured turns a client-side fallback into a claim
 * about someone's deployment, and the most likely reason for it is that the
 * reader is offline.
 *
 * This subscribes to the query the provider already owns — same key, and
 * `fetchAppConfig` memoises its promise — so it reports that one request's
 * state without issuing a second.
 */
export function useDeploymentAnswer(): DeploymentAnswer {
  const query = useQuery({
    queryKey: queryKeys.config,
    queryFn: fetchAppConfig,
    staleTime: Infinity,
  })

  return {
    pending: query.isPending,
    error: query.isError ? query.error : null,
    retry: () => void query.refetch(),
  }
}
