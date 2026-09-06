/**
 * What a row is allowed to say and to show.
 *
 * Two invariants worth a regression test: a deployment that cannot transcribe
 * never displays "Transcribing…", and nothing but a known-safe image or PDF is
 * ever put in the page.
 */

import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'

import { AttachmentBlock, describeStatus } from './AttachmentBlock'
import type { Attachment } from '../../../shared/api/types'

const BASE: Attachment = {
  id: 'a1',
  note_id: 'n1',
  block_id: null,
  filename: 'meeting.weba',
  mime_type: 'audio/webm',
  byte_size: 1_400_000,
  kind: 'audio',
  duration_seconds: 95,
  width: null,
  height: null,
  upload_status: 'ready',
  processing_status: 'queued',
  processing_error: null,
  storage_provider: 'local',
  drive_file_id: null,
  content_url: '/notes/n1/attachments/a1/content',
  thumbnail_url: null,
  created_at: '2026-09-06T10:00:00Z',
}

const ON = { transcription: true, ocr: true }
const OFF = { transcription: false, ocr: false }

function show(attachment: Partial<Attachment>, features = ON, onCheckAgain = vi.fn()) {
  render(
    <ul>
      <AttachmentBlock
        attachment={{ ...BASE, ...attachment }}
        features={features}
        canDelete
        onDelete={vi.fn()}
        onCheckAgain={onCheckAgain}
      />
    </ul>,
  )
  return { onCheckAgain }
}

describe('describeStatus', () => {
  it('names the job that is running', () => {
    expect(describeStatus({ ...BASE, processing_status: 'processing' }, ON)?.text).toBe('Transcribing…')
    expect(
      describeStatus({ ...BASE, kind: 'pdf', processing_status: 'processing' }, ON)?.text,
    ).toBe('Extracting text…')
  })

  it('never promises a transcript the deployment cannot produce', () => {
    const status = describeStatus(BASE, OFF)

    expect(status?.tone).toBe('muted')
    expect(status?.text).toContain('Transcription is not enabled')
  })

  it('says nothing at all when no job applies to the file', () => {
    expect(describeStatus({ ...BASE, kind: 'file', processing_status: 'skipped' }, ON)).toBeNull()
  })

  it('reports the server’s own reason for a failure', () => {
    const status = describeStatus(
      { ...BASE, processing_status: 'failed', processing_error: 'The audio was silent.' },
      ON,
    )

    expect(status?.tone).toBe('failed')
    expect(status?.detail).toBe('The audio was silent.')
  })
})

describe('<AttachmentBlock />', () => {
  it('shows the filename, size and length', () => {
    show({})

    expect(screen.getByText('meeting.weba')).toBeInTheDocument()
    expect(screen.getByText(/1\.3 MB/)).toBeInTheDocument()
    expect(screen.getByText(/1:35/)).toBeInTheDocument()
  })

  it('offers a way to look again when processing failed', () => {
    const { onCheckAgain } = show({ processing_status: 'failed', processing_error: 'Timed out.' })

    fireEvent.click(screen.getByRole('button', { name: 'Check again' }))

    expect(onCheckAgain).toHaveBeenCalled()
  })

  it('previews an image', () => {
    show({ kind: 'image', mime_type: 'image/png', filename: 'chart.png', duration_seconds: null })

    expect(screen.getByRole('img', { name: 'chart.png' })).toBeInTheDocument()
  })

  it('never renders an HTML file inline, whatever the row claims to be', () => {
    show({
      kind: 'image',
      mime_type: 'text/html',
      filename: 'payload.html',
      duration_seconds: null,
    })

    expect(screen.queryByRole('img')).not.toBeInTheDocument()
    expect(document.querySelector('object')).toBeNull()
    expect(screen.getByRole('link', { name: 'payload.html' })).toBeInTheDocument()
  })

  it('keeps a PDF behind a Preview control instead of loading it into every row', () => {
    show({ kind: 'pdf', mime_type: 'application/pdf', filename: 'invoice.pdf', duration_seconds: null })

    expect(document.querySelector('object')).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))
    expect(document.querySelector('object')).not.toBeNull()
  })

  it('hides the remove control from someone who may not edit the note', () => {
    render(
      <ul>
        <AttachmentBlock attachment={BASE} features={ON} canDelete={false} />
      </ul>,
    )

    expect(screen.queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Download/ })).toBeInTheDocument()
  })
})
