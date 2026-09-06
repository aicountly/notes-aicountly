/**
 * Templates.
 *
 * The screen where the starting points live. It is organised by *who owns a
 * template* rather than by what it contains, because that is the only thing
 * about a template a person cannot work out by looking at it: which ones came
 * with Notes, which ones the company agreed on, and which ones are theirs to
 * change.
 *
 * Ownership is not inferred here. The server sends `editable` per row, and the
 * "…" menu exists only where it is true — a built-in answers `TEMPLATE_READ_ONLY`
 * and a colleague's answers `TEMPLATE_NOT_OWNED`, and neither is a thing to
 * discover by pressing Save. The reason is on the preview instead, in the
 * server's own words.
 *
 * Everything a note is about to inherit — its body, its title pattern, its tags
 * — is visible before "Use template" is pressed. A template you have to try in
 * order to see is a template nobody tries twice.
 */

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, Dialog, EmptyState, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { useFeature } from '../../../app/AppConfigProvider'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { RowMenu } from '../../organise/RowMenu'
import { iconOrDefault } from '../../organise/appearance'
import type { NoteTemplate } from '../../../shared/api/types'
import { CANVAS_OFF, TemplateDialog } from '../components/TemplateDialog'
import { TemplatePicker } from '../components/TemplatePicker'
import { TemplatePreviewDialog } from '../components/TemplatePreviewDialog'
import {
  NOTE_TYPE_LABELS,
  SCOPE_LABEL,
  groupTemplates,
  renderTitlePreview,
  useCreateNoteFromTemplate,
  useDeleteTemplate,
  useTemplates,
} from '../hooks/useTemplates'
import '../templates.css'

export default function TemplatesPage() {
  const navigate = useNavigate()
  const templates = useTemplates()
  const start = useCreateNoteFromTemplate()
  const remove = useDeleteTemplate()
  const canvasEnabled = useFeature('canvas')

  const [picking, setPicking] = useState(false)
  const [editing, setEditing] = useState<NoteTemplate | null>(null)
  const [creating, setCreating] = useState(false)
  const [previewing, setPreviewing] = useState<NoteTemplate | null>(null)
  const [deleting, setDeleting] = useState<NoteTemplate | null>(null)
  const [startingId, setStartingId] = useState<string | null>(null)

  /** Why this template cannot start a note here, or null. */
  const blockedReason = (template: NoteTemplate): string | null =>
    template.note_type === 'canvas' && !canvasEnabled ? CANVAS_OFF : null

  const startNote = (template: NoteTemplate) => {
    if (blockedReason(template) !== null || start.isPending) return

    setStartingId(template.id)
    start
      .mutateAsync({ templateId: template.id })
      .then((note) => {
        setPreviewing(null)
        navigate(`/notes/${note.id}`)
      })
      // The message is rendered at the top of the page; the note was never
      // created, so there is nowhere to go.
      .catch(() => undefined)
      .finally(() => setStartingId(null))
  }

  const groups = groupTemplates(templates.data ?? [])
  const nothingAtAll = !templates.isPending && !templates.isError && groups.length === 0

  return (
    <div className="tpl-page">
      <div className="tpl-page__inner">
        <header className="tpl-page__header">
          <div>
            <h1 className="tpl-page__title">Templates</h1>
            <p className="tpl-page__subtitle">
              A template is a note that already knows how it starts — its body, its type, and what
              it is called.
            </p>
          </div>
          <div className="tpl-page__actions">
            <Button icon="template" onClick={() => setPicking(true)}>
              New note from template
            </Button>
            <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
              New template
            </Button>
          </div>
        </header>

        {start.error ? <ErrorNotice error={start.error} /> : null}

        {templates.isError ? (
          <ErrorNotice error={templates.error} onRetry={() => void templates.refetch()} />
        ) : null}

        {templates.isPending ? <TemplatesSkeleton /> : null}

        {nothingAtAll ? (
          <EmptyState
            icon="template"
            title="No templates yet"
            description="Save the shape of a note you write often — an agenda, a client call, a weekly review — and start the next one from it."
            action={
              <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
                New template
              </Button>
            }
          />
        ) : null}

        {groups.map((group) => (
          <section className="tpl-group" key={group.scope} aria-labelledby={`tpl-group-${group.scope}`}>
            <header className="tpl-group__header">
              <h2 className="tpl-group__title" id={`tpl-group-${group.scope}`}>
                {group.title}
              </h2>
              <p className="tpl-group__description">{group.description}</p>
            </header>

            <ul className="tpl-grid">
              {group.templates.map((template) => (
                <TemplateCard
                  key={template.id}
                  template={template}
                  blockedReason={blockedReason(template)}
                  starting={startingId === template.id}
                  onUse={() => startNote(template)}
                  onPreview={() => setPreviewing(template)}
                  onEdit={() => setEditing(template)}
                  onDelete={() => setDeleting(template)}
                />
              ))}
            </ul>
          </section>
        ))}
      </div>

      <LiveStatus>{start.isPending ? 'Starting your note' : ''}</LiveStatus>

      <TemplatePicker open={picking} onClose={() => setPicking(false)} />

      {/* Mounted only while in use: the create form asks the server for a list
          of notes to copy, which an unopened dialog has no business doing. */}
      {creating ? <TemplateDialog open template={null} onClose={() => setCreating(false)} /> : null}
      {editing ? (
        <TemplateDialog open template={editing} onClose={() => setEditing(null)} />
      ) : null}

      {previewing ? (
        <TemplatePreviewDialog
          template={previewing}
          starting={startingId === previewing.id}
          blockedReason={blockedReason(previewing)}
          onClose={() => setPreviewing(null)}
          onUse={startNote}
        />
      ) : null}

      {deleting ? (
        <DeleteTemplateDialog
          template={deleting}
          busy={remove.isPending}
          error={remove.error}
          onClose={() => {
            remove.reset()
            setDeleting(null)
          }}
          onConfirm={() => {
            remove
              .mutateAsync(deleting.id)
              .then(() => setDeleting(null))
              .catch(() => undefined)
          }}
        />
      ) : null}
    </div>
  )
}

