/**
 * The two promises the service worker has to keep.
 *
 * It is a plain script in `public/`, not a module — it cannot be imported and
 * exercised here, and a fake ServiceWorkerGlobalScope would be a test of the
 * fake. What can be checked is the contract the rest of the app is built on,
 * which is exactly where it went wrong: `install` used to call `skipWaiting()`,
 * so the new build activated on its own, `registration.waiting` was empty, and
 * the "Update available" prompt had nothing to post to. Activate also deletes
 * the previous cache, which can strand a lazily-loaded chunk an open page has
 * not fetched yet.
 */

import { describe, expect, it } from 'vitest'

// Imported through Vite's `?raw` rather than read with `node:fs`: this file is
// type-checked against the browser tsconfig, which has no Node types, and
// `import.meta.url` under jsdom is an http: URL that `readFileSync` cannot
// open anyway.
import source from '../../public/sw.js?raw'

/**
 * The body of one `self.addEventListener('<name>', …)` handler, comments
 * stripped — a comment explaining why `skipWaiting()` is *not* called would
 * otherwise read as a call to it.
 */
function handler(name: string): string {
  const start = source.indexOf(`self.addEventListener('${name}'`)
  expect(start, `${name} handler exists`).toBeGreaterThan(-1)
  const next = source.indexOf('self.addEventListener(', start + 1)
  const body = source.slice(start, next === -1 ? undefined : next)

  return body.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '')
}

describe('the service worker', () => {
  it('waits to be told before taking over, so the update prompt means something', () => {
    expect(handler('install')).not.toContain('skipWaiting')
  })

  it('still takes over when the reader accepts', () => {
    // `applyUpdate()` posts this exact message and then reloads.
    expect(handler('message')).toContain("'skip-waiting'")
    expect(handler('message')).toContain('skipWaiting')
  })

  it('never caches the API', () => {
    expect(source).toContain("url.pathname.startsWith('/api/')")
  })

  it('keeps only a real shell as the offline page', () => {
    // A 502 from the origin used to become the offline shell, and every later
    // offline visit rendered the error page instead of the app.
    const navigations = handler('fetch')
    expect(navigations).toContain('response.ok && response.type')
  })
})
