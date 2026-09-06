/**
 * One attachment, as a row.
 *
 * The row has to answer three questions at a glance — what is this file, is it
 * safe to look at yet, and what can I do with it — and it answers the second
 * one honestly:
 *
 *   - An image is shown. A PDF is shown *on request*, because loading a 30-page
 *     document into every row of a list is not a preview, it is a download with
 *     extra steps.
 *   - Everything else is a link. Nothing arbitrary is ever rendered inline:
 *     the server refuses HTML and SVG uploads outright, and this keeps an
 *     allowlist of its own so a future server relaxation cannot quietly turn
 *     this component into one that runs someone else's markup.
 *   - Processing state is named after the job that is running, and when the
 *     deployment cannot run that job at all the row says so instead of showing
 *     a spinner that will never resolve.
 */

import { useId, useState } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { Button, Spinner } from '../../../shared/ui/primitives'
import type { Attachment, AttachmentKind } from '../../../shared/api/types'
import { attachmentContentUrl, formatBytes } from '../hooks/useAttachments'
import type { PendingUpload } from '../hooks/useAttachments'
import '../attachments.css'

const KIND_ICONS: Record<AttachmentKind, IconName> = {
  image: 'image',
  pdf: 'file',
  audio: 'mic',
  video: 'play',
  document: 'note',
  spreadsheet: 'table',
  presentation: 'grid',
  text: 'note',
  file: 'file',
}

const KIND_LABELS: Record<AttachmentKind, string> = {
  image: 'Image',
  pdf: 'PDF',
  audio: 'Audio',
  video: 'Video',
  document: 'Document',
  spreadsheet: 'Spreadsheet',
  presentation: 'Presentation',
  text: 'Text file',
  file: 'File',
}

/**
 * Types this component will put in the page itself.
 *
 * An allowlist, not a blocklist, and it contains no markup format on purpose.
 * `text/html`, `image/svg+xml` and friends are documents with scripts in them;
 * rendering one from this origin would run it with the app's own privileges.
 */
const INLINE_IMAGE_TYPES = new Set([
  'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif',
])

function canShowImage(attachment: Attachment): boolean {
  return attachment.kind === 'image' && INLINE_IMAGE_TYPES.has(attachment.mime_type)
}

function canShowPdf(attachment: Attachment): boolean {
  return attachment.kind === 'pdf' && attachment.mime_type === 'application/pdf'
}

export function formatDuration(seconds: number): string {
  const whole = Math.max(0, Math.round(seconds))
  const minutes = Math.floor(whole / 60)
  const rest = whole % 60
  return `${minutes}:${String(rest).padStart(2, '0')}`
}

/** The job the server runs for this kind of file, in words. */
function processingVerb(kind: AttachmentKind): string {
  if (kind === 'audio' || kind === 'video') return 'Transcribing…'
  return 'Extracting text…'
}

/** Which flag has to be on for that job to be possible at all. */
function requiredFeature(kind: AttachmentKind): 'transcription' | 'ocr' | null {
  if (kind === 'audio' || kind === 'video') return 'transcription'
  if (kind === 'image' || kind === 'pdf' || kind === 'text') return 'ocr'
  return null
}

type StatusTone = 'busy' | 'ready' | 'failed' | 'muted'

interface StatusLine {
  tone: StatusTone
  text: string
  detail?: string
}

/**
 * What to say about this file's state.
 *
 * Exported because it is the piece worth testing: the mapping from two server
 * columns and a feature flag onto one sentence is where a "Transcribing…" that
 * never finishes comes from.
 */
export function describeStatus(
  attachment: Attachment,
  features: { transcription: boolean; ocr: boolean },
): StatusLine | null {
  if (attachment.upload_status === 'failed') {
    return { tone: 'failed', text: 'Upload failed' }
  }
  if (attachment.upload_status === 'pending' || attachment.upload_status === 'uploading') {
    return { tone: 'busy', text: 'Uploading…' }
  }

  switch (attachment.processing_status) {
    case 'queued':
    case 'processing': {
      const flag = requiredFeature(attachment.kind)
      // A queued job on a deployment with the capability switched off is a
      // spinner with no end. Say what is actually true instead.
      if (flag === 'transcription' && !features.transcription) {
        return { tone: 'muted', text: 'Transcription is not enabled on this deployment' }
      }
      if (flag === 'ocr' && !features.ocr) {
        return { tone: 'muted', text: 'Text extraction is not enabled on this deployment' }
      }
      return { tone: 'busy', text: processingVerb(attachment.kind) }
    }
    case 'failed':
      return {
        tone: 'failed',
        text: 'Couldn’t process',
        detail: attachment.processing_error ?? undefined,
      }
    case 'completed':
      return { tone: 'ready', text: 'Ready' }
    case 'pending':
    case 'skipped':
    default:
      // Nothing was queued for this kind of file. There is no state to report,
      // and "Skipped" would read as a failure.
      return null
  }
}

export interface AttachmentBlockProps {
  attachment: Attachment
  features: { transcription: boolean; ocr: boolean }
  /** From the note's `capabilities`; a viewer sees the file but cannot remove it. */
  canDelete: boolean
  onDelete?: (attachment: Attachment) => void
  deleting?: boolean
  /** Re-reads this note's attachments. Offered when processing failed. */
  onCheckAgain?: () => void
}

