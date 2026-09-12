/**
 * What a template will actually write into a note.
 *
 * A template is only worth choosing if you can see what it puts on the page, so
 * this renders the stored ProseMirror document rather than describing it. It is
 * a *reader*, not the editor:
 *
 *   - **Nothing here is a control.** A checklist item shows a box and the words
 *     "To do", not a checkbox that pretends to be tickable in a preview.
 *   - **Every string goes through React as a child**, so it is escaped by
 *     construction. There is no `dangerouslySetInnerHTML` anywhere in this file
 *     and there must never be: the document is user data.
 *   - **Nothing is fetched.** An image or an attachment is shown as a labelled
 *     placeholder — a preview that loads a colleague's uploads is a preview
 *     that leaks read receipts and stalls on a slow link.
 *   - **A node type this release does not know still renders its children**,
 *     because a template saved by a newer client should look thin here, not
 *     empty.
 */

import type { ReactNode } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import type { DocNode, NoteDocument } from '../../../shared/api/types'
import '../templates.css'

/** Schemes a link may use. The same list `NoteDocument::sanitize()` allows. */
const SAFE_LINK = /^(https?|mailto|tel):/i

const CALLOUT_ICON: Record<string, IconName> = {
  info: 'info',
  warning: 'alert',
  success: 'check',
  danger: 'alert',
}

function attrText(node: DocNode, name: string): string | null {
  const value = node.attrs?.[name]
  return typeof value === 'string' && value !== '' ? value : null
}

function attrNumber(node: DocNode, name: string): number | null {
  const value = node.attrs?.[name]
  return typeof value === 'number' ? value : null
}

export function TemplateDocumentPreview({
  document,
  emptyLabel = 'This template starts an empty note.',
}: {
  document: NoteDocument | undefined
  /** What to say when the template has no body — an empty note is a real choice. */
  emptyLabel?: string
}) {
  const blocks = document?.content ?? []

  if (blocks.length === 0) {
    return <p className="tpl-doc__empty">{emptyLabel}</p>
  }

  return (
    <div className="tpl-doc">
      <Nodes nodes={blocks} />
    </div>
  )
}

function Nodes({ nodes }: { nodes: DocNode[] | undefined }) {
  // Index keys: this tree is rendered from an immutable document and never
  // reordered, so there is nothing for a stable key to protect.
  return <>{(nodes ?? []).map((node, index) => <Node key={index} node={node} />)}</>
}

