/**
 * The browser jsdom does not quite provide.
 *
 * Everything here is a *narrow* stand-in for an API jsdom leaves out, installed
 * only when it is genuinely missing so a future jsdom that implements it wins.
 * Nothing here changes behaviour the tests then assert on — a stub that lies is
 * worse than a missing API, because the suite goes green over a broken app.
 */

import '@testing-library/jest-dom/vitest'

// ---------------------------------------------------------------------------
// matchMedia — read by the theme provider and by every reduced-motion check
// ---------------------------------------------------------------------------

if (typeof window.matchMedia !== 'function') {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => undefined,
    removeListener: () => undefined,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}

// ---------------------------------------------------------------------------
// IndexedDB — the offline store's home, which jsdom has no implementation of
// ---------------------------------------------------------------------------

interface StubOpenRequest {
  onerror: (() => void) | null
  onsuccess: (() => void) | null
  onupgradeneeded: (() => void) | null
  onblocked: (() => void) | null
  result: null
  error: null
}

/**
 * `openDatabase()` already treats an unavailable database as "no local cache"
 * — the same path a hardened browser profile takes — so the stub reports
 * failure rather than pretending to store anything. Asynchronously, because
 * callers attach their handlers after `open()` returns.
 */
function failingRequest(): StubOpenRequest {
  const request: StubOpenRequest = {
    onerror: null,
    onsuccess: null,
    onupgradeneeded: null,
    onblocked: null,
    result: null,
    error: null,
  }
  setTimeout(() => request.onerror?.(), 0)
  return request
}

if (!('indexedDB' in window)) {
  Object.defineProperty(window, 'indexedDB', {
    configurable: true,
    value: {
      open: failingRequest,
      deleteDatabase: failingRequest,
      cmp: () => 0,
      databases: () => Promise.resolve([]),
    },
  })
}

// ---------------------------------------------------------------------------
// Layout APIs the editor and its floating menus call into
// ---------------------------------------------------------------------------

if (typeof window.ResizeObserver !== 'function') {
  window.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}

if (typeof Element.prototype.scrollIntoView !== 'function') {
  Element.prototype.scrollIntoView = () => undefined
}

// ProseMirror measures the caret to decide where to scroll. jsdom has no
// layout, so an unstubbed Range throws instead of returning an empty box.
if (typeof Range.prototype.getBoundingClientRect !== 'function') {
  Range.prototype.getBoundingClientRect = () => new DOMRect(0, 0, 0, 0)
}
if (typeof Range.prototype.getClientRects !== 'function') {
  Range.prototype.getClientRects = () =>
    ({ length: 0, item: () => null, [Symbol.iterator]: function* () {} }) as unknown as DOMRectList
}
