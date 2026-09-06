/**
 * A link from one note to another.
 *
 * Stored as a node carrying the target's **id**, never its title. The server
 * turns those ids into rows in `note_links`, which is what makes backlinks work
 * and what keeps a link alive when the target note is renamed — the thing
 * parsing `[[Title]]` at render time cannot do.
 *
 * The chip is an `<a>` with a real href so it survives a copy into another
 * app, but inside the editor a click is intercepted: navigating away from a
 * contenteditable by accident loses the caret and, in a slow save, the words.
 */

import { Node, mergeAttributes } from '@tiptap/core'
import { NodeSelection, Plugin, PluginKey } from '@tiptap/pm/state'

export interface NoteLinkOptions {
  /** Called when the reader activates a chip. */
  onOpenNote: ((noteId: string) => void) | null
}

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    noteLink: {
      insertNoteLink: (attributes: { noteId: string; label: string }) => ReturnType
    }
  }
}

export const NoteLink = Node.create<NoteLinkOptions>({
  name: 'noteLink',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,

  addOptions() {
    return { onOpenNote: null }
  },

  addAttributes() {
    return {
      noteId: {
        default: '',
        parseHTML: (element) => element.getAttribute('data-note-id') ?? '',
        renderHTML: (attributes) => ({ 'data-note-id': String(attributes.noteId ?? '') }),
      },
      label: {
        default: '',
        parseHTML: (element) => element.getAttribute('data-label') ?? element.textContent ?? '',
        renderHTML: (attributes) => ({ 'data-label': String(attributes.label ?? '') }),
      },
    }
  },

  parseHTML() {
    return [{ tag: 'a[data-note-id]' }]
  },

  renderHTML({ node, HTMLAttributes }) {
    const label = String(node.attrs.label ?? '') || 'Untitled note'

    return [
      'a',
      mergeAttributes(HTMLAttributes, {
        class: 'note-link-chip',
        href: `/notes/${String(node.attrs.noteId ?? '')}`,
      }),
      label,
    ]
  },

  renderText({ node }) {
    return `[[${String(node.attrs.label ?? '')}]]`
  },

  addCommands() {
    return {
      insertNoteLink:
        (attributes) =>
        ({ chain }) =>
          chain()
            .focus()
            // The trailing space is what lets the writer keep typing after the
            // chip instead of landing back inside an atom they cannot edit.
            .insertContent([
              { type: this.name, attrs: attributes },
              { type: 'text', text: ' ' },
            ])
            .run(),
    }
  },

  addKeyboardShortcuts() {
    return {
      // A chip selected with the keyboard opens on Enter. Returning false when
      // the selection is anything else leaves ordinary Enter alone.
      Enter: () => {
        const { selection } = this.editor.state
        if (!(selection instanceof NodeSelection) || selection.node.type.name !== this.name) {
          return false
        }
        const noteId: unknown = selection.node.attrs.noteId
        if (typeof noteId !== 'string' || noteId === '') return false
        this.options.onOpenNote?.(noteId)
        return true
      },
    }
  },

  addProseMirrorPlugins() {
    return [
      new Plugin({
        key: new PluginKey('noteLinkClick'),
        props: {
          handleClickOn: (_view, _pos, node, _nodePos, event) => {
            if (node.type.name !== this.name) return false
            const noteId: unknown = node.attrs.noteId
            if (typeof noteId !== 'string' || noteId === '') return false

            event.preventDefault()
            this.options.onOpenNote?.(noteId)
            return true
          },
        },
      }),
    ]
  },
})
