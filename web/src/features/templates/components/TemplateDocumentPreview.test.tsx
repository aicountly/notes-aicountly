/**
 * The reader that shows what a template writes.
 *
 * A template's document is user data that arrives over the wire, so these tests
 * are mostly about what the preview refuses to do with it: no markup is ever
 * interpreted, a link scheme the sanitiser would have stripped is not turned
 * into a link, and nothing is fetched. The rest is that a document still reads
 * as a document — headings, lists and checklist state — rather than as JSON.
 */

import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'

import { TemplateDocumentPreview } from './TemplateDocumentPreview'
import type { NoteDocument } from '../../../shared/api/types'

function doc(content: NoteDocument['content']): NoteDocument {
  return { type: 'doc', content }
}

describe('TemplateDocumentPreview', () => {
  it('says so when a template starts an empty note', () => {
    render(<TemplateDocumentPreview document={doc([])} />)

    expect(screen.getByText('This template starts an empty note.')).toBeInTheDocument()
  })

  it('renders text as text, never as markup', () => {
    const { container } = render(
      <TemplateDocumentPreview
        document={doc([
          {
            type: 'paragraph',
            content: [{ type: 'text', text: '<img src=x onerror="alert(1)"> & <b>bold</b>' }],
          },
        ])}
      />,
    )

    expect(screen.getByText('<img src=x onerror="alert(1)"> & <b>bold</b>')).toBeInTheDocument()
    expect(container.querySelector('img')).toBeNull()
    expect(container.querySelector('b')).toBeNull()
  })

  it('links only what the server would have allowed to be a link', () => {
    render(
      <TemplateDocumentPreview
        document={doc([
          {
            type: 'paragraph',
            content: [
              {
                type: 'text',
                text: 'the handbook',
                marks: [{ type: 'link', attrs: { href: 'https://example.test/handbook' } }],
              },
              {
                type: 'text',
                text: 'not a link',
                marks: [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }],
              },
            ],
          },
        ])}
      />,
    )

    const link = screen.getByRole('link', { name: 'the handbook' })
    expect(link).toHaveAttribute('href', 'https://example.test/handbook')
    expect(link).toHaveAttribute('rel', expect.stringContaining('noreferrer'))

    expect(screen.getByText('not a link')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'not a link' })).not.toBeInTheDocument()
  })

  it('writes checklist state out in words as well as drawing it', () => {
    render(
      <TemplateDocumentPreview
        document={doc([
          {
            type: 'taskList',
            content: [
              {
                type: 'taskItem',
                attrs: { checked: true },
                content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Agenda sent' }] }],
              },
              {
                type: 'taskItem',
                attrs: { checked: false },
                content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Minutes filed' }] }],
              },
            ],
          },
        ])}
      />,
    )

    expect(screen.getByText('Done:')).toBeInTheDocument()
    expect(screen.getByText('To do:')).toBeInTheDocument()
    // Not a checkbox: nothing in a preview is a control.
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
  })

  it('names a file rather than loading it', () => {
    const { container } = render(
      <TemplateDocumentPreview
        document={doc([{ type: 'image', attrs: { src: 'https://example.test/x.png', alt: 'Floor plan' } }])}
      />,
    )

    expect(screen.getByText('Floor plan')).toBeInTheDocument()
    expect(container.querySelector('img')).toBeNull()
  })

  it('still shows the words inside a block this release does not know', () => {
    render(
      <TemplateDocumentPreview
        document={doc([
          {
            type: 'somethingNewer',
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Still readable' }] }],
          },
        ])}
      />,
    )

    expect(screen.getByText('Still readable')).toBeInTheDocument()
  })

  it('keeps a document’s structure: headings are headings, lists are lists', () => {
    render(
      <TemplateDocumentPreview
        document={doc([
          { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Decisions' }] },
          {
            type: 'bulletList',
            content: [
              {
                type: 'listItem',
                content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Ship on Friday' }] }],
              },
            ],
          },
        ])}
      />,
    )

    // One level down from the document's own, because the dialog around this
    // preview owns the h2.
    expect(screen.getByRole('heading', { name: 'Decisions', level: 3 })).toBeInTheDocument()
    expect(screen.getByRole('listitem')).toHaveTextContent('Ship on Friday')
  })
})
