/**
 * Making a template, and renaming one.
 *
 * Two things this form is careful about, both of them consequences of what the
 * server does with the fields:
 *
 *   - **The title is a pattern, not a title.** `{{date}}` and `{{time}}` are
 *     substituted when a note is started, and nothing else is — so the tokens
 *     are listed here, and what today's note would be called is shown under the
 *     field rather than left to be discovered after the fact.
 *   - **"Start from a note" is the same request.** `POST /templates` with a
 *     `from_note_id` copies that note's body, type and tags, which is why those
 *     three controls step aside when a note is chosen: offering a type picker
 *     that the server is about to overrule is offering a lie.
 *
 * A template's body cannot be edited here. There is no document editor in this
 * dialog and inventing half of one would be worse than saying so: the way to
 * change what a template writes is to save the note you want as a new template.
 */

import { useEffect, useId, useState } from 'react'

import { ApiError } from '../../../shared/api/client'
import { Icon } from '../../../shared/ui/Icon'
import { Button, Dialog } from '../../../shared/ui/primitives'
import { useAuth } from '../../../auth/AuthProvider'
import { useFeature } from '../../../app/AppConfigProvider'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { IconField } from '../../organise/appearance'
import { TagPicker } from '../../tags/components/TagPicker'
import { useNoteList } from '../../notes/hooks/useNotes'
import type { NoteTemplate, NoteType } from '../../../shared/api/types'
import {
  MAX_DEFAULT_TAGS,
  MAX_DESCRIPTION,
  MAX_NAME,
  MAX_TITLE_TEMPLATE,
  NOTE_TYPE_LABELS,
  TITLE_TOKENS,
  renderTitlePreview,
  useCreateTemplate,
  useUpdateTemplate,
} from '../hooks/useTemplates'
import type { TemplateScope } from '../hooks/useTemplates'
import '../templates.css'

/** Why a canvas template could not be used if it were made. */
export const CANVAS_OFF = 'Canvas notes are switched off for this workspace.'

export interface TemplateDialogProps {
  open: boolean
  /** The template being renamed. Absent when creating. */
  template?: NoteTemplate | null
  onClose: () => void
  onSaved?: (template: NoteTemplate, wasCreated: boolean) => void
}

