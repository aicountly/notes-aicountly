/**
 * Talking to Pulse.
 *
 * Everything here assumes the caller has already checked `useFeature('ai')`.
 * That check belongs in the component, because the honest response to Pulse
 * being off is *not rendering the control* — a hook that returned a friendly
 * error would still leave a button on screen for a capability this deployment
 * does not have.
 *
 * Three things this file insists on:
 *
 *   - **A question is cancellable.** These requests take seconds, and a person
 *     who has changed their mind must be able to stop one rather than watch a
 *     spinner they no longer care about. React Query threads an abort signal
 *     into a *query* but not into a mutation, so each call owns an
 *     `AbortController` and {@link wasCancelled} tells a cancelled request
 *     apart from a failed one — an abort is not an error to show anybody.
 *   - **The action catalogue comes from the server.** `GET /pulse/actions` is
 *     deliberately not feature-gated and reports `enabled` per action, so the
 *     menu is built from what the deployment says it has rather than from a
 *     list in the browser that drifts from it.
 *   - **A failure keeps the server's own words.** `FEATURE_DISABLED` and
 *     `RATE_LIMITED` are the two Pulse produces most, and both say something
 *     specific; replacing either with "Something went wrong" throws away the
 *     only part of the response the user could have acted on.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import type { UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import { ERROR_CODES } from '../../../shared/api/types'
import type { PulseActionDefinition, PulseAnswer } from '../../../shared/api/types'
import type { IconName } from '../../../shared/ui/Icon'

/** What a question is answered from. Mirrors the endpoint it is sent to. */
export type PulseScope = 'note' | 'notebook' | 'notes'

export interface PulseQuestion {
  scope: PulseScope
  /** Required for `note`; ignored otherwise. */
  noteId?: string | null
  /** Required for `notebook`; narrows a `notes` question when given. */
  notebookId?: string | null
  question: string
}

/**
 * An answer, with the question that produced it.
 *
 * Kept together because the panel is a thread: an answer three turns up is
 * unreadable without the question it belongs to.
 */
export interface PulseTurn {
  id: string
  scope: PulseScope
  /** "This note", "Marketing", "All my notes" — what was actually searched. */
  scopeLabel: string
  question: string
  answer: PulseAnswer
}

/**
 * The rest of what `NotesAIService::answer()` sends.
 *
 * `PulseAnswer` in the wire types names the half every caller needs — the
 * prose, the citations and whether it was grounded. A note action also comes
 * back with the shape it promised in the catalogue and, for a checklist or a
 * table, the parsed payload behind the prose. Declared here rather than in
 * `shared/api/types` because only this feature reads them; the fields are
 * optional so a server that omits one still renders.
 */
export interface PulseActionResult extends PulseAnswer {
  action?: string
  output?: PulseActionDefinition['output']
  data?: unknown
  /** True when `{"save": true}` was sent and the server wrote the result down. */
  saved?: boolean
}

export interface PulseChecklistItem {
  text: string
  due_at?: string | null
  assignee?: string | null
  priority?: string | null
}

export interface PulseTableData {
  columns: string[]
  rows: string[][]
}

/** True when this rejection is a cancelled request rather than a failure. */
export function wasCancelled(error: unknown): boolean {
  // Not `instanceof DOMException`: the abort arrives from whichever realm the
  // fetch was made in, and a cross-realm DOMException fails that test while
  // still being the abort we mean.
  return typeof error === 'object' && error !== null && (error as { name?: unknown }).name === 'AbortError'
}

function turnId(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
}

// ---------------------------------------------------------------------------
// The catalogue
// ---------------------------------------------------------------------------

/**
 * What Pulse can be asked to do.
 *
 * Cached for the session: the catalogue changes when someone edits the
 * server's configuration, not while a note is open. The signal is React
 * Query's — a menu closed before the list arrives should not leave a request
 * running behind it.
 */
export function usePulseActions(enabled = true): UseQueryResult<PulseActionDefinition[], ApiError> {
  return useQuery<PulseActionDefinition[], ApiError>({
    queryKey: queryKeys.pulseActions,
    enabled,
    staleTime: 10 * 60_000,
    queryFn: ({ signal }) => api.get<PulseActionDefinition[]>('/pulse/actions', { signal }),
  })
}

/** Group ids the registry ships with, in menu order, with names for people. */
const GROUP_LABELS: Record<string, string> = {
  understand: 'Understand',
  write: 'Write',
  transform: 'Turn into',
  extract: 'Pull out',
  meeting: 'Meetings',
  connect: 'Connect',
  ask: 'Ask',
}