// ---------------------------------------------------------------------------
// One template
// ---------------------------------------------------------------------------

function TemplateCard({
  template,
  blockedReason,
  starting,
  onUse,
  onPreview,
  onEdit,
  onDelete,
}: {
  template: NoteTemplate
  blockedReason: string | null
  starting: boolean
  onUse: () => void
  onPreview: () => void
  onEdit: () => void
  onDelete: () => void
}) {
  const title = renderTitlePreview(template.title_template)

  return (
    <li className="tpl-card">
      <div className="tpl-card__head">
        <span className="tpl-card__icon" aria-hidden>
          <Icon name={iconOrDefault(template.icon, 'template')} size={18} />
        </span>
        <h3 className="tpl-card__name">{template.name}</h3>
        <Badge tone={template.scope === 'system' ? 'neutral' : 'primary'}>
          {SCOPE_LABEL[template.scope]}
        </Badge>
      </div>

      <p className="tpl-card__description">
        {template.description ?? `Starts a ${NOTE_TYPE_LABELS[template.note_type].toLowerCase()}.`}
      </p>

      <dl className="tpl-card__meta">
        <div>
          <dt className="sr-only">Note type</dt>
          <dd>{NOTE_TYPE_LABELS[template.note_type]}</dd>
        </div>
        {title !== '' ? (
          <div>
            <dt className="sr-only">Title of each note</dt>
            <dd className="tpl-card__title-pattern">“{title}”</dd>
          </div>
        ) : null}
      </dl>

      {template.default_tags.length > 0 ? (
        <ul className="tpl-card__tags" aria-label="Tags added to every note from this template">
          {template.default_tags.map((tag) => (
            <li key={tag}>
              <Badge>{`#${tag}`}</Badge>
            </li>
          ))}
        </ul>
      ) : null}

      {blockedReason !== null ? (
        <p className="tpl-card__blocked">
          <Icon name="alert" size={14} />
          {blockedReason}
        </p>
      ) : null}

      <div className="tpl-card__actions">
        <Button
          variant="primary"
          size="sm"
          icon="plus"
          loading={starting}
          disabled={blockedReason !== null}
          title={blockedReason ?? undefined}
          onClick={onUse}
        >
          Use template
        </Button>
        <Button size="sm" icon="info" onClick={onPreview}>
          Preview
        </Button>
        {/* Only where the server says an edit would be accepted. Everyone else
            is told why on the preview instead of finding out on save. */}
        {template.editable ? (
          <RowMenu
            label={`Actions for ${template.name}`}
            items={[
              { key: 'edit', label: 'Edit template', icon: 'settings', onSelect: onEdit },
              {
                key: 'delete',
                label: 'Delete template',
                icon: 'trash',
                danger: true,
                separated: true,
                onSelect: onDelete,
              },
            ]}
          />
        ) : null}
      </div>
    </li>
  )
}

// ---------------------------------------------------------------------------
// Delete
// ---------------------------------------------------------------------------

function DeleteTemplateDialog({
  template,
  busy,
  error,
  onClose,
  onConfirm,
}: {
  template: NoteTemplate
  busy: boolean
  error: unknown
  onClose: () => void
  onConfirm: () => void
}) {
  return (
    <Dialog
      open
      onClose={onClose}
      title={`Delete “${template.name}”?`}
      width={440}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>
            Keep it
          </Button>
          <Button variant="danger" icon="trash" loading={busy} onClick={onConfirm}>
            Delete template
          </Button>
        </>
      }
    >
      <div className="org-form">
        {error ? <ErrorNotice error={error} /> : null}
        <p className="tpl-confirm">
          Notes already started from this template keep everything they were given. Only the
          starting point goes.
        </p>
      </div>
    </Dialog>
  )
}

// ---------------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------------

/** Shaped like the cards it replaces, so nothing jumps when they arrive. */
function TemplatesSkeleton() {
  return (
    <section className="tpl-group" aria-busy>
      <h2 className="tpl-group__title">Loading templates</h2>
      <ul className="tpl-grid">
        {[0, 1, 2, 3].map((card) => (
          <li className="tpl-card tpl-card--loading" key={card}>
            <Skeleton width={28} height={28} radius={9} />
            <Skeleton width="55%" height={15} />
            <Skeleton width="100%" height={12} />
            <Skeleton width="80%" height={12} />
            <Skeleton width={110} height={30} radius={8} />
          </li>
        ))}
      </ul>
    </section>
  )
}
