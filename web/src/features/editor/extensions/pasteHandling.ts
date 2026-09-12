/**
 * What happens when someone pastes.
 *
 * Three behaviours people expect from a modern editor, and one piece of
 * hygiene:
 *
 *   - a URL pasted **over a selection** turns that selection into a link
 *     rather than replacing the words with the address;
 *   - **plain text stays plain** — that is Tiptap's default and nothing here
 *     interferes with it;
 *   - **rich HTML** is parsed by Tiptap, which keeps only the nodes and marks
 *     in the schema. Before it gets there this strips `style` and every `on*`
 *     handler: the schema would drop them on the way to the server anyway, but
 *     they would live in the DOM of *this* session in the meantime, and a
 *     pasted `style` is also how a document ends up with colours that vanish
 *     in dark mode.
 *   - an **image file** is uploaded and inserted, when something is available
 *     to accept it. When nothing is, the paste is left alone rather than
 *     inserting a data: URL the server's sanitiser will reject.
 */

import { Extension } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'

export interface UploadedImage {
  src: string
  attachmentId?: string
  alt?: string
  width?: number
  height?: number
}

/** Implemented by the attachments feature; see NoteEditor's `uploadImage` prop. */
export type ImageUploader = (file: File) => Promise<UploadedImage>

export interface PasteHandlingOptions {
  uploadImage: (() => ImageUploader | null) | null
  onError: (message: string) => void
  /**
   * Called around an upload started by a paste.
   *
   * Without it the paste is silent: the handler consumes the event, the file
   * goes up over however many seconds a phone connection takes, and nothing on
   * screen says anything is happening.
   */
  onUploading: (uploading: boolean) => void
}

const ABSOLUTE_URL = /^(https?:\/\/|mailto:|tel:)[^\s<>"]+$/i

export function isPastableUrl(text: string): boolean {
  return ABSOLUTE_URL.test(text.trim())
}

/**
 * Remove the attributes a paste has no business carrying.
 *
 * Deliberately narrow: element and attribute filtering proper is the schema's
 * job, and duplicating it here would produce a second allowlist to keep in step
 * with the server's.
 */
export function stripUnsafeMarkup(html: string): string {
  const parsed = new DOMParser().parseFromString(html, 'text/html')

  parsed.body.querySelectorAll('script, style, link, iframe, object, embed').forEach((element) => {
    element.remove()
  })

  parsed.body.querySelectorAll('*').forEach((element) => {
    for (const attribute of Array.from(element.attributes)) {
      const name = attribute.name.toLowerCase()
      if (name === 'style' || name === 'srcdoc' || name.startsWith('on')) {
        element.removeAttribute(attribute.name)
      }
    }
  })

  return parsed.body.innerHTML
}

export const PasteHandling = Extension.create<PasteHandlingOptions>({
  name: 'pasteHandling',

  addOptions() {
    return { uploadImage: null, onError: () => undefined, onUploading: () => undefined }
  },

  addProseMirrorPlugins() {
    const { editor } = this

    return [
      new Plugin({
        key: new PluginKey('pasteHandling'),
        props: {
          transformPastedHTML: (html) => stripUnsafeMarkup(html),

          handlePaste: (view, event) => {
            const clipboard = event.clipboardData
            if (!clipboard) return false

            const image = Array.from(clipboard.files).find((file) =>
              file.type.startsWith('image/'),
            )
            if (image) {
              const upload = this.options.uploadImage?.()
              // Nothing can accept the file: fall through rather than inserting
              // a base64 src the server will strip on the next save.
              if (!upload) return false

              this.options.onUploading(true)
              void upload(image)
                .then((uploaded) => {
                  editor
                    .chain()
                    .focus()
                    .insertContent({
                      type: 'image',
                      attrs: {
                        src: uploaded.src,
                        alt: uploaded.alt ?? image.name,
                        attachmentId: uploaded.attachmentId ?? null,
                        width: uploaded.width ?? null,
                        height: uploaded.height ?? null,
                      },
                    })
                    .run()
                })
                .catch((error: unknown) => {
                  this.options.onError(
                    error instanceof Error ? error.message : 'That image could not be added.',
                  )
                })
                .finally(() => this.options.onUploading(false))

              return true
            }

            const text = clipboard.getData('text/plain').trim()
            if (text !== '' && isPastableUrl(text) && !view.state.selection.empty) {
              editor.chain().focus().extendMarkRange('link').setLink({ href: text }).run()
              return true
            }

            return false
          },
        },
      }),
    ]
  },
})
