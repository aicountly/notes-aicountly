/**
 * Creating and editing a notebook.
 *
 * One dialog for both, because "New notebook" and "Rename" ask for the same
 * four things and a second form would be a second place for the name rule to
 * be wrong. The caller says which part of it the user came for, so choosing
 * "Colour and icon…" from a menu lands focus on the swatches rather than on
 * the name they did not want to change.
 *
 * Field errors come back from the server keyed by field (`ApiError.fieldErrors`)
 * and are shown against that field — a duplicate name is a fact about the name
 * box, not a banner at the top.
 */

import { useEffect, useId, useState } from 'react'

import { ApiError } from '../../../shared/api/client'
import { Button, Dialog } from '../../../shared/ui/primitives'
import { ColorField, IconField } from '../../organise/appearance'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { useCreateNotebook, useUpdateNotebook } from '../hooks/useNotebooks'
import type { NotebookNode } from '../hooks/useNotebooks'
import '../notebooks.css'

const MAX_NAME = 200
const MAX_DESCRIPTION = 2000

export interface NotebookDialogProps {
  open: boolean
  /** The notebook being edited. Absent when creating. */
  notebook?: NotebookNode | null
  /** The parent a new notebook goes under. Absent for a root notebook. */
  parent?: NotebookNode | null
  /** Which part of the form the user came for. */
  initialFocus?: 'name' | 'appearance'
  onClose: () => void
  onSaved?: (notebook: NotebookNode, wasCreated: boolean) => void
}

export function NotebookDialog({
  open,
  notebook = null,
  parent = null,
  initialFocus = 'name',
  onClose,
  onSaved,
}: NotebookDialogProps) {
  const create = useCreateNotebook()
  const update = useUpdateNotebook()
  const nameId = useId()
  const descriptionId = useId()
  const nameErrorId = useId()

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [icon, setIcon] = useState<string | null>(null)
  const [color, setColor] = useState<string | null>(null)
  const [error, setError] = useState<unknown>(null)

  // Reset when the dialog opens rather than on every render of the parent:
  // typing a name and reopening on a different notebook must not keep it.
  useEffect(() => {
    if (!open) return
    setName(notebook?.name ?? '')
    setDescription(notebook?.description ?? '')
    setIcon(notebook?.icon ?? null)
    setColor(notebook?.color ?? null)
    setError(null)
  }, [open, notebook])

  const editing = notebook !== null
  const busy = create.isPending || update.isPending
  const fieldErrors = error instanceof ApiError ? error.fieldErrors : {}
  const trimmed = name.trim()

  const submit = () => {
    if (trimmed === '' || busy) return
    setError(null)

    const done = (saved: NotebookNode) => {
      onSaved?.(saved, !editing)
      onClose()
    }

    const work = editing
      ? update.mutateAsync({
          id: notebook.id,
          name: trimmed,
          description: description.trim() === '' ? null : description.trim(),
          icon,
          color,
        })
      : create.mutateAsync({
          name: trimmed,
          parent_id: parent?.id ?? null,
          description: description.trim() === '' ? null : description.trim(),
          icon,
          color,
        })

    work.then(done).catch(setError)
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={editing ? 'Notebook settings' : parent ? `New notebook in ${parent.name}` : 'New notebook'}
      description={
        editing
          ? undefined
          : 'A notebook is a place a note lives in. A note can be in one notebook at a time.'
      }
      width={520}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" icon="check" loading={busy} disabled={trimmed === ''} onClick={submit}>
            {editing ? 'Save changes' : 'Create notebook'}
          </Button>
        </>
      }
    >
      <form
        className="org-form"
        onSubmit={(event) => {
          event.preventDefault()
          submit()
        }}
      >
        {/* A field error is shown against its field; anything else — offline,
            a 500, a permission refusal — belongs at the top. */}
        {error && Object.keys(fieldErrors).length === 0 ? <ErrorNotice error={error} /> : null}

        <div className="org-field">
          <label className="org-label" htmlFor={nameId}>
            Name
          </label>
          <input
            id={nameId}
            className="org-input"
            value={name}
            maxLength={MAX_NAME}
            autoComplete="off"
            required
            disabled={busy}
            aria-invalid={fieldErrors.name !== undefined}
            aria-describedby={fieldErrors.name ? nameErrorId : undefined}
            data-autofocus={initialFocus === 'name' ? '' : undefined}
            onChange={(event) => setName(event.target.value)}
          />
          {fieldErrors.name ? (
            <p className="org-error" id={nameErrorId} role="alert">
              {fieldErrors.name}
            </p>
          ) : null}
        </div>

        <div className="org-field">
          <label className="org-label" htmlFor={descriptionId}>
            Description <span className="org-hint">Optional</span>
          </label>
          <textarea
            id={descriptionId}
            className="org-textarea"
            value={description}
            maxLength={MAX_DESCRIPTION}
            disabled={busy}
            onChange={(event) => setDescription(event.target.value)}
          />
        </div>

        <IconField
          value={icon}
          fallback="notebook"
          disabled={busy}
          autoFocus={initialFocus === 'appearance'}
          onChange={(next) => setIcon(next)}
        />

        <ColorField value={color} disabled={busy} onChange={(next) => setColor(next)} />

        {/* The dialog's own footer submits; this only makes Enter work inside
            the name field, where a keyboard user expects it to. */}
        <button type="submit" className="sr-only" tabIndex={-1} disabled={busy}>
          {editing ? 'Save changes' : 'Create notebook'}
        </button>
      </form>
    </Dialog>
  )
}
