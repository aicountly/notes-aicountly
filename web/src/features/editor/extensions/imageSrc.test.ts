/**
 * Which image sources this app fetches on the reader's behalf.
 *
 * The consequence of getting it wrong in either direction is visible: too
 * narrow and an uploaded image renders as a broken icon, because the canonical
 * attachment URL is behind the session's Bearer token and an `<img>` sends no
 * such header; too wide and the app fetches arbitrary external URLs through
 * its own session.
 */

import { describe, expect, it } from 'vitest'

import { isAttachmentSrc } from './imageSrc'

describe('isAttachmentSrc', () => {
  it('claims an attachment on this API, however it was written', () => {
    // The same document is opened on production, on sandbox and on localhost,
    // so an absolute URL saved on one host must still resolve on the others.
    expect(isAttachmentSrc('/api/notes/n1/attachments/a1/content')).toBe(true)
    expect(isAttachmentSrc('https://notes.aicountly.com/api/notes/n1/attachments/a1/content')).toBe(true)
    expect(isAttachmentSrc('http://localhost:5173/api/notes/n1/attachments/a1/content')).toBe(true)
  })

  it('leaves anything that is not ours alone', () => {
    expect(isAttachmentSrc('https://example.test/logo.png')).toBe(false)
    expect(isAttachmentSrc('https://notes.aicountly.com/api/notes/n1/attachments')).toBe(false)
    expect(isAttachmentSrc('')).toBe(false)
    // Not a URL at all: `new URL` throws, and a throw must not be read as a
    // yes.
    expect(isAttachmentSrc('https://')).toBe(false)
  })
})
