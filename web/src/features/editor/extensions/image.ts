/**
 * The image node, widened to carry its attachment.
 *
 * Tiptap's Image stores `src`, `alt` and `title`. The server also stores
 * `attachmentId`, and without it an image in a document has no link back to
 * the row that owns the file — so deleting the note could not clean up the
 * upload, and a re-signed URL could not be swapped in.
 */

import Image from '@tiptap/extension-image'

export const NoteImage = Image.extend({
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
