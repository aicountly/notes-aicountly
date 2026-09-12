/**
 * Stable ids for block nodes.
 *
 * A comment anchors to a block, a checklist action mirrors one, and a backlink
 * scrolls to one. None of that can be done against a node identified only by
 * its position in the tree — reword the paragraph above and every anchor below
 * it has moved. So every block carries a uuid, minted here and enforced by
 * `NoteDocument::sanitizeAttrs()` on the way in.
 *
 * Two halves, and both are needed:
 *
 *   1. `keepOnSplit: false` — pressing Enter mid-paragraph produces a *new*
 *      block, and it must not inherit the id of the one it was split from.
 *   2. the plugin below — a paste, an undo or a node created by a command can
 *      still land without an id, or with one that is already in the document.
 *      Both are repaired in the same transaction that produced them, so the
 *      document is never briefly saveable in an ambiguous state.
 *
 * Ids for content arriving from the server are filled in by `withBlockIds()`
 * before it ever reaches the editor; see documentSchema.ts for why that has to
 * happen on load rather than on the first edit.
 */

import { Extension } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'

import { BLOCK_ID_NODES, isBlockId, newBlockId } from '../documentSchema'

export interface BlockIdOptions {
  /** Node types that carry an id. Defaults to every type the server allows one on. */
  types: readonly string[]
}

export const BlockId = Extension.create<BlockIdOptions>({
  name: 'blockId',

  addOptions() {
    return { types: BLOCK_ID_NODES }
  },

  addGlobalAttributes() {
    return [
      {
        types: [...this.options.types],
        attributes: {
          blockId: {
            default: null,
            keepOnSplit: false,
            parseHTML: (element) => element.getAttribute('data-block-id'),
            renderHTML: (attributes) => {
              const id: unknown = attributes.blockId
              return typeof id === 'string' && id !== '' ? { 'data-block-id': id } : {}
            },
          },
        },
      },
    ]
  },

  addProseMirrorPlugins() {
    const types = new Set(this.options.types)

    return [
      new Plugin({
        key: new PluginKey('blockId'),
        appendTransaction: (transactions, _oldState, newState) => {
          if (!transactions.some((transaction) => transaction.docChanged)) return null

          const seen = new Set<string>()
          const transaction = newState.tr
          let touched = false

          newState.doc.descendants((node, pos) => {
            if (!types.has(node.type.name)) return true

            const id: unknown = node.attrs.blockId
            if (isBlockId(id) && !seen.has(id)) {
              seen.add(id)
              return true
            }

            const fresh = newBlockId()
            seen.add(fresh)
            transaction.setNodeAttribute(pos, 'blockId', fresh)
            touched = true
            return true
          })

          return touched ? transaction : null
        },
      }),
    ]
  },
})
