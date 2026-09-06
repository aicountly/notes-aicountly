/**
 * Register the service worker, and notice when a new build lands.
 *
 * The worker is skipped entirely on localhost: a stale cached shell during
 * development is a confusing hour spent debugging code that is not running.
 */

let updateReady = false
const listeners = new Set<(ready: boolean) => void>()

export function onUpdateReady(listener: (ready: boolean) => void): () => void {
  listeners.add(listener)
  listener(updateReady)
  return () => {
    listeners.delete(listener)
  }
}

function publish(ready: boolean): void {
  updateReady = ready
  for (const listener of listeners) listener(ready)
}

export function registerServiceWorker(): void {
  if (!('serviceWorker' in navigator)) return
  if (window.location.hostname === 'localhost' || window.location.hostname.startsWith('127.')) return
  if (window.location.protocol !== 'https:') return

  window.addEventListener('load', () => {
    void navigator.serviceWorker
      .register('/sw.js')
      .then((registration) => {
        registration.addEventListener('updatefound', () => {
          const installing = registration.installing
          if (!installing) return

          installing.addEventListener('statechange', () => {
            // "installed" with a controller already present means a new build
            // is waiting. It is not applied silently: reloading under someone
            // mid-sentence would lose the sentence.
            if (installing.state === 'installed' && navigator.serviceWorker.controller) {
              publish(true)
            }
          })
        })
      })
      .catch(() => {
        /* offline support is optional; the app works without it */
      })
  })
}

/** Apply a waiting update and reload. Called from the "Update available" prompt. */
export function applyUpdate(): void {
  void navigator.serviceWorker.getRegistration().then((registration) => {
    registration?.waiting?.postMessage('skip-waiting')
    window.location.reload()
  })
}
