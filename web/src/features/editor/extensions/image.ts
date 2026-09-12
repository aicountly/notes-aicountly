/**
 * The image node, widened to carry its attachment.
 *
 * Tiptap's Image stores `src`, `alt` and `title`. The server also stores
 * `attachmentId`, and without it an image in a document has no link back to
 * the row that owns the file — so deleting the note could not clean up the
 * upload, and a re-signed URL could not be swapped in.
 */

import Image from '@tiptap/extension-image'

import { isAttachmentSrc } from './imageSrc'
import type { ImageSrcLoader } from './imageSrc'

declare module '@tiptap/extension-image' {
  interface ImageOptions {
    /**
     * Fetches an attachment URL with the session attached and answers with
     * something the DOM can load. Injected rather than imported, the same way
     * the uploader is: the editor stays a document component that neither
     * fetches nor uploads, and a test can render it without a network.
     */
     loadSrc?: ImageSrcLoader | null
  }
}

export const NoteImage = Image.extend({
  /**
   * Render the image through the session.
   *
   * Without this the node emits a bare `<img src>` at a Bearer-only endpoint,
   * the browser's unauthenticated request comes back 401, and every image
   * anyone uploads is a broken icon — see {@link isAttachmentSrc} for why the
   * document nonetheless stores that URL rather than a blob.
   *
   * The element the editor already built is kept and only its `src` swapped,
   * so width, height, alt and the attachment id all behave exactly as they did
   * and ProseMirror's own selection handling is untouched.
   */
  addNodeView() {
    return ({ node, HTMLAttributes }) => {
      const dom = document.createElement('img')
      for (const [name, value] of Object.entries(HTMLAttributes)) {
        if (value !== null && value !== undefined) dom.setAttribute(name, String(value))
      }

      const src = typeof node.attrs.src === 'string' ? node.attrs.src : ''
      const load = this.options.loadSrc
      const controller = new AbortController()
      let objectUrl: string | null = null

      if (load && isAttachmentSrc(src)) {
        // Blank until it resolves, rather than letting the browser try the
        // canonical URL first and paint a broken image on the way.
        dom.removeAttribute('src')
        dom.setAttribute('data-loading', 'true')

        void load(src, controller.signal)
          .then((resolved) => {
            if (controller.signal.aborted) return
            objectUrl = resolved
            dom.src = resolved
            dom.removeAttribute('data-loading')
          })
          .catch(() => {
            if (controller.signal.aborted) return
            dom.removeAttribute('data-loading')
            dom.setAttribute('data-error', 'true')
            // The alt text is what a reader is left with, so it says what
            // happened rather than staying silent about it.
            dom.alt = typeof node.attrs.alt === 'string' && node.attrs.alt !== ''
              ? `${String(node.attrs.alt)} (could not be loaded)`
              : 'This image could not be loaded.'
          })
      }

      return {
        dom,
        destroy() {
          controller.abort()
          if (objectUrl) URL.revokeObjectURL(objectUrl)
        },
      }
    }
  },

  addAttributes() {
    return {
      ...this.parent?.(),
      attachmentId: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-attachment-id'),
        renderHTML: (attributes) => {
          const id: unknown = attributes.attachmentId
          return typeof id === 'string' && id !== '' ? { 'data-attachment-id': id } : {}
        },
      },
      width: {
        default: null,
        parseHTML: (element) => element.getAttribute('width'),
        renderHTML: (attributes) => (attributes.width ? { width: attributes.width } : {}),
      },
      height: {
        default: null,
        parseHTML: (element) => element.getAttribute('height'),
        renderHTML: (attributes) => (attributes.height ? { height: attributes.height } : {}),
      },
    }
  },
})