export interface PulseActionGroup {
  id: string
  label: string
  actions: PulseActionDefinition[]
}

/**
 * The catalogue grouped for a menu, in the order the server sent it.
 *
 * A group id this release has no name for keeps its own id rather than being
 * dropped: a newer server adding a group should show a slightly plain heading
 * here, not lose six actions.
 */
export function groupPulseActions(actions: PulseActionDefinition[]): PulseActionGroup[] {
  const groups: PulseActionGroup[] = []

  for (const action of actions) {
    const existing = groups.find((group) => group.id === action.group)
    if (existing) {
      existing.actions.push(action)
      continue
    }
    groups.push({ id: action.group, label: GROUP_LABELS[action.group] ?? action.group, actions: [action] })
  }

  return groups
}

/**
 * The one action that cannot run on its own.
 *
 * `NotesAIService::taskFor()` answers 422 for a translation with no target
 * language, so a menu that fired this one straight off would be a button that
 * always fails. The catalogue does not describe an action's options, so this
 * single id is known here; adding an `options` field to `GET /pulse/actions`
 * would let even that come from the server.
 */
export const ACTION_NEEDS_LANGUAGE = 'translate'

// ---------------------------------------------------------------------------
// Failures
// ---------------------------------------------------------------------------

export interface PulseFailure {
  /** The server's own sentence wherever it sent one. */
  message: string
  /** What to do about it, when that is not obvious from the message. */
  detail: string | null
  icon: IconName
  /** True when asking again is a reasonable thing to offer. */
  retryable: boolean
}

function retryAfterSeconds(error: ApiError): number | null {
  const value = error.details.retry_after

  return typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.ceil(value) : null
}

function inWords(seconds: number): string {
  if (seconds < 60) return `${seconds} second${seconds === 1 ? '' : 's'}`
  const minutes = Math.ceil(seconds / 60)

  return `${minutes} minute${minutes === 1 ? '' : 's'}`
}

/**
 * A failure, in the words of whatever produced it.
 *
 * A cancellation is not a failure and returns null — the user stopped the
 * request, they do not need telling that it stopped.
 */
export function describePulseFailure(error: unknown): PulseFailure | null {
  if (error === null || error === undefined || wasCancelled(error)) return null

  if (!(error instanceof ApiError)) {
    return {
      message: 'Something went wrong. Please try again.',
      detail: null,
      icon: 'alert',
      retryable: true,
    }
  }

  if (error.isOffline) {
    return {
      message: error.message,
      detail: 'Pulse needs the server; nothing was sent.',
      icon: 'cloud-off',
      retryable: true,
    }
  }

  if (error.code === ERROR_CODES.featureDisabled) {
    return {
      message: error.message,
      // The flags are read once at start-up, so this is what a mid-session
      // switch-off looks like. Retrying would only produce it again.
      detail: 'Pulse was switched off on this deployment. Reload to pick up the change.',
      icon: 'pulse',
      retryable: false,
    }
  }

  if (error.code === ERROR_CODES.rateLimited) {
    const seconds = retryAfterSeconds(error)

    return {
      message: error.message,
      detail: seconds === null ? null : `Pulse is free again in about ${inWords(seconds)}.`,
      icon: 'alert',
      retryable: false,
    }
  }

  return { message: error.message, detail: null, icon: 'alert', retryable: error.status >= 500 }
}

// ---------------------------------------------------------------------------
// Asking
// ---------------------------------------------------------------------------

function questionEndpoint(input: PulseQuestion): { path: string; body: Record<string, unknown> } {
  switch (input.scope) {
    case 'note':
      return {
        path: `/pulse/note/${input.noteId}/ask`,
        body: { action: 'ask_note', question: input.question },
      }
    case 'notebook':
      return { path: `/pulse/notebook/${input.notebookId}/ask`, body: { question: input.question } }
    case 'notes':
      return {
        path: '/pulse/notes/ask',
        body: { question: input.question, notebook_id: input.notebookId ?? undefined },
      }
  }
}

export interface CancellableMutation<TInput, TResult> {
  run: (input: TInput) => Promise<TResult>
  cancel: () => void
  reset: () => void
  isPending: boolean
  /** Never a cancellation — an aborted request is cleared rather than reported. */
  error: unknown
}

/**
 * One cancellable POST.
 *
 * Both callers below want the same three things — a request that carries an
 * abort signal, a `cancel()` that fires it, and an error that is null when the
 * user was the one who stopped it — so they share this rather than each
 * keeping their own controller and each getting the abort handling slightly
 * different.
 */
