/**
 * Choosing a notebook to move something into.
 *
 * Used for moving a notebook under another one, and shaped so it can serve a
 * note just as well: the caller supplies the current destination and receives
 * the chosen one, and nothing about the thing being moved leaks in here.
 *
 * Two destinations are refused rather than hidden, because a picker that
 * silently omits a notebook reads as a missing notebook:
 *
 *   - anything inside the subtree being moved — a notebook cannot contain
 *     itself, and a cycle turns every recursive read in the API into a query
 *     that never returns;
 *   - anything so deep that the branch would break the eight-level cap.
 *
 * Both are shown greyed with the reason beside them.
 */

import { useId, useMemo, useState } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import { Button, Dialog, Skeleton } from '../../../shared/ui/primitives'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { colorDotClass, iconOrDefault } from '../../organise/appearance'
import { flattenNotebooks, useNotebooks } from '../hooks/useNotebooks'
import type { NotebookNode } from '../hooks/useNotebooks'
import '../notebooks.css'

export interface MoveToNotebookDialogProps {
  open: boolean
  title?: string
  /** The destination currently in force, marked as chosen. */
  selectedId: string | null
  /** Hide-and-explain this notebook and everything under it. */
  excludeIds?: Set<string>
  /** What "no parent" is called here — "Top level" for a notebook. */
  rootLabel?: string
  /** The deepest a destination may itself sit, so the branch still fits. */
  maxDestinationDepth?: number
  busy?: boolean
  error?: unknown
  onSelect: (notebookId: string | null) => void
  onClose: () => void
}

export function MoveToNotebookDialog({
  open,
  title = 'Move to notebook',
  selectedId,
  excludeIds,
  rootLabel = 'Top level',
  maxDestinationDepth,
  busy = false,
  error = null,
  onSelect,
  onClose,
}: MoveToNotebookDialogProps) {
  const notebooks = useNotebooks()
  const [filter, setFilter] = useState('')
  const filterId = useId()

  const options = useMemo(() => {
    const flat = flattenNotebooks(notebooks.data ?? [])
    const needle = filter.trim().toLowerCase()

    return flat.filter((option) => needle === '' || option.name.toLowerCase().includes(needle))
  }, [notebooks.data, filter])

  const byId = useMemo(() => {
    const map = new Map<string, NotebookNode>()
    const walk = (nodes: NotebookNode[]) => {
      for (const node of nodes) {
        map.set(node.id, node)
        walk(node.children ?? [])
      }
    }
    walk(notebooks.data ?? [])

    return map
  }, [notebooks.data])

  const reasonFor = (id: string, depth: number): string | undefined => {
    if (excludeIds?.has(id)) return 'Inside this notebook'
    if (maxDestinationDepth !== undefined && depth > maxDestinationDepth) return 'Too deep'
    return undefined
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={title}
      width={460}
      footer={
        <Button onClick={onClose} disabled={busy}>
          Cancel
        </Button>
      }
    >
      {error ? <ErrorNotice error={error} /> : null}

      <div className="org-form">
        <div className="org-field">
          <label className="org-label" htmlFor={filterId}>
            Find a notebook
          </label>
          <input
            id={filterId}
            className="org-input"
            type="search"
            value={filter}
            autoComplete="off"
            placeholder="Type to filter"
            disabled={busy || notebooks.isPending}
            onChange={(event) => setFilter(event.target.value)}
          />
        </div>

        {notebooks.isPending ? (
          <div className="org-skeletons" aria-hidden>
            {[0, 1, 2, 3].map((row) => (
              <div className="org-skeleton-row" key={row}>
                <Skeleton width={16} height={16} radius={4} />
                <Skeleton width={`${60 + row * 8}%`} height={12} />
              </div>
            ))}
          </div>
        ) : notebooks.isError ? (
          <ErrorNotice error={notebooks.error} onRetry={() => void notebooks.refetch()} />
        ) : (
          <ul className="org-picker">
            <li>
              <button
                type="button"
                className="org-picker__option"
                aria-pressed={selectedId === null}
                disabled={busy}
                onClick={() => onSelect(null)}
              >
                <Icon name="home" size={15} />
                <span className="org-picker__label">{rootLabel}</span>
              </button>
            </li>

            {options.map((option) => {
              const node = byId.get(option.id)
              const reason = reasonFor(option.id, option.depth)

              return (
                <li key={option.id}>
                  <button
                    type="button"
                    className="org-picker__option"
                    aria-pressed={selectedId === option.id}
                    aria-disabled={reason !== undefined || busy}
                    // Indentation shows where a destination sits, which is the
                    // difference between two notebooks with the same name.
                    style={{ paddingLeft: `calc(var(--notes-space-3) + ${option.depth} * var(--notes-space-4))` }}
                    onClick={() => {
                      if (reason !== undefined || busy) return
                      onSelect(option.id)
                    }}
                  >
                    <span className={`org-dot ${colorDotClass(node?.color)}`.trim()} aria-hidden />
                    <Icon name={iconOrDefault(node?.icon, 'notebook')} size={15} />
                    <span className="org-picker__label">{option.name}</span>
                    {reason ? <span className="org-picker__note">{reason}</span> : null}
                  </button>
                </li>
              )
            })}

            {options.length === 0 ? (
              <li>
                <p className="org-empty">
                  {filter.trim() === ''
                    ? 'You have no notebooks yet.'
                    : `No notebook matches “${filter.trim()}”.`}
                </p>
              </li>
            ) : null}
          </ul>
        )}
      </div>

    </Dialog>
  )
}
