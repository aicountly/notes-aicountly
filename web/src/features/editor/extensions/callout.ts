/**
 * The callout block — a passage the writer wants to stand out.
 *
 * Rendered as a wrapper around ordinary blocks rather than as a leaf with its
 * own text field, so everything else in the editor keeps working inside it:
 * lists, headings, links, the lot.
 *
 * The tone is carried in `aria-label`, not only in the colour. A yellow panel
 * says nothing to a screen reader, and colour on its own is never a signal in
 * this product.
 */

import { Node, mergeAttributes } from '@tiptap/core'

export const CALLOUT_TONES = ['info', 'warning', 'success', 'danger'] as const
export type CalloutTone = (typeof CALLOUT_TONES)[number]

const TONE_LABELS: Record<CalloutTone, string> = {
  info: 'Note',
  warning: 'Warning',
  success: 'Success',
  danger: 'Important',
}

function toTone(value: unknown): CalloutTone {
  return CALLOUT_TONES.includes(value as CalloutTone) ? (value as CalloutTone) : 'info'
}

export interface CalloutOptions {
  HTMLAttributes: Record<string, string>
}

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    callout: {
      /** Wrap the selection in a callout. */
      setCallout: (tone?: CalloutTone) => ReturnType
      /** Wrap, or unwrap when the selection is already in one. */
      toggleCallout: (tone?: CalloutTone) => ReturnType
      unsetCallout: () => ReturnType
    }
  }
}

export const Callout = Node.create<CalloutOptions>({
  name: 'callout',
  group: 'block',
  content: 'block+',
  defining: true,

  addOptions() {
    return { HTMLAttributes: {} }
  },

  addAttributes() {
    return {
      tone: {
        default: 'info',
        parseHTML: (element) => toTone(element.getAttribute('data-tone')),
        renderHTML: (attributes) => ({ 'data-tone': toTone(attributes.tone) }),
      },
    }
  },

  parseHTML() {
    return [{ tag: 'div[data-type="callout"]' }]
  },

  renderHTML({ node, HTMLAttributes }) {
    const tone = toTone(node.attrs.tone)

    return [
      'div',
      mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
        'data-type': 'callout',
        class: 'callout',
        role: 'note',
        'aria-label': TONE_LABELS[tone],
      }),
      0,
    ]
  },

  addCommands() {
    return {
      setCallout:
        (tone = 'info') =>
        ({ commands }) =>
          commands.wrapIn(this.name, { tone }),
      toggleCallout:
        (tone = 'info') =>
        ({ commands }) =>
          commands.toggleWrap(this.name, { tone }),
      unsetCallout:
        () =>
        ({ commands }) =>
          commands.lift(this.name),
    }
  },

  addKeyboardShortcuts() {
    return {
      'Mod-Alt-c': () => this.editor.commands.toggleCallout(),
    }
  },
})