export function AttachmentBlock({
  attachment,
  features,
  canDelete,
  onDelete,
  deleting = false,
  onCheckAgain,
}: AttachmentBlockProps) {
  const [previewOpen, setPreviewOpen] = useState(false)
  const previewId = useId()

  const href = attachmentContentUrl(attachment)
  const status = describeStatus(attachment, features)
  const showImage = canShowImage(attachment)
  const showPdf = canShowPdf(attachment)

  const meta = [
    KIND_LABELS[attachment.kind],
    formatBytes(attachment.byte_size),
    attachment.duration_seconds !== null ? formatDuration(attachment.duration_seconds) : null,
  ].filter((part): part is string => part !== null)

  return (
    <li className="att-block">
      <div className="att-block__row">
        <span className="att-block__icon" aria-hidden>
          <Icon name={KIND_ICONS[attachment.kind]} size={17} />
        </span>

        <div className="att-block__body">
          <a className="att-block__name" href={href} download={attachment.filename}>
            {attachment.filename}
          </a>
          <p className="att-block__meta">
            {meta.join(' · ')}
            {attachment.drive_file_id ? ' · From Drive' : ''}
          </p>

          {status ? (
            <p className={`att-status att-status--${status.tone}`}>
              {status.tone === 'busy' ? <Spinner size={11} /> : null}
              {status.tone === 'ready' ? <Icon name="check" size={13} /> : null}
              {status.tone === 'failed' ? <Icon name="alert" size={13} /> : null}
              <span>{status.text}</span>
              {status.detail ? <span className="att-status__detail">{status.detail}</span> : null}
              {status.tone === 'failed' && onCheckAgain ? (
                <Button size="sm" variant="ghost" icon="refresh" onClick={onCheckAgain}>
                  Check again
                </Button>
              ) : null}
            </p>
          ) : null}
        </div>

        <div className="att-block__actions">
          {showPdf ? (
            <Button
              size="sm"
              variant="ghost"
              icon={previewOpen ? 'chevron-down' : 'chevron-right'}
              aria-expanded={previewOpen}
              aria-controls={previewId}
              onClick={() => setPreviewOpen((open) => !open)}
            >
              Preview
            </Button>
          ) : null}

          <a
            className="btn btn--ghost btn--sm att-block__download"
            href={href}
            download={attachment.filename}
          >
            <Icon name="download" size={15} />
            <span>Download</span>
          </a>

          {canDelete && onDelete ? (
            <Button
              size="sm"
              variant="ghost"
              icon="trash"
              iconOnly
              loading={deleting}
              aria-label={`Remove ${attachment.filename}`}
              onClick={() => onDelete(attachment)}
            />
          ) : null}
        </div>
      </div>

      {showImage ? (
        <div className="att-preview att-preview--image">
          {/* Not wrapped in a link: Download above already goes to the same
              place, and a second link with the same name is noise in a screen
              reader's list of links. */}
          <img
            src={href}
            alt={attachment.filename}
            loading="lazy"
            decoding="async"
            width={attachment.width ?? undefined}
            height={attachment.height ?? undefined}
          />
        </div>
      ) : null}

      {showPdf && previewOpen ? (
        <div className="att-preview att-preview--pdf" id={previewId}>
          <object data={href} type="application/pdf" aria-label={`Preview of ${attachment.filename}`}>
            {/* Reached whenever the browser declines to embed a PDF — a mobile
                browser, or a store that sends the file as a download. */}
            <p className="att-preview__fallback">
              This browser cannot show the PDF here.{' '}
              <a href={href} download={attachment.filename}>
                Download {attachment.filename}
              </a>{' '}
              to read it.
            </p>
          </object>
        </div>
      ) : null}
    </li>
  )
}

// ---------------------------------------------------------------------------
// A file that has not reached the server yet
// ---------------------------------------------------------------------------

export interface PendingAttachmentBlockProps {
  item: PendingUpload
  onRetry: (key: string) => void
  onDismiss: (key: string) => void
}

/**
 * The same row, for a file still uploading.
 *
 * The bar is deliberately indeterminate. `api.upload` is built on `fetch`,
 * which reports nothing until the request completes, so a percentage here
 * would be a number this app invented — and a progress bar that lies is worse
 * than one that only says "working".
 */
export function PendingAttachmentBlock({ item, onRetry, onDismiss }: PendingAttachmentBlockProps) {
  const failed = item.error !== null

  return (
    <li className={`att-block att-block--pending ${failed ? 'att-block--failed' : ''}`.trim()}>
      <div className="att-block__row">
        <span className="att-block__icon" aria-hidden>
          {item.previewUrl ? (
            <img className="att-block__thumb" src={item.previewUrl} alt="" />
          ) : (
            <Icon name={KIND_ICONS[item.kind]} size={17} />
          )}
        </span>

        <div className="att-block__body">
          <p className="att-block__name att-block__name--plain">{item.filename}</p>
          <p className="att-block__meta">{formatBytes(item.byteSize)}</p>

          {failed ? (
            <p className="att-status att-status--failed">
              <Icon name="alert" size={13} />
              <span>{item.error}</span>
            </p>
          ) : (
            <>
              <p className="att-status att-status--busy">
                <Spinner size={11} />
                <span>Uploading…</span>
              </p>
              <div
                className="att-progress"
                role="progressbar"
                aria-label={`Uploading ${item.filename}`}
              >
                <span className="att-progress__bar" />
              </div>
            </>
          )}
        </div>

        <div className="att-block__actions">
          {failed ? (
            <Button size="sm" variant="ghost" icon="refresh" onClick={() => onRetry(item.key)}>
              Try again
            </Button>
          ) : null}
          <Button
            size="sm"
            variant="ghost"
            icon="close"
            iconOnly
            aria-label={`Remove ${item.filename} from the queue`}
            onClick={() => onDismiss(item.key)}
          />
        </div>
      </div>
    </li>
  )
}