function Node({ node }: { node: DocNode }): ReactNode {
  switch (node.type) {
    case 'text':
      return <Text node={node} />

    case 'hardBreak':
      return <br />

    case 'paragraph': {
      const empty = (node.content ?? []).length === 0

      // An empty paragraph in a template is deliberate — it is the space the
      // author left to write in — so it keeps its height instead of collapsing.
      return (
        <p className={`tpl-doc__p ${empty ? 'tpl-doc__p--blank' : ''}`.trim()}>
          <Nodes nodes={node.content} />
        </p>
      )
    }

    case 'heading': {
      // Shifted one level down: this preview lives inside a dialog whose title
      // is the h2, so the document's own level 2 belongs at h3.
      const level = Math.min(6, Math.max(3, (attrNumber(node, 'level') ?? 2) + 1))
      const Tag = `h${level}` as 'h3' | 'h4' | 'h5' | 'h6'

      return (
        <Tag className={`tpl-doc__heading tpl-doc__heading--${level}`}>
          <Nodes nodes={node.content} />
        </Tag>
      )
    }

    case 'blockquote':
      return (
        <blockquote className="tpl-doc__quote">
          <Nodes nodes={node.content} />
        </blockquote>
      )

    case 'codeBlock':
      return (
        <pre className="tpl-doc__code">
          <code>{plainText(node)}</code>
        </pre>
      )

    case 'bulletList':
      return (
        <ul className="tpl-doc__list">
          <Nodes nodes={node.content} />
        </ul>
      )

    case 'orderedList':
      return (
        <ol className="tpl-doc__list" start={attrNumber(node, 'start') ?? undefined}>
          <Nodes nodes={node.content} />
        </ol>
      )

    case 'listItem':
      return (
        <li className="tpl-doc__item">
          <Nodes nodes={node.content} />
        </li>
      )

    case 'taskList':
      return (
        <ul className="tpl-doc__tasks">
          <Nodes nodes={node.content} />
        </ul>
      )

    case 'taskItem': {
      const done = node.attrs?.checked === true

      return (
        <li className="tpl-doc__task">
          <span className={`tpl-doc__box ${done ? 'tpl-doc__box--done' : ''}`.trim()} aria-hidden>
            {done ? <Icon name="check" size={12} /> : null}
          </span>
          {/* The state is written out as well as drawn: a tick is a shape, and
              a shape alone is not a signal every reader receives. */}
          <span className="sr-only">{done ? 'Done: ' : 'To do: '}</span>
          <span className="tpl-doc__task-body">
            <Nodes nodes={node.content} />
          </span>
        </li>
      )
    }

    case 'horizontalRule':
      return <hr className="tpl-doc__rule" />

    case 'callout': {
      const tone = attrText(node, 'tone') ?? 'info'

      return (
        <div className={`tpl-doc__callout tpl-doc__callout--${CALLOUT_ICON[tone] ? tone : 'info'}`}>
          <Icon name={CALLOUT_ICON[tone] ?? 'info'} size={15} />
          <span className="sr-only">{`${tone} note: `}</span>
          <div className="tpl-doc__callout-body">
            <Nodes nodes={node.content} />
          </div>
        </div>
      )
    }

    case 'details':
      // Open, because a preview whose content is folded away is not a preview.
      return (
        <details className="tpl-doc__details" open>
          <Nodes nodes={node.content} />
        </details>
      )

    case 'detailsSummary':
      return (
        <summary className="tpl-doc__summary">
          <Nodes nodes={node.content} />
        </summary>
      )

    case 'detailsContent':
      return <Nodes nodes={node.content} />

    case 'table':
      // Its own scroller: a six-column table must not push the dialog sideways.
      return (
        <div className="tpl-doc__table-scroll">
          <table className="tpl-doc__table">
            <tbody>
              <Nodes nodes={node.content} />
            </tbody>
          </table>
        </div>
      )

    case 'tableRow':
      return (
        <tr>
          <Nodes nodes={node.content} />
        </tr>
      )

    case 'tableHeader':
      return (
        <th scope="col">
          <Nodes nodes={node.content} />
        </th>
      )

    case 'tableCell':
      return (
        <td>
          <Nodes nodes={node.content} />
        </td>
      )

    case 'image':
      return <Placeholder icon="image" label={attrText(node, 'alt') ?? 'Image'} />

    case 'attachment':
      return <Placeholder icon="attach" label={attrText(node, 'filename') ?? 'Attachment'} />

    case 'audio':
      return <Placeholder icon="mic" label="Recording" />

    case 'canvasEmbed':
      return <Placeholder icon="draw" label="Canvas" />

    case 'noteLink':
      return <Chip icon="note" label={attrText(node, 'label') ?? 'Linked note'} />

    case 'mention':
      return <Chip icon="user" label={attrText(node, 'label') ?? 'Mention'} />

    case 'dateChip':
      return <Chip icon="calendar" label={attrText(node, 'label') ?? attrText(node, 'date') ?? 'Date'} />

    default:
      return <Nodes nodes={node.content} />
  }
}

/** Text, with its marks. Unknown marks fall through as plain text. */
function Text({ node }: { node: DocNode }): ReactNode {
  let rendered: ReactNode = node.text ?? ''

  for (const mark of node.marks ?? []) {
    switch (mark.type) {
      case 'bold':
        rendered = <strong>{rendered}</strong>
        break
      case 'italic':
        rendered = <em>{rendered}</em>
        break
      case 'underline':
        rendered = <u>{rendered}</u>
        break
      case 'strike':
        rendered = <s>{rendered}</s>
        break
      case 'code':
        rendered = <code className="tpl-doc__inline-code">{rendered}</code>
        break
      case 'highlight':
        rendered = <mark className="tpl-doc__mark">{rendered}</mark>
        break
      case 'link': {
        const href = mark.attrs?.href
        // A link the sanitiser would have stripped is shown as the words it
        // wraps: an unclickable label is safer than a scheme we do not trust.
        rendered =
          typeof href === 'string' && SAFE_LINK.test(href) ? (
            <a className="tpl-doc__link" href={href} target="_blank" rel="noreferrer noopener">
              {rendered}
            </a>
          ) : (
            rendered
          )
        break
      }
      default:
        break
    }
  }

  return rendered
}

/** A block the preview names rather than loads. */
function Placeholder({ icon, label }: { icon: IconName; label: string }) {
  return (
    <p className="tpl-doc__media">
      <Icon name={icon} size={15} />
      {label}
    </p>
  )
}

function Chip({ icon, label }: { icon: IconName; label: string }) {
  return (
    <span className="tpl-doc__chip">
      <Icon name={icon} size={12} />
      {label}
    </span>
  )
}

/** The text under a node, for the places that take no markup — a code block. */
function plainText(node: DocNode): string {
  if (typeof node.text === 'string') return node.text
  return (node.content ?? []).map(plainText).join('')
}
