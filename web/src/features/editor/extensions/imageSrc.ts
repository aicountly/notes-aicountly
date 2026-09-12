/**
 * Turning a stored image `src` into one the browser can actually load.
 *
 * An image uploaded to a note is stored in the document as the canonical
 * attachment URL — `/api/notes/{id}/attachments/{aid}/content` — and that is
 * the right thing to store: it is stable, it survives a reload, and it names
 * the row that owns the file. It is also **behind the session's Bearer token**,
 * and an `<img>` sends no Authorization header, so the browser's own request
 * for it comes back as a 401 JSON envelope and the reader sees a broken image.
 *
 * So the document keeps the canonical URL and the view resolves it: the bytes
 * are fetched with the session attached and handed to the DOM as an object
 * URL. Nothing about the saved document changes, which matters because the
 * alternative — storing a `blob:` address — is dead the moment the tab closes.
 *
 * An external `https://` image in a pasted document is left exactly as it is.
 * It needs no credentials of ours and fetching it through this API would be
 * both pointless and a way to make the server fetch arbitrary URLs.
 */

/** How a loader is handed to the node view. */
export type ImageSrcLoader = (src: string, signal: AbortSignal) => Promise<string>

/**
 * Does this `src` point at an attachment on this API?
 *
 * Matched on the path rather than the origin: the same document is opened on
 * `notes.aicountly.com`, on a sandbox host and on localhost, and an absolute
 * URL written on one of those must still resolve on the others. Anything else
 * — an external image, a data: URI the sanitiser would have stripped anyway —
 * is not ours to fetch.
 */
export function isAttachmentSrc(src: string): boolean {
  if (src === '') return false

  const path = src.startsWith('http://') || src.startsWith('https://')
    ? safePath(src)
    : src

  return /\/notes\/[^/]+\/attachments\/[^/]+\/content\/?$/.test(path)
}

function safePath(src: string): string {
  try {
    return new URL(src).pathname
  } catch {
    return ''
  }
}
