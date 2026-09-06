/**
 * `@mentions`.
 *
 * A mention is a node carrying an entity type and id, not a piece of styled
 * text — the server reads them out of the document to link a note to a person
 * (and, later, to any other AICOUNTLY record) without parsing prose.
 *
 * The candidate list comes from whoever can already see the note. Mentioning
 * someone who cannot open it would either leak the note or produce a dead
 * link, so the people offered are the note's members and nobody else.
 */

import { Extension, Node, mergeAttributes } from '@tiptap/core'
import type { Editor, Range } from '@tiptap/core'
import { PluginKey } from '@tiptap/pm/state'
import Suggestion from '@tiptap/suggestion'

import { createSuggestionBridge, suggestionRenderer } from './suggestionBridge'
import type { SuggestionBridge, SuggestionMenuItem } from './suggestionBridge'

export interface MentionItem extends SuggestionMenuItem {
  id: string
  label: string
  entityType: string
  entityId: string
}

export const Mention = Node.create({
  name: 'mention',
  group: 'inline',
  inline: true,
  atom: true,

  addAttributes() {
    return {
      entityType: {
        default: 'user',
        parseHTML: (element) => element.getAttribute('data-entity-type') ?? 'user',
        renderHTML: (attributes) => ({ 'data-entity-type': String(attributes.entityType ?? 'user') }),
      },
      entityId: {
        default: '',
        parseHTML: (element) => element.getAttribute('data-entity-id') ?? '',
        renderHTML: (attributes) => ({ 'data-entity-id': String(attributes.entityId ?? '') }),
      },
      label: {
        default: '',
        parseHTML: (element) => element.getAttribute('data-label') ?? '',
        renderHTML: (attributes) => ({ 'data-label': String(attributes.label ?? '') }),
      },
    }
  },

  parseHTML() {
    return [{ tag: 'span[data-entity-id]' }]
  },

  renderHTML({ node, HTMLAttributes }) {
    return [
      'span',
      mergeAttributes(HTMLAttributes, { class: 'mention-chip' }),
      `@${String(node.attrs.label ?? '')}`,
    ]
  },

  renderText({ node }) {
    return `@${String(node.attrs.label ?? '')}`
  },
})

export interface MentionSuggestionOptions {
  bridge: SuggestionBridge
  /** Resolves candidates for what has been typed. Returning [] shows no menu. */
  items: (query: string) => Promise<MentionItem[]> | MentionItem[]
}

export const MentionSuggestion = Extension.create<MentionSuggestionOptions>({
  name: 'mentionSuggestion',

  addOptions() {
    return {
      bridge: createSuggestionBridge(),
      items: () => [],
    }
  },

  addProseMirrorPlugins() {
    return [
      Suggestion<MentionItem, MentionItem>({
        editor: this.editor,
        char: '@',
        pluginKey: new PluginKey('mentionSuggestion'),
        allowSpaces: false,
        // The list is fetched, so a keystroke should not fire a request.
        debounce: 150,
        items: ({ query }) => this.options.items(query),
        command: ({ editor, range, props }: { editor: Editor; range: Range; props: MentionItem }) => {
          editor
            .chain()
            .focus()
            .deleteRange(range)
            .insertContent([
              {
                type: 'mention',
                attrs: {
                  entityType: props.entityType,
                  entityId: props.entityId,
                  label: props.label,
                },
              },
              { type: 'text', text: ' ' },
            ])
            .run()
        },
        render: suggestionRenderer<MentionItem>(this.options.bridge),
      }),
    ]
  },
})
