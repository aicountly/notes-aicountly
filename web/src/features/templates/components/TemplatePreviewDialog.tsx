/**
 * What you get before you commit to it.
 *
 * The list cannot show a template's body — `GET /templates` leaves the document
 * out on purpose — so this is the one place that asks for it. Everything that
 * decides whether this is the right template is here: what it writes, what the
 * note will be called, what tags it arrives with, and, where it applies, why it
 * cannot be changed.
 */

import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, Dialog, Skeleton } from '../../../shared/ui/primitives'
import { ErrorNotice } from '../../organise/ErrorNotice'
import type { NoteTemplate } from '../../../shared/api/types'
import { NOTE_TYPE_LABELS, readOnlyReason, renderTitlePreview, useTemplate } from '../hooks/useTemplates'
import { TemplateDocumentPreview } from './TemplateDocumentPreview'
import '../templates.css'

export interface TemplatePreviewDialogProps {
  /** The template to show. The dialog is only mounted when there is one. */
  template: NoteTemplate
  onClose: () => void
  onUse: (template: NoteTemplate) => void
  /** Set while a note is being started from this template. */
  starting?: boolean
  /** Present when this template cannot be used here, and why. */
  blockedReason?: string | null
  /**
   * A failed attempt to start the note. Shown here rather than on the page
   * behind this dialog, which nobody can read while it is open.
   */
  error?: unknown
}

export function TemplatePreviewDialog({
  template,
  onClose,
  onUse,
  starting = false,
  blockedReason = null,
  error = null,
}: TemplatePreviewDialogProps) {
  // The list row is already correct for everything but the body, so the fetch
  // only ever adds to what is on screen — it never blanks it while loading.
  const detail = useTemplate(template.id)
  const title = renderTitlePreview(template.title_template)
  const readOnly = readOnlyReason(template)

  return (
    <Dialog
      open
      onClose={onClose}
      title={template.name}
      description={template.description ?? undefined}
      width={640}
      footer={
        <>
          <Button onClick={onClose}>Close</Button>
          <Button
            variant="primary"
            icon="plus"
            loading={starting}
            disabled={blockedReason !== null}
            title={blockedReason ?? undefined}
            onClick={() => onUse(template)}
          >
            Use template
          </Button>
        </>
      }
    >
      <div className="tpl-preview">
        {error ? <ErrorNotice error={error} /> : null}

        <dl className="tpl-facts">
          <div className="tpl-fact">
            <dt>Note type</dt>
            <dd>{NOTE_TYPE_LABELS[template.note_type]}</dd>
          </div>
          <div className="tpl-fact">
            <dt>Title of each note</dt>
            <dd>{title === '' ? 'Untitled, until you name it' : `“${title}”`}</dd>
          </div>
          {template.default_tags.length > 0 ? (
            <div className="tpl-fact">
              <dt>Tags</dt>
              <dd className="tpl-fact__tags">
                {template.default_tags.map((tag) => (
                  <Badge key={tag}>{`#${tag}`}</Badge>
                ))}
              </dd>
            </div>
          ) : null}
        </dl>

        {blockedReason !== null ? (
          <p className="tpl-notice tpl-notice--blocked" role="status">
            <Icon name="alert" size={15} />
            {blockedReason}
          </p>
        ) : null}

        {readOnly !== null ? (
          <p className="tpl-notice" role="note">
            <Icon name="lock" size={15} />
            {readOnly}
          </p>
        ) : null}

        <section className="tpl-preview__body">
          <h3 className="tpl-preview__heading">What it writes</h3>
          {detail.isPending ? (
            <div className="tpl-preview__skeleton" aria-busy>
              <Skeleton width="40%" height={16} />
              <Skeleton width="100%" height={12} />
              <Skeleton width="92%" height={12} />
              <Skeleton width="35%" height={16} />
              <Skeleton width="80%" height={12} />
            </div>
          ) : detail.isError ? (
            <ErrorNotice error={detail.error} onRetry={() => void detail.refetch()} />
          ) : (
            <TemplateDocumentPreview document={detail.data?.document} />
          )}
        </section>
      </div>
    </Dialog>
  )
}
