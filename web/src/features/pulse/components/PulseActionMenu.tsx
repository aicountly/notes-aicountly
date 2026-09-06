/**
 * Running a Pulse action on a whole note.
 *
 * The menu is **built from `GET /pulse/actions`**, never from a list in the
 * browser. The server owns which actions exist and whether each one is
 * enabled; hardcoding them here would mean a deployment that adds one has to
 * ship a new frontend, and one that removes one shows a button that 503s.
 *
 * With `features.ai` off this renders nothing at all — not a disabled button
 * with a tooltip. A toolbar is not a place to explain a capability that does
 * not exist here; the panel carries that sentence instead, which is where
 * somebody looking for Pulse actually goes.
 *
 * The part that matters most is what happens *after* an action runs: nothing.
 * The result is shown as a preview, and writing it into the note is a separate,
 * deliberate press — Replace, Insert below, or Copy. An assistant that rewrote
 * somebody's note the moment they picked "Improve writing" would be an
 * assistant people learn to be frightened of.
 *
 * Where the user may not edit this note, Replace and Insert are shown disabled
 * with the reason rather than hidden: the answer is still theirs to copy, and
 * silently dropping two of three buttons reads as a bug.
 */

import { Fragment, useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import { Button, Dialog, LiveStatus, Skeleton, Spinner } from '../../../shared/ui/primitives'
import { useFeature } from '../../../app/AppConfigProvider'
import { CitationList } from './CitationList'
import { PulseFailureNotice } from './PulseNotice'
import {
  ACTION_NEEDS_LANGUAGE,
  checklistItems,
  groupPulseActions,
  tableData,
  useElapsedSeconds,
  usePulseActions,
  usePulseNoteAction,
} from '../hooks/usePulse'
import type { PulseActionResult } from '../hooks/usePulse'
import type { NoteCapabilities, PulseActionDefinition } from '../../../shared/api/types'
import '../pulse.css'

export interface PulseActionMenuProps {
  noteId: string
  /** The note's own capabilities. `edit` decides whether a result can be applied. */
  capabilities: NoteCapabilities
  /** Replace the note's body with this text. */
  onReplaceNote: (text: string) => void
  /** Append this text to the end of the note. */
  onInsertBelow: (text: string) => void
}

/** Why an action cannot be run, in the server's own terms. */
function disabledReason(action: PulseActionDefinition): string | undefined {
  return action.enabled ? undefined : 'Switched off on this server'
}

export function PulseActionMenu({ noteId, capabilities, onReplaceNote, onInsertBelow }: PulseActionMenuProps) {
  const enabled = useFeature('ai')

  const [open, setOpen] = useState(false)
  const [picked, setPicked] = useState<PulseActionDefinition | null>(null)
  const [language, setLanguage] = useState('')
  const [result, setResult] = useState<PulseActionResult | null>(null)
  const [copied, setCopied] = useState(false)

  const catalogue = usePulseActions(enabled && open)
  const action = usePulseNoteAction()
  const elapsed = useElapsedSeconds(action.isPending)

  const menuId = useId()
  const languageId = useId()
  const containerRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const popupRef = useRef<HTMLDivElement>(null)

  // Capture phase, so opening another menu closes this one rather than leaving
  // two on screen.
  useEffect(() => {
    if (!open) return undefined

    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('pointerdown', onPointerDown, true)
    return () => document.removeEventListener('pointerdown', onPointerDown, true)
  }, [open])

  useEffect(() => {
    if (open) popupRef.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
  }, [open, catalogue.data])

  if (!enabled) return null

  const close = () => {
    setOpen(false)
    triggerRef.current?.focus()
  }

  const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.stopPropagation()
      close()
      return
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return

    const items = Array.from(popupRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])
    if (items.length === 0) return

    event.preventDefault()
    const at = items.indexOf(document.activeElement as HTMLElement)
    const next = event.key === 'ArrowDown' ? at + 1 : at - 1
    items[(next + items.length) % items.length].focus()
  }

  const run = (definition: PulseActionDefinition, target?: string) => {
    setResult(null)
    setCopied(false)
    action.reset()

    action
      .run({ noteId, action: definition.id, language: target })
      .then(setResult)
      // Cancelled or failed; either way the preview dialog stays open and says
      // which of the two it was.
      .catch(() => undefined)
  }

  const select = (definition: PulseActionDefinition) => {
    if (!definition.enabled) return

    close()
    setLanguage('')
    setPicked(definition)

    // Translation needs a target language before it can run at all, so picking
    // it opens the dialog on a question rather than on a request that would
    // come back 422.
    if (definition.id !== ACTION_NEEDS_LANGUAGE) run(definition)
  }

  const dismiss = () => {
    action.cancel()
    action.reset()
    setPicked(null)
    setResult(null)
    setCopied(false)
  }

  const apply = (write: (text: string) => void, text: string) => {
    write(text)
    dismiss()
  }

  /**
   * The actions that can run against a whole note.
   *
   * `scope` says the smallest input an action needs, and the note endpoint
   * accepts anything sized `selection` or `note`. The `ask` group is left out
   * because those actions need a question to go with them — that is the panel's
   * job, not a menu's.
   */
  const runnable = (catalogue.data ?? []).filter(
    (item) => (item.scope === 'selection' || item.scope === 'note') && item.group !== 'ask',
  )
  const groups = groupPulseActions(runnable)

  const applyBlocked = capabilities.edit ? undefined : 'You have view-only access to this note'
  const awaitingLanguage = picked?.id === ACTION_NEEDS_LANGUAGE && result === null && !action.isPending
  const items = result ? checklistItems(result) : []
  const table = result ? tableData(result) : null

  return (
    <div className="pulse-menu" ref={containerRef}>
      {/* A raw button rather than the primitive, because this one needs a ref
          to put focus back on when the menu closes. */}
      <button
        ref={triggerRef}
        type="button"
        className="btn btn--secondary btn--sm"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => setOpen((current) => !current)}
      >
        <Icon name="pulse" size={15} />
        <span>Pulse</span>
      </button>

      {open ? (
        <div
          ref={popupRef}
          id={menuId}
          className="pulse-menu__popup"
          role="menu"
          aria-label="Pulse actions"
          onKeyDown={onKeyDown}
        >
          {catalogue.isPending ? (
            <div className="pulse-menu__loading" aria-busy>
              <Skeleton width="70%" height={13} />
              <Skeleton width="55%" height={13} />
              <Skeleton width="64%" height={13} />
            </div>
          ) : null}

          {catalogue.isError ? (
            <div className="pulse-menu__notice">
              <PulseFailureNotice error={catalogue.error} onRetry={() => void catalogue.refetch()} />
            </div>
          ) : null}

          {catalogue.isSuccess && groups.length === 0 ? (
            <p className="pulse-menu__empty">Pulse has no actions for a whole note on this server.</p>
          ) : null}

          {groups.map((group) => (
            <Fragment key={group.id}>
              <p className="pulse-menu__group" role="presentation">
                {group.label}
              </p>
              {group.actions.map((item) => {
                const reason = disabledReason(item)

                return (
                  <button
                    key={item.id}
                    type="button"
                    role="menuitem"
                    className="pulse-menu__item"
                    // aria-disabled rather than `disabled`: a disabled button
                    // leaves the tab order, taking the reason with it.
                    aria-disabled={reason !== undefined}
                    onClick={() => select(item)}
                  >
                    <span className="pulse-menu__label">{item.label}</span>
                    {reason ? <span className="pulse-menu__reason">{reason}</span> : null}
                  </button>
                )
              })}
            </Fragment>
          ))}
        </div>
      ) : null}

      {picked ? (
        <Dialog
          open
          onClose={dismiss}
          title={picked.label}
          description="Nothing is written into your note until you choose to."
          width={620}
          footer={
            <>
              <Button onClick={dismiss}>{result ? 'Discard' : 'Cancel'}</Button>

              {awaitingLanguage ? (
                <Button
                  variant="primary"
                  icon="pulse"
                  disabled={language.trim() === ''}
                  onClick={() => run(picked, language.trim())}
                >
                  Translate
                </Button>
              ) : null}

              {result ? (
                <>
                  <Button
                    icon="copy"
                    onClick={() => {
                      navigator.clipboard
                        ?.writeText(result.answer)
                        .then(() => setCopied(true))
                        .catch(() => setCopied(false))
                    }}
                  >
                    Copy
                  </Button>
                  <Button
                    icon="plus"
                    disabled={applyBlocked !== undefined}
                    title={applyBlocked}
                    onClick={() => apply(onInsertBelow, result.answer)}
                  >
                    Insert below
                  </Button>
                  <Button
                    variant="primary"
                    icon="check"
                    disabled={applyBlocked !== undefined}
                    title={applyBlocked}
                    onClick={() => apply(onReplaceNote, result.answer)}
                  >
                    Replace note
                  </Button>
                </>
              ) : null}
            </>
          }
        >
          {awaitingLanguage ? (
            <div className="pulse-options">
              <label className="pulse-options__label" htmlFor={languageId}>
                Translate this note into
              </label>
              <input
                id={languageId}
                className="pulse-options__input"
                data-autofocus
                value={language}
                placeholder="Hindi"
                onChange={(event) => setLanguage(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' && language.trim() !== '') {
                    event.preventDefault()
                    run(picked, language.trim())
                  }
                }}
              />
            </div>
          ) : null}

          {action.isPending ? (
            <div className="pulse-progress" role="status">
              <Spinner size={14} />
              <span className="pulse-progress__text">Pulse is reading this note — {elapsed}s</span>
              <Button size="sm" variant="ghost" icon="stop" onClick={action.cancel}>
                Stop
              </Button>
            </div>
          ) : null}

          <PulseFailureNotice error={action.error} />

          {!awaitingLanguage && !action.isPending && !action.error && result === null ? (
            <p className="pulse-menu__empty">That run was stopped. Nothing was changed.</p>
          ) : null}

          {result ? (
            <div className="pulse-result">
              {result.grounded ? null : (
                <p className="pulse-turn__ungrounded">
                  <Icon name="alert" size={14} />
                  Pulse answered from general knowledge, not from your notes.
                </p>
              )}

              <div className="pulse-result__preview">
                {items.length > 0 ? (
                  <ul className="pulse-result__items">
                    {items.map((item, index) => (
                      <li key={`${index}-${item.text}`}>
                        {item.text}
                        {item.assignee || item.due_at ? (
                          <span className="pulse-result__meta">
                            {[item.assignee, item.due_at].filter(Boolean).join(' · ')}
                          </span>
                        ) : null}
                      </li>
                    ))}
                  </ul>
                ) : table ? (
                  <div className="pulse-result__table-wrap">
                    <table className="pulse-result__table">
                      <thead>
                        <tr>
                          {table.columns.map((column) => (
                            <th key={column} scope="col">
                              {column}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {table.rows.map((row, index) => (
                          <tr key={index}>
                            {row.map((cell, cellIndex) => (
                              <td key={cellIndex}>{cell}</td>
                            ))}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  // Rendered as text, never as markup: a model that could put
                  // HTML on this page is a model a note could talk into it.
                  result.answer
                    .split(/\n{2,}/)
                    .filter((paragraph) => paragraph.trim() !== '')
                    .map((paragraph, index) => <p key={index}>{paragraph}</p>)
                )}
              </div>

              <CitationList citations={result.citations} />

              {applyBlocked ? (
                <p className="pulse-result__blocked">
                  <Icon name="lock" size={14} />
                  {applyBlocked}, so this can be copied but not written into it.
                </p>
              ) : null}
            </div>
          ) : null}

          <LiveStatus>{copied ? 'Answer copied to the clipboard.' : ''}</LiveStatus>
        </Dialog>
      ) : null}
    </div>
  )
}
