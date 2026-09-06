/**
 * Everything attached to a note, and every way of adding something.
 *
 * This is the one place the three capture paths meet, so it is also where the
 * rules about what may be offered are enforced:
 *
 *   - **Recording is hidden when the browser cannot record.** `MediaRecorder`
 *     is missing on old Safari and on any insecure origin, and a Record button
 *     that throws is worse than no Record button.
 *   - **Drive is hidden when the deployment has no Drive.** The endpoint
 *     answers 503 with the flag off, so the control is not rendered.
 *   - **A viewer sees the files and no controls.** `capabilities.edit` is the
 *     server's answer about this note and this person; the list respects it
 *     rather than letting the user find out by being refused.
 */

import { useId, useRef, useState } from 'react'

import { useFeature } from '../../../app/AppConfigProvider'
import { getAppById, resolveAppOrigin } from '../../../services/appLauncher'
import { ApiError } from '../../../shared/api/client'
import { Icon } from '../../../shared/ui/Icon'
import { Button, Dialog, EmptyState, Skeleton } from '../../../shared/ui/primitives'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { DocumentScanner } from '../../capture/components/DocumentScanner'
import { VoiceRecorder, isVoiceRecordingSupported } from '../../capture/components/VoiceRecorder'
import type { Attachment, NoteCapabilities } from '../../../shared/api/types'
import { useAttachments, formatBytes } from '../hooks/useAttachments'
import { AttachmentBlock, PendingAttachmentBlock } from './AttachmentBlock'
import { FileDropZone } from './FileDropZone'
import '../attachments.css'

/**
 * Drive's own origin for THIS environment.
 *
 * Read from the launcher catalog rather than written out, because Drive's host
 * is `drive.aicountly.com` in production and `drive.gh.aicountly.com` in
 * sandbox — and because the catalog entry's id is `docs`, which is Drive's
 * product code rather than its hostname. Hard-coding either is how a sandbox
 * build ends up telling people to paste a production link.
 *
 * Used to name Drive in this dialog, not to build a link into it: Drive's SPA
 * routes documents by *scope* (`/documents/:scope?` —
 * `drive-react-app/web/src/constants/routes.js`) and has no per-document URL at
 * all, so there is no address of a single file to show as an example.
 */
function driveOrigin(): string {
  const drive = getAppById('docs')
  return drive ? resolveAppOrigin(drive) : 'https://drive.aicountly.com'
}

/**
 * A Drive file id, from an id or from a link to it.
 *
 * The id is what to ask for, because Drive has no per-document URL today —
 * `/documents/personal` and `/documents/trash` are lists, so the last segment
 * of a real Drive address is a scope name and not a file. The URL branch stays
 * anyway: people paste address bars, it costs one regex, and it means the day
 * Drive gains `?id=` or a document route this field already understands it.
 *
 * Anything that is not a URL is taken as an id and handed to the server, which
 * is the thing that decides whether it exists and whether this person may see
 * it.
 */
