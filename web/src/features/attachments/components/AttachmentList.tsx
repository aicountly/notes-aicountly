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
 * A Drive file id, from an id or from a link to it.
 *
 * People paste the address bar, not the id. Anything that is not a URL is
 * taken as an id and handed to the server, which is the thing that decides
 * whether it exists and whether this person may see it.
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
  const [removeError, setRemoveError] = useState<string | null>(null)
  const driveFieldId = useId()

  const canEdit = capabilities.edit
  const canRecord = isVoiceRecordingSupported()

  const close = () => {
    setDialog('none')
    setDriveInput('')
    setDriveError(null)
  }

  const onDelete = async (attachment: Attachment) => {
    setRemoveError(null)
    try {
      await attachments.remove(attachment.id)
    } catch (error) {
      setRemoveError(
        error instanceof ApiError ? error.message : `${attachment.filename} could not be removed.`,
      )
    }
  }

  const attachDrive = async () => {
    const id = parseDriveFileId(driveInput)
    if (id === '') {
      setDriveError('Paste a Drive link, or the file’s id.')
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
      return <ErrorNotice error={attachments.error} onRetry={attachments.refetch} />
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
        {attachments.pending.map((item) => (
          <PendingAttachmentBlock
            key={item.key}
            item={item}
            onRetry={attachments.retryPending}
            onDismiss={attachments.dismissPending}
          />
        ))}
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
    <section className="att-panel" aria-labelledby={`${driveFieldId}-heading`}>
      <header className="att-panel__header">
        <h2 className="att-panel__title" id={`${driveFieldId}-heading`}>
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

      {removeError ? (
        <p className="att-panel__error" role="alert">
          <Icon name="alert" size={14} />
          {removeError}
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
          <VoiceRecorder
            onCancel={close}
            onSave={async (file) => {
              await attachments.upload([file])
              close()
            }}
          />
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
          <DocumentScanner
            onCancel={close}
            onSave={async (files) => {
              await attachments.upload(files)
              close()
            }}
          />
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
            Drive link or file id
          </label>
          <input
            id={driveFieldId}
            className="editor-field__input"
            data-autofocus
            value={driveInput}
            placeholder="https://drive.aicountly.com/files/…"
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
