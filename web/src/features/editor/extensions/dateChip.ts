/**
 * An inline date.
 *
 * Two attributes rather than one: the machine date the server can read, and the
 * label the writer sees. Storing only the formatted label would make "12/07"
 * unparseable a month later, and storing only the ISO date would render a note
 * full of `2026-09-06`.
 */

import { Node, mergeAttributes } from '@tiptap/core'

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    dateChip: {
      insertDateChip: (date?: Date) => ReturnType
    }
  }
}

/** `YYYY-MM-DD` in the reader's own timezone, not UTC. */
export function toIsoDate(date: Date): string {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 10)
}

export function formatDateLabel(date: Date): string {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date)
}

export const DateChip = Node.create({
  name: 'dateChip',
  group: 'inline',
  inline: true,
  atom: true,

  addAttributes() {
    return {
      date: {
        default: '',
        parseHTML: (element) => element.getAttribute('datetime') ?? '',
        renderHTML: (attributes) => ({ datetime: String(attributes.date ?? '') }),
      },
      label: {
        default: '',
        parseHTML: (element) => element.textContent ?? '',
        renderHTML: () => ({}),
      },
    }
  },

  parseHTML() {
    return [{ tag: 'time[datetime]' }]
  },

  renderHTML({ node, HTMLAttributes }) {
    const label = String(node.attrs.label ?? '') || String(node.attrs.date ?? '')
    return ['time', mergeAttributes(HTMLAttributes, { class: 'date-chip' }), label]
  },

  renderText({ node }) {
    return String(node.attrs.label ?? node.attrs.date ?? '')
  },

  addCommands() {
    return {
      insertDateChip:
        (date = new Date()) =>
        ({ chain }) =>
          chain()
            .focus()
            .insertContent([
              { type: this.name, attrs: { date: toIsoDate(date), label: formatDateLabel(date) } },
              { type: 'text', text: ' ' },
            ])
            .run(),
    }
  },
})
