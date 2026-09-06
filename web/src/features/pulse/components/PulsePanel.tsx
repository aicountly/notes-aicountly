/**
 * Asking Pulse a question.
 *
 * The panel is a thread rather than a one-shot box: people ask a follow-up far
 * more often than they ask once, and losing the previous answer to make room
 * for the next one is how a person ends up re-asking what they already know.
 *
 * Three rules it will not bend on:
 *
 *   - **When `features.ai` is off, this renders one sentence and nothing
 *     clickable.** Not a disabled composer, not a teaser. Pulse has no
 *     provider behind it on any deployment today (`docs/PULSE_INTEGRATION.md`),
 *     so the one honest thing to say is that it is not enabled here — and this
 *     panel is exactly where somebody who went looking for Pulse ends up.
 *   - **Every answer shows its sources**, as links to the notes they came from.
 *   - **An answer the server marks `grounded: false` is labelled before it is
 *     read.** The model answered from general knowledge rather than from this
 *     person's notes, and presenting that as sourced is the single most
 *     damaging thing an assistant inside a notes app can do.
 *
 * Streaming is not part of the API, so the wait is honest instead: a line that
 * says what is being read and how long it has taken, and a Cancel that aborts
 * the request rather than hiding it.
 */

import { useId, useState } from 'react'
import type { FormEvent } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import { Button, LiveStatus, Spinner } from '../../../shared/ui/primitives'
import { useFeature } from '../../../app/AppConfigProvider'
import { CitationList } from './CitationList'
import { PulseFailureNotice, PulseUnavailableLine } from './PulseNotice'
import { newTurn, useElapsedSeconds, usePulseAsk } from '../hooks/usePulse'
import type { PulseQuestion, PulseScope, PulseTurn } from '../hooks/usePulse'
import '../pulse.css'

export interface PulsePanelProps {
  /** The note in view. Its presence is what offers "This note". */
  noteId?: string | null
  noteTitle?: string | null
  /** The notebook in view. Its presence is what offers "This notebook". */
  notebookId?: string | null
  notebookName?: string | null
  /** Rendered as a close control when given — the panel does not own its frame. */
  onClose?: () => void
  /** Called when a citation is followed, so a host dialog can get out of the way. */
  onNavigate?: (noteId: string) => void
}

interface ScopeOption {
  scope: PulseScope
  label: string
  /** What the question will actually be answered from. */
  detail: string
}

