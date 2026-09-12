/**
 * The document contract.
 *
 * Both halves matter to a user, even though neither is visible: a document
 * that does not round-trip loses formatting on save, and a block id that moves
 * takes every comment anchored to it along with it.
 */

import { describe, expect, it } from 'vitest'

import { isBlockId, toStorableDocument, withBlockIds } from './documentSchema'
import type { DocNode, NoteDocument } from '../../shared/api/types'

describe('toStorableDocument', () => {
  it('keeps what the server stores and drops what it does not', () => {
    const stored = toStorableDocument({
      type: 'doc',
      content: [
        {
          type: 'orderedList',
          // `type` is Tiptap's; the server has no column for it.
          attrs: { start: 1, type: null, blockId: 'ba5eba11-0000-4000-8000-000000000001' },
          content: [
            {
              type: 'listItem',
              attrs: { blockId: 'ba5eba11-0000-4000-8000-000000000002' },
              content: [
                {
                  type: 'paragraph',
                  attrs: { blockId: 'ba5eba11-0000-4000-8000-000000000003', textAlign: null },
                  content: [{ type: 'text', text: 'One' }],
                },
              ],
            },
          ],
        },
      ],
    })

    expect(stored.content?.[0]?.attrs).toEqual({
      start: 1,
      blockId: 'ba5eba11-0000-4000-8000-000000000001',
    })
  })

  it('drops a node type the schema has never heard of', () => {
    const stored = toStorableDocument({
      type: 'doc',
      content: [
        { type: 'iframe', attrs: { src: 'https://example.com' } },
        { type: 'paragraph', content: [{ type: 'text', text: 'Kept' }] },
      ],
    })

    expect(stored.content).toHaveLength(1)
    expect(stored.content?.[0]?.type).toBe('paragraph')
  })

  it('drops a mark the schema has never heard of, keeping the words', () => {
    const stored = toStorableDocument({
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          content: [
            {
              type: 'text',
              text: 'Careful',
              marks: [{ type: 'bold' }, { type: 'fontSize', attrs: { size: '40px' } }],
            },
          ],
        },
      ],
    })

    const text = stored.content?.[0]?.content?.[0]
    expect(text?.text).toBe('Careful')
    expect(text?.marks).toEqual([{ type: 'bold' }])
  })

  it('answers an unparseable document with an empty one rather than throwing', () => {
    expect(toStorableDocument(null)).toEqual({ type: 'doc', content: [] })
    expect(toStorableDocument({ type: 'paragraph' })).toEqual({ type: 'doc', content: [] })
  })
})

describe('withBlockIds', () => {
  const document: NoteDocument = {
    type: 'doc',
    content: [
      { type: 'paragraph', content: [{ type: 'text', text: 'First' }] },
      { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Second' }] },
    ],
  }

  it('gives every block an id before the first keystroke', () => {
    const ready = withBlockIds(document)

    for (const node of ready.content ?? []) {
      expect(isBlockId(node.attrs?.blockId)).toBe(true)
    }
  })

  it('leaves an id the server already assigned alone', () => {
    const existing = 'ba5eba11-0000-4000-8000-00000000000a'
    const ready = withBlockIds({
      type: 'doc',
      content: [{ type: 'paragraph', attrs: { blockId: existing } }],
    })

    expect(ready.content?.[0]?.attrs?.blockId).toBe(existing)
  })

  it('re-mints an id that a copy-paste duplicated', () => {
    const duplicated = 'ba5eba11-0000-4000-8000-00000000000b'
    const ready = withBlockIds({
      type: 'doc',
      content: [
        { type: 'paragraph', attrs: { blockId: duplicated } },
        { type: 'paragraph', attrs: { blockId: duplicated } },
      ],
    })

    const [first, second] = ready.content as DocNode[]
    expect(first.attrs?.blockId).toBe(duplicated)
    expect(second.attrs?.blockId).not.toBe(duplicated)
    expect(isBlockId(second.attrs?.blockId)).toBe(true)
  })

  it('survives a save round trip unchanged', () => {
    const ready = withBlockIds(document)
    expect(toStorableDocument(ready)).toEqual(ready)
  })
})