function useCancellablePost<TInput>(
  toRequest: (input: TInput) => { path: string; body: Record<string, unknown> },
): CancellableMutation<TInput, PulseActionResult> {
  const controller = useRef<AbortController | null>(null)

  const mutation = useMutation<PulseActionResult, unknown, TInput>({
    mutationFn: async (input) => {
      const abort = new AbortController()
      controller.current = abort
      const { path, body } = toRequest(input)

      try {
        return await api.post<PulseActionResult>(path, body, { signal: abort.signal })
      } finally {
        controller.current = null
      }
    },
  })

  const cancel = useCallback(() => {
    controller.current?.abort()
    controller.current = null
  }, [])

  return {
    run: mutation.mutateAsync,
    cancel,
    reset: mutation.reset,
    isPending: mutation.isPending,
    error: wasCancelled(mutation.error) ? null : mutation.error,
  }
}

/**
 * Ask a question of a note, a notebook, or everything the user can read.
 *
 * The error type is `unknown` rather than `ApiError` because an abort arrives
 * as a `DOMException`, and typing it away would only mean casting it back.
 */
export function usePulseAsk(): CancellableMutation<PulseQuestion, PulseActionResult> {
  return useCancellablePost<PulseQuestion>(questionEndpoint)
}

export interface PulseActionRequest {
  noteId: string
  /** An action id from the catalogue. */
  action: string
  /** Target language, for the one action that takes one. */
  language?: string
}

/**
 * Run a catalogue action against a whole note.
 *
 * `POST /pulse/note/{id}/ask` takes any action the registry marks as working on
 * a selection or on a note, which is why there is one endpoint here rather than
 * one per action — the same reason the server has one code path to its
 * provider.
 */
export function usePulseNoteAction(): CancellableMutation<PulseActionRequest, PulseActionResult> {
  return useCancellablePost<PulseActionRequest>(({ noteId, action, language }) => ({
    path: `/pulse/note/${noteId}/ask`,
    body: { action, language },
  }))
}

// ---------------------------------------------------------------------------
// Reading the answer
// ---------------------------------------------------------------------------

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * The checklist behind a checklist answer.
 *
 * `NotesAIService::shape()` puts the parsed items in `data.items` and leaves
 * the prose in `answer`, so a model whose JSON did not parse still produces
 * something readable. Anything that is not a list of objects with text is
 * treated as "no items" and the prose is shown instead.
 */
export function checklistItems(result: PulseActionResult): PulseChecklistItem[] {
  if (!isRecord(result.data) || !Array.isArray(result.data.items)) return []

  const items: PulseChecklistItem[] = []
  for (const entry of result.data.items) {
    if (!isRecord(entry) || typeof entry.text !== 'string' || entry.text.trim() === '') continue

    items.push({
      text: entry.text,
      due_at: typeof entry.due_at === 'string' ? entry.due_at : null,
      assignee: typeof entry.assignee === 'string' ? entry.assignee : null,
      priority: typeof entry.priority === 'string' ? entry.priority : null,
    })
  }

  return items
}

/** The table behind a table answer, or null when the model did not produce one. */
export function tableData(result: PulseActionResult): PulseTableData | null {
  if (!isRecord(result.data)) return null

  const { columns, rows } = result.data
  if (!Array.isArray(columns) || !Array.isArray(rows) || columns.length === 0) return null

  return {
    columns: columns.map((column) => String(column)),
    rows: rows
      .filter((row): row is unknown[] => Array.isArray(row))
      .map((row) => row.map((cell) => String(cell))),
  }
}

// ---------------------------------------------------------------------------
// Waiting
// ---------------------------------------------------------------------------

/**
 * How long the request in flight has been running.
 *
 * There is no streaming endpoint, so there is no honest percentage to draw.
 * A counting clock is the truth: it says the app is still waiting and roughly
 * how long for, which is what tells somebody whether to keep waiting or press
 * Cancel. A progress bar that filled itself on a timer would be a lie.
 */
export function useElapsedSeconds(running: boolean): number {
  const [seconds, setSeconds] = useState(0)

  useEffect(() => {
    if (!running) {
      setSeconds(0)
      return undefined
    }

    const startedAt = Date.now()
    const timer = window.setInterval(() => setSeconds(Math.floor((Date.now() - startedAt) / 1000)), 1000)

    return () => window.clearInterval(timer)
  }, [running])

  return seconds
}

/** A new turn for the thread. Exported so the panel does not mint ids itself. */
export function newTurn(question: PulseQuestion, scopeLabel: string, answer: PulseAnswer): PulseTurn {
  return { id: turnId(), scope: question.scope, scopeLabel, question: question.question, answer }
}
