/**
 * The editor's half of the document contract.
 *
 * `server-php/src/Domain/Notes/NoteDocument.php` is an allowlist: an unknown
 * node, mark or attribute does not survive `sanitize()`. That is the security
 * boundary and it is not negotiable — but it means anything the editor emits
 * outside the list is *silently dropped*, so what comes back from a save is not
 * what was sent. This module mirrors the allowlist on the client and normalises
 * the document before it goes on the wire, which buys two things:
 *
 *   - a save round-trips byte-for-byte, so the version the editor holds and the
 *     version the server stored are the same document;
 *   - the payload carries no editor bookkeeping (ProseMirror emits every
 *     attribute, including the nulls) across a mobile connection.
 *
 * When the PHP allowlist changes, this changes with it. That duplication is
 * deliberate: the alternative is the editor discovering the schema by having
 * its users' formatting quietly deleted.
 */

import { EMPTY_DOCUMENT } from '../../shared/api/types'
import type { DocMark, DocNode, NoteDocument } from '../../shared/api/types'

/** Mirrors NoteDocument::ALLOWED_NODES. */
export const ALLOWED_NODES: ReadonlySet<string> = new Set([
  'doc', 'paragraph', 'text', 'heading', 'blockquote', 'codeBlock',
  'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem',
  'horizontalRule', 'hardBreak', 'image', 'table', 'tableRow',
  'tableCell', 'tableHeader', 'callout', 'details', 'detailsSummary',
  'detailsContent', 'attachment', 'audio', 'noteLink', 'mention',
  'dateChip', 'canvasEmbed',
])

/** Mirrors NoteDocument::ALLOWED_ATTRS. */
const ALLOWED_ATTRS: Readonly<Record<string, readonly string[]>> = {
  heading: ['level', 'blockId'],
  paragraph: ['blockId', 'textAlign'],
  codeBlock: ['language', 'blockId'],
  blockquote: ['blockId'],
  bulletList: ['blockId'],
  orderedList: ['blockId', 'start'],
  listItem: ['blockId'],
  taskList: ['blockId'],
  taskItem: ['blockId', 'checked'],
  image: ['src', 'alt', 'title', 'width', 'height', 'attachmentId', 'blockId'],
  attachment: ['attachmentId', 'filename', 'mimeType', 'byteSize', 'kind', 'blockId'],
  audio: ['attachmentId', 'duration', 'blockId'],
  callout: ['tone', 'blockId'],
  details: ['open', 'blockId'],
  table: ['blockId'],
  tableCell: ['colspan', 'rowspan', 'colwidth'],
  tableHeader: ['colspan', 'rowspan', 'colwidth'],
  noteLink: ['noteId', 'label'],
  mention: ['entityType', 'entityId', 'label'],
  dateChip: ['date', 'label'],
  canvasEmbed: ['canvasId', 'height', 'blockId'],
  horizontalRule: ['blockId'],
}

/** Mirrors NoteDocument::ALLOWED_MARKS. */
const ALLOWED_MARKS: Readonly<Record<string, readonly string[]>> = {
  bold: [],
  italic: [],
  underline: [],
  strike: [],
  code: [],
  highlight: ['color'],
  textStyle: ['color'],
  link: ['href', 'target', 'rel'],
  comment: ['commentId'],
}

/**
 * The nodes the server mints a `blockId` for, and therefore the nodes the
 * editor must mint one for first — see {@link withBlockIds}.
 */
export const BLOCK_ID_NODES: readonly string[] = Object.entries(ALLOWED_ATTRS)
  .filter(([, attrs]) => attrs.includes('blockId'))
  .map(([type]) => type)

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export function isBlockId(value: unknown): value is string {
  return typeof value === 'string' && UUID_PATTERN.test(value)
}

/** A v4 UUID. `crypto.randomUUID` is unavailable on insecure origins. */
export function newBlockId(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

// ---------------------------------------------------------------------------
// Normalisation
// ---------------------------------------------------------------------------

type AttrValue = string | number | boolean | number[]

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function attrValue(name: string, value: unknown): AttrValue | null {
  if (value === null || value === undefined) return null

  if (name === 'colwidth') {
    if (!Array.isArray(value)) return null
    const widths = value.filter((entry): entry is number => typeof entry === 'number')
    return widths.length > 0 ? widths : null
  }

  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
    return value
  }
  return null
}

