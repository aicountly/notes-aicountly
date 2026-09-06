/**
 * What a row is allowed to say and to show.
 *
 * Two invariants worth a regression test: a deployment that cannot transcribe
 * never displays "Transcribing…", and nothing but a known-safe image or PDF is
 * ever put in the page.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'

import { AttachmentBlock, PendingAttachmentBlock, describeStatus } from './AttachmentBlock'
import type { Attachment } from '../../../shared/api/types'
import type { PendingUpload } from '../hooks/useAttachments'

// The content endpoint wants the session's Bearer token, so a preview is a
// real fetch rather than an <img src> the browser resolves on its own.
vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'ses-key' }))

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

let fetched: string[] = []

beforeEach(() => {
  fetched = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) => {
      fetched.push(String(url))
      return { ok: true, status: 200, blob: async () => new Blob(['bytes']) } as unknown as Response
    }),
  )
  vi.stubGlobal('URL', Object.assign(URL, {
    createObjectURL: vi.fn(() => 'blob:preview'),
    revokeObjectURL: vi.fn(),
  }))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

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

  it('previews an image once the bytes arrive with the session attached', async () => {
    show({ kind: 'image', mime_type: 'image/png', filename: 'chart.png', duration_seconds: null })

    const image = await screen.findByRole('img', { name: 'chart.png' })

    expect(image).toHaveAttribute('src', 'blob:preview')
    // Not the raw content URL on an <img>: that request carries no
    // Authorization header and comes back 401.
    expect(fetched).toHaveLength(1)
    expect(fetched[0]).toContain('/notes/n1/attachments/a1/content')
  })

  it('says so rather than showing an empty strip when a preview cannot be read', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 404 }) as unknown as Response))
    show({ kind: 'image', mime_type: 'image/png', filename: 'chart.png', duration_seconds: null })

    expect(await screen.findByText(/no longer stored/i)).toBeInTheDocument()
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
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
    expect(fetched).toHaveLength(0)
    // Offered as a download and as nothing else.
    expect(screen.getByText('payload.html')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Download payload\.html/ })).toBeInTheDocument()
  })

  it('keeps a PDF behind a Preview control instead of loading it into every row', async () => {
    show({ kind: 'pdf', mime_type: 'application/pdf', filename: 'invoice.pdf', duration_seconds: null })

    expect(document.querySelector('object')).toBeNull()
    expect(fetched).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))

    expect(await screen.findByLabelText('Preview of invoice.pdf')).toBeInTheDocument()
    expect(fetched).toHaveLength(1)
  })

  it('hides the remove control from someone who may not edit the note', () => {
    render(
      <ul>
        <AttachmentBlock attachment={BASE} features={ON} canDelete={false} />
      </ul>,
    )

    expect(screen.queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Download/ })).toBeInTheDocument()
  })
})

describe('<PendingAttachmentBlock />', () => {
  const queued: PendingUpload = {
    key: 'k1',
    file: new File(['x'], 'holiday.png', { type: 'image/png' }),
    filename: 'holiday.png',
    byteSize: 2048,
    kind: 'image',
    previewUrl: null,
    uploading: false,
    error: null,
    retryable: true,
  }

  function pending(overrides: Partial<PendingUpload>) {
    const onRetry = vi.fn()
    const onDismiss = vi.fn()
    render(
      <ul>
        <PendingAttachmentBlock item={{ ...queued, ...overrides }} onRetry={onRetry} onDismiss={onDismiss} />
      </ul>,
    )
    return { onRetry, onDismiss }
  }

  it('offers a retry for an upload the network lost', () => {
    const { onRetry } = pending({ error: 'You appear to be offline.', retryable: true })

    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))

    expect(onRetry).toHaveBeenCalledWith('k1')
  })

  it('offers no retry for a file that can never be accepted', () => {
    pending({ error: 'holiday.png is 40 MB. This deployment accepts files up to 25 MB.', retryable: false })

    expect(screen.getByText(/accepts files up to 25 MB/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument()
    // The row can still be cleared away.
    expect(screen.getByRole('button', { name: /Remove holiday\.png from the queue/ })).toBeInTheDocument()
  })

  it('shows an indeterminate bar rather than a percentage it cannot know', () => {
    pending({})

    const bar = screen.getByRole('progressbar', { name: 'Uploading holiday.png' })

    expect(bar).not.toHaveAttribute('aria-valuenow')
  })
})