export function PulsePanel({
  noteId = null,
  noteTitle = null,
  notebookId = null,
  notebookName = null,
  onClose,
  onNavigate,
}: PulsePanelProps) {
  const enabled = useFeature('ai')
  const ask = usePulseAsk()
  const elapsed = useElapsedSeconds(ask.isPending)

  const [thread, setThread] = useState<PulseTurn[]>([])
  const [question, setQuestion] = useState('')
  const [pending, setPending] = useState<string | null>(null)
  // Null until somebody picks: the default is then whichever scope is the
  // narrowest one currently offered, which changes as notes are opened.
  const [scope, setScope] = useState<PulseScope | null>(null)

  const questionId = useId()
  const scopeName = useId()

  const options: ScopeOption[] = [
    ...(noteId
      ? [{ scope: 'note' as const, label: 'This note', detail: noteTitle?.trim() || 'the note you have open' }]
      : []),
    ...(notebookId
      ? [
          {
            scope: 'notebook' as const,
            label: 'This notebook',
            detail: notebookName?.trim() || 'this notebook and everything under it',
          },
        ]
      : []),
    { scope: 'notes', label: 'All my notes', detail: 'everything you are allowed to read' },
  ]

  // The selected scope may stop being offered — the note pane closes, and
  // "This note" goes with it. Resolving against the current options rather
  // than trusting the state is what stops a question going to /pulse/note/null.
  const active = options.find((option) => option.scope === scope) ?? options[0]

  // The whole capability is absent on this deployment. One sentence, and not a
  // single control that would fail if it were pressed — no panel frame either,
  // because a frame with a heading is furniture for a feature that is not here.
  if (!enabled) return <PulseUnavailableLine className="pulse-off--panel" />


  const submit = (event: FormEvent) => {
    event.preventDefault()

    const asked = question.trim()
    if (asked === '' || ask.isPending) return

    const input: PulseQuestion = { scope: active.scope, noteId, notebookId, question: asked }

    setPending(asked)
    setQuestion('')

    ask
      .run(input)
      .then((answer) => {
        setThread((current) => [...current, newTurn(input, active.label, answer)])
        setPending(null)
      })
      .catch(() => {
        // Cancelled or failed. The question goes back in the box so it does not
        // have to be typed again; the failure itself is rendered from `error`.
        setQuestion(asked)
        setPending(null)
      })
  }

  const latest = thread[thread.length - 1]

  return (
    <section className="pulse-panel" aria-label="Ask Pulse">
      <header className="pulse-panel__header">
        <span className="pulse-panel__mark" aria-hidden>
          <Icon name="pulse" size={16} />
        </span>
        <h2 className="pulse-panel__title">Pulse</h2>
        {onClose ? (
          <Button icon="close" iconOnly variant="ghost" size="sm" aria-label="Close Pulse" onClick={onClose} />
        ) : null}
      </header>

      <div className="pulse-panel__thread">
        {thread.length === 0 && pending === null ? (
          <p className="pulse-panel__intro">
            Ask a question and Pulse answers from {active.detail}. Every answer lists the notes it used, so you
            can check it.
          </p>
        ) : null}

        <ol className="pulse-thread">
          {thread.map((turn) => (
            <li className="pulse-turn" key={turn.id}>
              <p className="pulse-turn__question">
                <span className="pulse-turn__scope">{turn.scopeLabel}</span>
                {turn.question}
              </p>

              {/* Said before the answer is read, not after. */}
              {turn.answer.grounded ? null : (
                <p className="pulse-turn__ungrounded">
                  <Icon name="alert" size={14} />
                  Pulse answered from general knowledge, not from your notes.
                </p>
              )}

              <div className="pulse-turn__answer">{renderParagraphs(turn.answer.answer)}</div>

              <CitationList citations={turn.answer.citations} onNavigate={onNavigate} />
            </li>
          ))}
        </ol>

        {pending !== null ? (
          <div className="pulse-progress" role="status">
            <Spinner size={14} />
            <span className="pulse-progress__text">
              Reading {active.label.toLowerCase()} to answer “{pending}” — {elapsed}s
            </span>
            <Button size="sm" variant="ghost" icon="stop" onClick={ask.cancel}>
              Cancel
            </Button>
          </div>
        ) : null}

        <PulseFailureNotice error={ask.error} />
      </div>

      <form className="pulse-compose" onSubmit={submit}>
        <fieldset className="pulse-scope">
          <legend className="pulse-scope__legend">Answer from</legend>
          {options.map((option) => (
            <label className="pulse-scope__option" key={option.scope}>
              <input
                type="radio"
                name={scopeName}
                value={option.scope}
                checked={active.scope === option.scope}
                disabled={ask.isPending}
                onChange={() => setScope(option.scope)}
              />
              <span>{option.label}</span>
            </label>
          ))}
        </fieldset>

        <label className="sr-only" htmlFor={questionId}>
          Your question
        </label>
        <textarea
          id={questionId}
          className="pulse-compose__input"
          rows={2}
          placeholder={`Ask ${active.label.toLowerCase()}…`}
          value={question}
          disabled={ask.isPending}
          onChange={(event) => setQuestion(event.target.value)}
          onKeyDown={(event) => {
            // Enter sends, Shift+Enter makes a new line — the convention every
            // message box in this suite uses.
            if (event.key === 'Enter' && !event.shiftKey) {
              event.preventDefault()
              event.currentTarget.form?.requestSubmit()
            }
          }}
        />

        <div className="pulse-compose__bar">
          <p className="pulse-compose__hint">Answers come from {active.detail}.</p>
          <Button
            type="submit"
            variant="primary"
            icon="pulse"
            size="sm"
            loading={ask.isPending}
            disabled={question.trim() === ''}
          >
            Ask
          </Button>
        </div>
      </form>

      <LiveStatus>
        {latest
          ? latest.answer.grounded
            ? `Pulse answered from ${latest.answer.citations.length} of your notes.`
            : 'Pulse answered from general knowledge, not from your notes.'
          : ''}
      </LiveStatus>
    </section>
  )
}

/**
 * The answer as paragraphs.
 *
 * Split on blank lines and rendered as React children, so the text is escaped
 * by construction. Model output is never markup here: an assistant that could
 * put HTML into the page is an assistant that can be talked into putting a link
 * there by a note it read.
 */
function renderParagraphs(text: string) {
  const paragraphs = text.split(/\n{2,}/).filter((paragraph) => paragraph.trim() !== '')

  return (paragraphs.length > 0 ? paragraphs : [text]).map((paragraph, index) => (
    <p key={index}>{paragraph}</p>
  ))
}
