/**
 * Block ids, through a real editor.
 *
 * The failure this guards against is silent and expensive: two blocks sharing
 * an id, which makes every comment and checklist action anchored to it
 * ambiguous. Splitting a paragraph is the everyday way it happens — press
 * Enter in the middle of a sentence and the new node inherits the old attrs.
 */

import { Editor } from '@tiptap/core'
import { StarterKit } from '@tiptap/starter-kit'
import { afterEach, describe, expect, it } from 'vitest'

import { isBlockId, withBlockIds } from '../documentSchema'
import { BlockId } from './blockId'

let editor: Editor | null = null

function open(text: string): Editor {
  editor = new Editor({
    extensions: [StarterKit, BlockId],
    content: withBlockIds({
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'text', text }] }],
    }),
  })
  return editor
}

function blockIds(instance: Editor): unknown[] {
  const document = instance.getJSON()
  return (document.content ?? []).map((node) => node.attrs?.blockId)
}

describe('BlockId', () => {
  afterEach(() => {
    editor?.destroy()
    editor = null
  })

  it('leaves an existing id alone while the text is edited', () => {
    const instance = open('Discussed GST reconciliation.')
    const before = blockIds(instance)[0]

    instance.commands.insertContentAt(1, 'Also ')

    expect(blockIds(instance)[0]).toBe(before)
  })

  it('gives the half split off by Enter an id of its own', () => {
    const instance = open('One sentence. Another sentence.')
    const before = blockIds(instance)[0]

    instance.commands.setTextSelection(15)
    instance.commands.splitBlock()

    const after = blockIds(instance)
    expect(after).toHaveLength(2)
    expect(after[0]).toBe(before)
    expect(after[1]).not.toBe(before)
    expect(isBlockId(after[1])).toBe(true)
  })

  it('re-mints the id on a block pasted from elsewhere in the same note', () => {
    const instance = open('Original')
    const duplicated = blockIds(instance)[0]

    instance.commands.insertContentAt(instance.state.doc.content.size, {
      type: 'paragraph',
      attrs: { blockId: duplicated },
      content: [{ type: 'text', text: 'Copy' }],
    })

    const after = blockIds(instance)
    expect(after[0]).toBe(duplicated)
    expect(after[1]).not.toBe(duplicated)
    expect(isBlockId(after[1])).toBe(true)
  })
})