export function TemplateDialog({ open, template = null, onClose, onSaved }: TemplateDialogProps) {
  const editing = template !== null
  const create = useCreateTemplate()
  const update = useUpdateTemplate()
  const canvasEnabled = useFeature('canvas')
  const { profile } = useAuth()

  const nameId = useId()
  const descriptionId = useId()
  const typeId = useId()
  const titleId = useId()
  const sourceId = useId()
  const scopeName = useId()

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [icon, setIcon] = useState<string | null>(null)
  const [noteType, setNoteType] = useState<NoteType>('document')
  const [titleTemplate, setTitleTemplate] = useState('')
  const [tags, setTags] = useState<string[]>([])
  const [scope, setScope] = useState<Exclude<TemplateScope, 'system'>>('user')
  const [fromNoteId, setFromNoteId] = useState('')
  const [error, setError] = useState<unknown>(null)

  useEffect(() => {
    if (!open) return
    setName(template?.name ?? '')
    setDescription(template?.description ?? '')
    setIcon(template?.icon ?? null)
    setNoteType(template?.note_type ?? 'document')
    setTitleTemplate(template?.title_template ?? '')
    setTags(template?.default_tags ?? [])
    setScope('user')
    setFromNoteId('')
    setError(null)
  }, [open, template])

  const busy = create.isPending || update.isPending
  const fieldErrors = error instanceof ApiError ? error.fieldErrors : {}
  const trimmed = name.trim()
  const fromNote = fromNoteId !== ''
  const canvasBlocked = noteType === 'canvas' && !canvasEnabled
  const hasCompany = profile?.tenant_id !== null && profile?.tenant_id !== undefined

  const submit = () => {
    if (trimmed === '' || busy || canvasBlocked) return
    setError(null)

    const shared = {
      name: trimmed,
      description: description.trim() === '' ? null : description.trim(),
      icon,
      title_template: titleTemplate.trim() === '' ? null : titleTemplate.trim(),
    }

    const work = editing
      ? update.mutateAsync({
          id: template.id,
          ...shared,
          note_type: noteType,
          default_tags: tags,
        })
      : create.mutateAsync({
          ...shared,
          scope,
          from_note_id: fromNote ? fromNoteId : undefined,
          // Left out when the note decides them, so the server's copy wins
          // rather than being overwritten by this form's defaults.
          note_type: fromNote ? undefined : noteType,
          default_tags: fromNote && tags.length === 0 ? undefined : tags,
        })

    work
      .then((saved) => {
        onSaved?.(saved, !editing)
        onClose()
      })
      .catch(setError)
  }

  const titlePreview = renderTitlePreview(titleTemplate)

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={editing ? 'Edit template' : 'New template'}
      description={
        editing
          ? 'Changes apply to notes started from now on. Notes already made from it are untouched.'
          : 'A template is a starting point: a body, a note type and a title pattern.'
      }
      width={620}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button
            variant="primary"
            icon="check"
            loading={busy}
            disabled={trimmed === '' || canvasBlocked}
            title={canvasBlocked ? CANVAS_OFF : undefined}
            onClick={submit}
          >
            {editing ? 'Save template' : 'Create template'}
          </Button>
        </>
      }
    >
      <div className="org-form">
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
            data-autofocus=""
            aria-invalid={fieldErrors.name !== undefined}
            onChange={(event) => setName(event.target.value)}
          />
          {fieldErrors.name ? (
            <p className="org-error" role="alert">
              {fieldErrors.name}
            </p>
          ) : null}
        </div>

        <div className="org-field">
          <label className="org-label" htmlFor={descriptionId}>
            Description
          </label>
          <textarea
            id={descriptionId}
            className="org-textarea"
            value={description}
            maxLength={MAX_DESCRIPTION}
            disabled={busy}
            placeholder="When someone should reach for this one."
            onChange={(event) => setDescription(event.target.value)}
          />
        </div>

        <IconField value={icon} fallback="template" disabled={busy} onChange={setIcon} />

        {editing || !fromNote ? (
          <div className="org-field">
            <label className="org-label" htmlFor={typeId}>
              Note type
            </label>
            <select
              id={typeId}
              className="org-select"
              value={noteType}
              disabled={busy}
              onChange={(event) => setNoteType(event.target.value as NoteType)}
            >
              {(Object.keys(NOTE_TYPE_LABELS) as NoteType[]).map((type) => (
                <option
                  key={type}
                  value={type}
                  // Kept visible rather than removed: a template already saved
                  // as a canvas must still show what it is.
                  disabled={type === 'canvas' && !canvasEnabled}
                >
                  {type === 'canvas' && !canvasEnabled
                    ? `${NOTE_TYPE_LABELS[type]} — switched off`
                    : NOTE_TYPE_LABELS[type]}
                </option>
              ))}
            </select>
            {canvasBlocked ? (
              <p className="org-error" role="alert">
                {CANVAS_OFF}
              </p>
            ) : null}
          </div>
        ) : null}

        <div className="org-field">
          <label className="org-label" htmlFor={titleId}>
            Title of each new note
          </label>
          <input
            id={titleId}
            className="org-input"
            value={titleTemplate}
            maxLength={MAX_TITLE_TEMPLATE}
            autoComplete="off"
            disabled={busy}
            placeholder="Meeting — {{date}}"
            aria-describedby={`${titleId}-tokens`}
            onChange={(event) => setTitleTemplate(event.target.value)}
          />
          <p className="tpl-tokens" id={`${titleId}-tokens`}>
            <Icon name="info" size={13} />
            <span>
              Two tokens are filled in when a note is made:{' '}
              {TITLE_TOKENS.map((entry, index) => (
                <span key={entry.token}>
                  {index > 0 ? ' and ' : ''}
                  <code className="tpl-token">{entry.token}</code> for {entry.description}
                </span>
              ))}
              . Anything else in braces is left as you typed it, as a blank to fill in.
            </span>
          </p>
          {titlePreview !== '' ? (
            <p className="org-hint">A note made now would be called “{titlePreview}”.</p>
          ) : null}
        </div>

        <TagPicker
          label="Tags on every note from this template"
          value={tags}
          max={MAX_DEFAULT_TAGS}
          disabled={busy}
          onChange={setTags}
        />

        {editing ? (
          <p className="org-hint">
            <Icon name="info" size={13} /> The body cannot be edited here. To change what this
            template writes, start a note from it, edit that note, and save it as a new template.
          </p>
        ) : (
          <>
            <SourceNoteField
              id={sourceId}
              value={fromNoteId}
              disabled={busy}
              onChange={setFromNoteId}
            />

            <fieldset className="org-field tpl-fieldset">
              <legend className="org-label">Who can use it</legend>
              <div className="tpl-scope">
                <label className="tpl-scope__option">
                  <input
                    type="radio"
                    name={scopeName}
                    value="user"
                    checked={scope === 'user'}
                    disabled={busy}
                    onChange={() => setScope('user')}
                  />
                  Only me
                </label>
                <label className="tpl-scope__option">
                  <input
                    type="radio"
                    name={scopeName}
                    value="tenant"
                    checked={scope === 'tenant'}
                    // The server refuses a company template from someone who is
                    // not signed in to one, so the choice is closed here.
                    disabled={busy || !hasCompany}
                    onChange={() => setScope('tenant')}
                  />
                  Everyone at my company
                </label>
              </div>
              {!hasCompany ? (
                <p className="org-hint">
                  You are not signed in to a company, so this template can only be your own.
                </p>
              ) : null}
            </fieldset>
          </>
        )}
      </div>
    </Dialog>
  )
}

/**
 * The note a new template is copied from.
 *
 * Its own component so the note list is only fetched while a template is being
 * *made* — renaming one has no business asking the server for forty notes.
 */
function SourceNoteField({
  id,
  value,
  disabled,
  onChange,
}: {
  id: string
  value: string
  disabled: boolean
  onChange: (noteId: string) => void
}) {
  const notes = useNoteList({ sort: 'updated_desc', limit: 40 })

  return (
    <div className="org-field">
      <label className="org-label" htmlFor={id}>
        Start from
      </label>
      <select
        id={id}
        className="org-select"
        value={value}
        disabled={disabled || notes.isPending}
        onChange={(event) => onChange(event.target.value)}
      >
        <option value="">{notes.isPending ? 'Loading your notes…' : 'An empty note'}</option>
        {(notes.data?.notes ?? []).map((note) => (
          <option
            key={note.id}
            value={note.id}
            // A private note is ciphertext to the server, so it cannot become a
            // template — said here rather than discovered on save.
            disabled={note.privacy_mode === 'private'}
          >
            {note.privacy_mode === 'private'
              ? `${note.display_title} — private, cannot be copied`
              : note.display_title}
          </option>
        ))}
      </select>
      {notes.isError ? (
        <p className="org-hint">
          Your notes could not be listed ({notes.error.message}) — this template will start empty.
        </p>
      ) : null}
      {value !== '' ? (
        <p className="org-hint">The body, note type and tags come from that note.</p>
      ) : null}
    </div>
  )
}
