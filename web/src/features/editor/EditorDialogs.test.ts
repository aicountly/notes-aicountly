/**
 * The dialog and the sanitiser have to agree on what a link is.
 *
 * `safeUrl()` takes an `allowAppRelative` flag, and the image `src` path
 * passes it while the link `href` path does not. Accepting a `/`-relative
 * href here meant the editor stored a link the server then silently deleted
 * on save: the text stayed, the link was gone, and nothing said why.
 */

import { describe, expect, it } from 'vitest'

import { normaliseHref, normaliseImageSrc } from './EditorDialogs'

describe('normaliseHref', () => {
  it('assumes https for the bare domain people actually type', () => {
    expect(normaliseHref('example.com')).toEqual({ href: 'https://example.com' })
    expect(normaliseHref('  example.com/page  ')).toEqual({ href: 'https://example.com/page' })
  })

  it('keeps the schemes the sanitiser allows', () => {
    expect(normaliseHref('https://example.com')).toEqual({ href: 'https://example.com' })
    expect(normaliseHref('mailto:sam@example.com')).toEqual({ href: 'mailto:sam@example.com' })
    expect(normaliseHref('tel:+911234567890')).toEqual({ href: 'tel:+911234567890' })
    // An anchor within the note is allowed by the server without the flag.
    expect(normaliseHref('#section-2')).toEqual({ href: '#section-2' })
  })

  it('refuses a path, and says why rather than dropping it later', () => {
    const result = normaliseHref('/notes/abc')
    expect(result).toHaveProperty('error')
    expect('error' in result && result.error).toMatch(/full web address/)
  })

  it('refuses nothing at all', () => {
    expect(normaliseHref('   ')).toHaveProperty('error')
  })
})

describe('normaliseImageSrc', () => {
  it('accepts the app-relative address every uploaded image carries', () => {
    // `safeUrl(..., allowAppRelative: true)` is what a `src` is checked
    // against, so this really is different from a link.
    expect(normaliseImageSrc('/notes/n1/attachments/a1/content')).toEqual({
      href: '/notes/n1/attachments/a1/content',
    })
  })

  it('still refuses a scheme-relative address, as the sanitiser does', () => {
    expect(normaliseImageSrc('//evil.example/logo.png')).toHaveProperty('error')
  })

  it('takes an ordinary web address too', () => {
    expect(normaliseImageSrc('example.com/logo.png')).toEqual({
      href: 'https://example.com/logo.png',
    })
  })
})
