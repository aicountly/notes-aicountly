/**
 * The parts of the attachments feature that decide something before the
 * network is involved: what may be offered to the server, and how long this
 * app is willing to keep asking whether a file has finished processing.
 */

import { describe, expect, it } from 'vitest'

import {
  describeFileRejection,
  fileExtension,
  formatBytes,
  isProcessing,
  kindFromMimeType,
  pollIntervalFor,
} from './useAttachments'
import type { Attachment } from '../../../shared/api/types'

const LIMIT = 25 * 1024 * 1024

function fileOf(name: string, size: number, type = ''): File {
  const file = new File(['x'], name, { type })
  // `File` takes its size from the parts it was built from, and building a
  // 26 MB string in a test is a waste of a second.
  Object.defineProperty(file, 'size', { value: size })
  return file
}

describe('describeFileRejection', () => {
  it('accepts an ordinary image', () => {
    expect(describeFileRejection(fileOf('holiday.png', 400_000, 'image/png'), LIMIT)).toBeNull()
  })

  it('names both sizes when the file is over the limit', () => {
    const message = describeFileRejection(fileOf('scan.pdf', 40 * 1024 * 1024), LIMIT)

    expect(message).toContain('40 MB')
    expect(message).toContain('25 MB')
  })

  it('refuses an empty file rather than uploading nothing', () => {
    expect(describeFileRejection(fileOf('empty.txt', 0), LIMIT)).toContain('is empty')
  })

  it.each(['page.html', 'logo.svg', 'setup.exe', 'deploy.sh'])(
    'refuses %s before it reaches the server',
    (name) => {
      expect(describeFileRejection(fileOf(name, 1024), LIMIT)).toContain('cannot be attached')
    },
  )

  it('is case insensitive about the extension', () => {
    expect(describeFileRejection(fileOf('Page.HTML', 1024), LIMIT)).not.toBeNull()
  })

  it('does not refuse a name that merely contains a refused word', () => {
    expect(describeFileRejection(fileOf('html-notes.pdf', 1024), LIMIT)).toBeNull()
  })
})

describe('fileExtension', () => {
  it('reads the last extension', () => {
    expect(fileExtension('report.final.pdf')).toBe('pdf')
  })

  it('is empty for a name with no extension', () => {
    expect(fileExtension('README')).toBe('')
  })

  it('treats a dotfile as having no extension', () => {
    expect(fileExtension('.htaccess')).toBe('')
  })
})

describe('formatBytes', () => {
  it('reads in the unit a person would use', () => {
    expect(formatBytes(512)).toBe('512 bytes')
    expect(formatBytes(2048)).toBe('2 KB')
    expect(formatBytes(3.5 * 1024 * 1024)).toBe('3.5 MB')
    // Ten megabytes and up lose the decimal — a file is "42 MB", not "42.0 MB".
    expect(formatBytes(42 * 1024 * 1024)).toBe('42 MB')
  })
})

describe('kindFromMimeType', () => {
  it('picks the icon family from the type', () => {
    expect(kindFromMimeType('image/jpeg')).toBe('image')
    expect(kindFromMimeType('application/pdf')).toBe('pdf')
    expect(kindFromMimeType('audio/webm')).toBe('audio')
    expect(kindFromMimeType('video/mp4')).toBe('video')
    expect(kindFromMimeType('text/markdown')).toBe('text')
    expect(kindFromMimeType('application/octet-stream')).toBe('file')
  })
})

describe('pollIntervalFor', () => {
  it('backs off 2s, 4s, 8s and then settles at 15s', () => {
    expect(pollIntervalFor(0)).toBe(2_000)
    expect(pollIntervalFor(2_000)).toBe(4_000)
    expect(pollIntervalFor(6_000)).toBe(8_000)
    expect(pollIntervalFor(14_000)).toBe(15_000)
    expect(pollIntervalFor(60_000)).toBe(15_000)
  })

  it('stops after three minutes rather than polling forever', () => {
    expect(pollIntervalFor(179_000)).toBe(15_000)
    expect(pollIntervalFor(180_000)).toBe(false)
    expect(pollIntervalFor(10 * 60_000)).toBe(false)
  })
})

describe('isProcessing', () => {
  const base = { processing_status: 'completed' } as Attachment

  it('is true only while work is outstanding', () => {
    expect(isProcessing({ ...base, processing_status: 'queued' })).toBe(true)
    expect(isProcessing({ ...base, processing_status: 'processing' })).toBe(true)
    expect(isProcessing({ ...base, processing_status: 'completed' })).toBe(false)
    expect(isProcessing({ ...base, processing_status: 'failed' })).toBe(false)
    expect(isProcessing({ ...base, processing_status: 'skipped' })).toBe(false)
  })
})