function normaliseAttrs(type: string, attrs: unknown): Record<string, AttrValue> | null {
  const allowed = ALLOWED_ATTRS[type]
  if (!allowed || !isRecord(attrs)) return null

  const clean: Record<string, AttrValue> = {}
  for (const name of allowed) {
    const value = attrValue(name, attrs[name])
    if (value !== null) clean[name] = value
  }

  return Object.keys(clean).length > 0 ? clean : null
}

function normaliseMarks(marks: unknown): DocMark[] {
  if (!Array.isArray(marks)) return []

  const clean: DocMark[] = []
  for (const mark of marks) {
    if (!isRecord(mark)) continue
    const type = mark.type
    if (typeof type !== 'string') continue

    const allowed = ALLOWED_MARKS[type]
    if (!allowed) continue

    const entry: DocMark = { type }
    const attrs = isRecord(mark.attrs) ? mark.attrs : {}
    const cleanAttrs: Record<string, string | number | boolean> = {}
    for (const name of allowed) {
      const value = attrs[name]
      if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
        cleanAttrs[name] = value
      }
    }
    if (Object.keys(cleanAttrs).length > 0) entry.attrs = cleanAttrs
    clean.push(entry)
  }

  return clean
}

function normaliseNode(value: unknown): DocNode | null {
  if (!isRecord(value)) return null

  const type = value.type
  if (typeof type !== 'string' || !ALLOWED_NODES.has(type)) return null

  const node: DocNode = { type }

  if (type === 'text') {
    // Control characters are how a payload gets smuggled past a filter, and
    // the server strips them anyway; matching it here keeps the round trip
    // stable rather than producing a diff on every save.
    const text = typeof value.text === 'string' ? value.text : ''
    const cleaned = text.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
    if (cleaned === '') return null
    node.text = cleaned
    const marks = normaliseMarks(value.marks)
    if (marks.length > 0) node.marks = marks
    return node
  }

  const attrs = normaliseAttrs(type, value.attrs)
  if (attrs) node.attrs = attrs

  if (Array.isArray(value.content)) {
    const children: DocNode[] = []
    for (const child of value.content) {
      const clean = normaliseNode(child)
      if (clean) children.push(clean)
    }
    if (children.length > 0) node.content = children
  }

  const marks = normaliseMarks(value.marks)
  if (marks.length > 0) node.marks = marks

  return node
}

/**
 * Editor JSON, reduced to exactly what the server will store.
 *
 * Takes `unknown` on purpose: `editor.getJSON()` is typed as `JSONContent`,
 * whose `attrs` are `any`. Narrowing here rather than casting there is what
 * keeps `any` out of the save path.
 */
export function toStorableDocument(value: unknown): NoteDocument {
  const doc = normaliseNode(value)
  if (!doc || doc.type !== 'doc') return EMPTY_DOCUMENT

  return { type: 'doc', content: doc.content ?? [] }
}

/**
 * Give every block a stable id before it reaches the editor.
 *
 * This runs on load rather than on first edit, and that ordering is the whole
 * point. If the editor started with `blockId: null` on a block, every save
 * would hand the server a null and the server would mint a *different* uuid
 * each time — so a comment anchored to a paragraph would come unstuck from it
 * on the next keystroke. Minting here, once, means the id the server stores is
 * the id the editor is holding.
 *
 * Duplicates are re-minted too: a copy-pasted block arrives carrying the id of
 * the block it was copied from, and two blocks with one id is an ambiguous
 * anchor.
 */
export function withBlockIds(document: NoteDocument): NoteDocument {
  const seen = new Set<string>()

  const visit = (node: DocNode): DocNode => {
    const content = node.content?.map(visit)
    const next: DocNode = { ...node }
    if (content) next.content = content

    if (BLOCK_ID_NODES.includes(node.type)) {
      const current = node.attrs?.blockId
      const id = isBlockId(current) && !seen.has(current) ? current : newBlockId()
      seen.add(id)
      next.attrs = { ...next.attrs, blockId: id }
    }

    return next
  }

  return { type: 'doc', content: (document.content ?? []).map(visit) }
}
