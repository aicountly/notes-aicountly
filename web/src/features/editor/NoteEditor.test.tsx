/**
 * A mount test for the editor itself.
 *
 * Not a test of Tiptap: it is here because the extension list is the one part
 * of this feature that fails at *runtime* rather than at compile time — a
 * duplicate extension name, a node whose content expression the schema
 * rejects, or an attribute two extensions both claim. Rendering one real note
 * catches all three.
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { NoteEditor } from './NoteEditor'
import type { Note, NoteRole } from '../../shared/api/types'

function aNote(overrides: Partial<Note> = {}): Note {
  return {
    id: '11111111-1111-4111-8111-111111111111',
    note_type: 'document',
    title: 'Quarterly review',
    display_title: 'Quarterly review',
    excerpt: '',
    notebook_id: null,
    color: null,
    is_pinned: false,
    is_favourite: false,
    is_archived: false,
    is_locked: false,
    privacy_mode: 'standard',
    version: 1,
    word_count: 3,
    char_count: 20,
    owner_user_id: 'user-1',
    created_at: null,
    updated_at: null,
    deleted_at: null,
    role: 'owner',
    is_shared: false,
    attachment_count: 0,
    has_reminder: false,
    checklist: null,
    tags: [],
    document: {
      type: 'doc',
      content: [
        { type: 'paragraph', content: [{ type: 'text', text: 'Discussed GST reconciliation.' }] },
      ],
    },
    document_schema_version: 1,
    content_hash: '',
    source: null,
    language: null,
    template_key: null,
    capabilities: {
      view: true,
      comment: true,
      edit: true,
      share: true,
      delete: true,
      restore: true,
      manage_members: true,
    },
    ...overrides,
  }
}

function renderEditor(note: Note) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const result = render(
    <QueryClientProvider client={client}>
      <NoteEditor note={note} />
    </QueryClientProvider>,
  )
  return { ...result, client }
}

describe('NoteEditor', () => {
  it('opens the note with its title and body', async () => {
    renderEditor(aNote())

    expect(await screen.findByLabelText('Note title')).toHaveValue('Quarterly review')
    expect(await screen.findByText('Discussed GST reconciliation.')).toBeInTheDocument()
  })

  it('explains itself rather than silently refusing edits a viewer cannot make', async () => {
    const viewer = aNote({
      role: 'viewer' as NoteRole,
      capabilities: {
        view: true,
        comment: false,
        edit: false,
        share: false,
        delete: false,
        restore: false,
        manage_members: false,
      },
    })

    renderEditor(viewer)

    expect(await screen.findByText('You have view-only access to this note.')).toBeInTheDocument()
    expect(screen.getByLabelText('Note title')).toHaveAttribute('readonly')
    expect(await screen.findByRole('textbox', { name: 'Note body' })).toHaveAttribute(
      'contenteditable',
      'false',
    )
  })

  describe('a save that lands while this note is open', () => {
    /**
     * These exercise what a `note` prop change does once presence (or any
     * other refetch — a reconnect, a sibling mutation) delivers a newer
     * version: apply it when nothing would be lost, say so instead of
     * applying it when something would be. Neither path was under test
     * before presence made it something this app now relies on happening
     * correctly, several times a minute, on every shared note that is open.
     */
    it('does not touch what is on screen while the writer is mid-edit, and says so', async () => {
      const original = aNote({ version: 1 })
      const { rerender, client } = renderEditor(original)

      const body = await screen.findByRole('textbox', { name: 'Note body' })
      body.focus()
      expect(body).toHaveFocus()

      const updated = aNote({
        version: 2,
        document: {
          type: 'doc',
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Someone else wrote this.' }] }],
        },
      })
      rerender(
        <QueryClientProvider client={client}>
          <NoteEditor note={updated} />
        </QueryClientProvider>,
      )

      expect(
        await screen.findByText('Someone saved changes to this note'),
      ).toBeInTheDocument()
      // Nothing was overwritten: the words on screen are still the original
      // ones, not the ones that just arrived.
      expect(within(body).getByText('Discussed GST reconciliation.')).toBeInTheDocument()
      expect(within(body).queryByText('Someone else wrote this.')).not.toBeInTheDocument()
    })

    it('applies a newer version straight away when nothing is mid-edit, and never shows the notice for it', async () => {
      const original = aNote({ version: 1 })
      const { rerender, client } = renderEditor(original)
      const body = await screen.findByRole('textbox', { name: 'Note body' })

      // Never focused, never typed into — the ordinary case of a note sitting
      // open while someone else saves it, which presence exists to notice
      // promptly. Nothing is at risk of being lost, so this is the case that
      // should need no notice at all: it should simply show up.
      const updated = aNote({
        version: 2,
        document: {
          type: 'doc',
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Someone else wrote this.' }] }],
        },
      })
      rerender(
        <QueryClientProvider client={client}>
          <NoteEditor note={updated} />
        </QueryClientProvider>,
      )

      expect(await within(body).findByText('Someone else wrote this.')).toBeInTheDocument()
      expect(within(body).queryByText('Discussed GST reconciliation.')).not.toBeInTheDocument()
      expect(screen.queryByText('Someone saved changes to this note')).not.toBeInTheDocument()
    })
  })
})
