/**
 * Pulse, on a selection.
 *
 * Only ever reached when the deployment reports `features.ai` — the toolbar
 * entry does not exist otherwise. Inside, the *individual* actions come from
 * `GET /pulse/actions`, and one the server reports as disabled is rendered
 * disabled with the reason rather than hidden: the catalogue is a promise that
 * the capability exists, and quietly dropping half of it would leave the user
 * wondering where "Summarise" went.
 *
 * Nothing is written into the note without being asked for. The answer is
 * shown first, and inserting it is a separate, explicit press.
 */

import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'

import { ApiError, api } from '../../shared/api/client'
import { queryKeys } from '../../shared/query/queryClient'
import { Button, Dialog, EmptyState, LiveStatus, Skeleton } from '../../shared/ui/primitives'
import type { PulseActionDefinition, PulseAnswer } from '../../shared/api/types'
import './editor.css'

export interface PulseSelectionDialogProps {
  open: boolean
  noteId: string
  /** The text the writer had selected. */
  selection: string
  onClose: () => void
  onReplaceSelection: (text: string) => void
  onInsertBelow: (text: string) => void
}

export function PulseSelectionDialog({
  open,
  noteId,
  selection,
  onClose,
  onReplaceSelection,
  onInsertBelow,
}: PulseSelectionDialogProps) {
  const [answer, setAnswer] = useState<PulseAnswer | null>(null)
  /**
   * Whether the last Copy worked.
   *
   * `navigator.clipboard` is absent on an insecure origin and can be refused
   * by permission, so a bare `writeText()` is a button that does nothing and
   * says nothing. Either outcome is reported.
   */
  const [copied, setCopied] = useState<'yes' | 'no' | null>(null)

  const copy = async (text: string) => {
    try {
      if (!navigator.clipboard) throw new Error('no clipboard')
      await navigator.clipboard.writeText(text)
      setCopied('yes')
    } catch {
      setCopied('no')
    }
  }

  const catalogue = useQuery<PulseActionDefinition[], ApiError>({
    queryKey: queryKeys.pulseActions,
    enabled: open,
    staleTime: 5 * 60_000,
    queryFn: () => api.get<PulseActionDefinition[]>('/pulse/actions'),
  })

  const ask = useMutation<PulseAnswer, ApiError, string>({
    mutationFn: (action) =>
      api.post<PulseAnswer>('/pulse/selection', { note_id: noteId, action, text: selection }),
    onSuccess: (next) => {
      setAnswer(next)
      // A new answer is not the one that was copied a moment ago.
      setCopied(null)
    },
  })

  const actions = (catalogue.data ?? []).filter((action) => action.scope === 'selection')

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Ask Pulse"
      description="Pulse reads the selected text and answers from your own notes."
      width={560}
    >
      <blockquote className="pulse-dialog__selection">{selection}</blockquote>

      {catalogue.isPending ? (
        <div className="pulse-dialog__actions" aria-hidden>
          <Skeleton width={110} height={30} radius={8} />
          <Skeleton width={140} height={30} radius={8} />
          <Skeleton width={96} height={30} radius={8} />
        </div>
      ) : catalogue.error ? (
        <EmptyState
          icon={catalogue.error.isOffline ? 'cloud-off' : 'alert'}
          title={catalogue.error.isOffline ? 'You are offline' : 'Pulse is unavailable'}
          description={catalogue.error.message}
          action={
            <Button icon="refresh" onClick={() => void catalogue.refetch()}>
              Try again
            </Button>
          }
        />
      ) : actions.length === 0 ? (
        <EmptyState icon="pulse" title="No Pulse actions are configured for a selection." />
      ) : (
        <div className="pulse-dialog__actions">
          {actions.map((action) => (
            <Button
              key={action.id}
              variant={action.enabled ? 'secondary' : 'ghost'}
              size="sm"
              disabled={!action.enabled || ask.isPending}
              loading={ask.isPending && ask.variables === action.id}
              title={action.enabled ? action.label : `${action.label} is switched off on this server`}
              onClick={() => ask.mutate(action.id)}
            >
              {action.label}
            </Button>
          ))}
        </div>
      )}

      {ask.error ? (
        <p className="editor-field__error" role="alert">
          {ask.error.message}
        </p>
      ) : null}

      {answer ? (
        <div className="pulse-dialog__answer">
          <p className="pulse-dialog__answer-text">{answer.answer}</p>

          {/* Whether the model had the user's notes to work from is the single
              most useful thing to know about an answer. */}
          <p className="pulse-dialog__grounding">
            {answer.grounded
              ? `Grounded in ${answer.citations.length} note${answer.citations.length === 1 ? '' : 's'} of yours.`
              : 'Answered without using your notes — check it before relying on it.'}
          </p>

          <div className="editor-form__actions">
            <Button icon={copied === 'yes' ? 'check' : 'copy'} onClick={() => void copy(answer.answer)}>
              {copied === 'yes' ? 'Copied' : 'Copy'}
            </Button>
            {copied === 'no' ? (
              <span className="pulse-dialog__grounding" role="alert">
                Copying is not available here — select the text instead.
              </span>
            ) : (
              <LiveStatus>{copied === 'yes' ? 'Answer copied' : ''}</LiveStatus>
            )}
            <span className="editor-form__spacer" />
            <Button onClick={() => onInsertBelow(answer.answer)}>Insert below</Button>
            <Button variant="primary" onClick={() => onReplaceSelection(answer.answer)}>
              Replace selection
            </Button>
          </div>
        </div>
      ) : null}
    </Dialog>
  )
}