export function parseDriveFileId(input: string): string {
  const trimmed = input.trim()
  if (trimmed === '') return ''
  if (!/^https?:\/\//i.test(trimmed)) return trimmed

  try {
    const url = new URL(trimmed)
    const fromQuery = url.searchParams.get('id') ?? url.searchParams.get('file_id')
    if (fromQuery) return fromQuery

    const segments = url.pathname.split('/').filter((segment) => segment !== '')
    return segments[segments.length - 1] ?? ''
  } catch {
    return trimmed
  }
}

export interface AttachmentListProps {
  noteId: string
  /** The note's own `capabilities` object, straight from the server. */
  capabilities: NoteCapabilities
}

export function AttachmentList({ noteId, capabilities }: AttachmentListProps) {
  const attachments = useAttachments(noteId)
  const driveEnabled = useFeature('drive')
  const transcription = useFeature('transcription')
  const ocr = useFeature('ocr')

  const fileInputRef = useRef<HTMLInputElement>(null)
  const [dialog, setDialog] = useState<'none' | 'voice' | 'scan' | 'drive'>('none')
  const [driveInput, setDriveInput] = useState('')
  const [driveError, setDriveError] = useState<string | null>(null)
  const [driveBusy, setDriveBusy] = useState(false)
  /** One line for anything that went wrong outside the list itself. */
  const [panelError, setPanelError] = useState<string | null>(null)
  const driveFieldId = useId()
  const headingId = useId()

  const canEdit = capabilities.edit
  const canRecord = isVoiceRecordingSupported()

  const close = () => {
    setDialog('none')
    setDriveInput('')
    setDriveError(null)
  }

  const onDelete = async (attachment: Attachment) => {
    setPanelError(null)
    try {
      await attachments.remove(attachment.id)
    } catch (error) {
      setPanelError(
        error instanceof ApiError ? error.message : `${attachment.filename} could not be removed.`,
      )
    }
  }

  /**
   * A capture dialog's upload.
   *
   * Failures are pulled back out of the tray, because the dialog in front of
   * the user is where the message has to land — a row behind an overlay is a
   * message nobody reads.
   *
   * The partial case is the one worth being careful about. A scan of five
   * pages where the fourth is refused has already stored three files; throwing
   * would leave the scanner holding all five, and saving again would attach
   * the first three a second time. So a partial run closes the dialog and
   * reports what did not make it, and only a run that stored nothing is thrown
   * back for the user to try again with what they captured still in hand.
   */
  const uploadFromDialog = async (files: File[]) => {
    setPanelError(null)
    const outcome = await attachments.upload(files)
    outcome.failed.forEach((row) => attachments.dismissPending(row.key))

    if (outcome.failed.length === 0) {
      close()
      return
    }

    const reasons = outcome.failed.map((row) => row.message).join(' ')
    if (outcome.stored.length === 0) throw new Error(reasons)

    setPanelError(reasons)
    close()
  }

  const attachDrive = async () => {
    const id = parseDriveFileId(driveInput)
    if (id === '') {
      setDriveError('Enter the file’s id in Drive.')
      return
    }

    setDriveBusy(true)
    setDriveError(null)
    try {
      await attachments.attachDriveFile(id)
      close()
    } catch (error) {
      setDriveError(
        error instanceof ApiError ? error.message : 'That Drive file could not be attached.',
      )
    } finally {
      setDriveBusy(false)
    }
  }

  const pendingRows = attachments.pending.map((item) => (
    <PendingAttachmentBlock
      key={item.key}
      item={item}
      onRetry={attachments.retryPending}
      onDismiss={attachments.dismissPending}
    />
  ))

  const body = () => {
    if (attachments.isPending) {
      // Shaped like the rows that are coming: an icon, two lines of text and a
      // pair of actions, so nothing jumps when they arrive.
      return (
        <ul className="att-list att-list--loading">
          {[0, 1, 2].map((row) => (
            <li className="att-block" key={row}>
              <div className="att-block__row">
                <Skeleton width={34} height={34} radius={8} />
                <div className="att-block__body">
                  <Skeleton width="45%" height={13} />
                  <Skeleton width="25%" height={11} />
                </div>
              </div>
            </li>
          ))}
        </ul>
      )
    }

    if (attachments.isError && attachments.error) {
      // A failed read says nothing about a file this device is still holding,
      // so the queue stays on screen underneath the explanation.
      return (
        <>
          <ErrorNotice error={attachments.error} onRetry={attachments.refetch} />
          {pendingRows.length > 0 ? <ul className="att-list">{pendingRows}</ul> : null}
        </>
      )
    }

    if (attachments.attachments.length === 0 && attachments.pending.length === 0) {
      return (
        <EmptyState
          icon="attach"
          title="Nothing attached yet"
          description={
            canEdit
              ? `Add an image, a PDF or a recording — up to ${formatBytes(attachments.maxBytes)} each.`
              : 'Files added to this note will appear here.'
          }
          action={
            canEdit ? (
              <Button variant="primary" icon="upload" onClick={() => fileInputRef.current?.click()}>
                Add a file
              </Button>
            ) : undefined
          }
        />
      )
    }

    return (
      <ul className="att-list">
        {pendingRows}
        {attachments.attachments.map((attachment) => (
          <AttachmentBlock
            key={attachment.id}
            attachment={attachment}
            features={{ transcription, ocr }}
            canDelete={canEdit}
            deleting={attachments.removingId === attachment.id}
            onDelete={(target) => void onDelete(target)}
            onCheckAgain={attachments.checkAgain}
          />
        ))}
      </ul>
    )
  }

  return (
    <section className="att-panel" aria-labelledby={headingId}>
      <header className="att-panel__header">
        <h2 className="att-panel__title" id={headingId}>
          Attachments
          {attachments.attachments.length > 0 ? (
            <span className="att-panel__count"> ({attachments.attachments.length})</span>
          ) : null}
        </h2>

        {canEdit ? (
          <div className="att-panel__actions">
            <Button size="sm" icon="upload" onClick={() => fileInputRef.current?.click()}>
              Add file
            </Button>
            {canRecord ? (
              <Button size="sm" icon="mic" onClick={() => setDialog('voice')}>
                Record
              </Button>
            ) : null}
            <Button size="sm" icon="scan" onClick={() => setDialog('scan')}>
              Scan
            </Button>
            {driveEnabled ? (
              <Button size="sm" icon="cloud-check" onClick={() => setDialog('drive')}>
                From Drive
              </Button>
            ) : null}
          </div>
        ) : null}
      </header>

      <input
        ref={fileInputRef}
        type="file"
        multiple
        className="sr-only"
        tabIndex={-1}
        aria-hidden
        onChange={(event) => {
          void attachments.upload(event.target.files ?? [])
          event.target.value = ''
        }}
      />

      {panelError ? (
        <p className="att-panel__error" role="alert">
          <Icon name="alert" size={14} />
          <span>{panelError}</span>
          <Button
            size="sm"
            variant="ghost"
            icon="close"
            iconOnly
            aria-label="Dismiss this message"
            onClick={() => setPanelError(null)}
          />
        </p>
      ) : null}

      {attachments.watchExpired ? (
        <p className="att-panel__notice" role="status">
          <Icon name="info" size={14} />
          <span>
            Still processing after three minutes. This stopped checking so it does not keep asking
            forever.
          </span>
          <Button size="sm" variant="ghost" icon="refresh" onClick={attachments.checkAgain}>
            Check again
          </Button>
        </p>
      ) : null}

      <FileDropZone
        onFiles={(files) => void attachments.upload(files)}
        disabled={!canEdit}
        label="Drop files to attach them to this note"
        disabledReason="You do not have permission to add files to this note."
      >
        {body()}
      </FileDropZone>

      <Dialog
        open={dialog === 'voice'}
        onClose={close}
        title="Record a voice note"
        description="The recording is attached to this note when you save it."
        width={560}
      >
        {/* Mounted only while open, so the microphone is released the moment
            the dialog closes rather than when this panel unmounts. */}
        {dialog === 'voice' ? (
          <VoiceRecorder onCancel={close} onSave={(file) => uploadFromDialog([file])} />
        ) : null}
      </Dialog>

      <Dialog
        open={dialog === 'scan'}
        onClose={close}
        title="Scan a document"
        description="Capture each page, straighten it, then attach them all at once."
        width={720}
      >
        {dialog === 'scan' ? (
          <DocumentScanner onCancel={close} onSave={uploadFromDialog} />
        ) : null}
      </Dialog>

      <Dialog
        open={dialog === 'drive'}
        onClose={close}
        title="Attach a file from Drive"
        description="The file stays in Drive. This note links to it rather than copying it."
        width={480}
        footer={
          <>
            <Button variant="ghost" onClick={close} disabled={driveBusy}>
              Cancel
            </Button>
            <Button variant="primary" loading={driveBusy} onClick={() => void attachDrive()}>
              Attach
            </Button>
          </>
        }
      >
        <div className="editor-field">
          <label className="editor-field__label" htmlFor={driveFieldId}>
            File id from {driveOrigin().replace(/^https?:\/\//, '')}
          </label>
          <input
            id={driveFieldId}
            className="editor-field__input"
            data-autofocus
            value={driveInput}
            placeholder="4821"
            onChange={(event) => setDriveInput(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                void attachDrive()
              }
            }}
          />
          {driveError ? (
            <p className="editor-field__error" role="alert">
              {driveError}
            </p>
          ) : null}
        </div>
      </Dialog>
    </section>
  )
}
