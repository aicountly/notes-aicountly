/**
 * The writing surface.
 *
 * Everything else in this product exists to get someone to this component and
 * out of their own way once they are here. Three decisions carry most of it:
 *
 * **The cursor never jumps.** A note is refetched by TanStack Query while it is
 * being typed into — on reconnect, on an invalidation from a sibling mutation,
 * on a collaborator's change. Feeding every one of those back into the editor
 * is the classic way a caret ends up at position 0 mid-sentence. So content is
 * written into the editor in exactly two situations: the note *id* changed (a
 * different note is open), or the incoming version is genuinely newer than the
 * one this editor loaded **and** the editor is not focused **and** there is
 * nothing waiting to be saved. `loadedVersion` is also advanced by our own
 * saves, so a save's own response — same document, higher version — is never
 * mistaken for someone else's edit.
 *
 * **Blocks carry ids before the first keystroke.** See documentSchema.ts.
 *
 * **A control that cannot work is not rendered.** Pulse appears only where the
 * server reports `features.ai`; the image upload field appears only when
 * something can accept a file.
 */

import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { EditorContent, useEditor, useEditorState } from '@tiptap/react'
import type { Editor } from '@tiptap/core'
import { StarterKit } from '@tiptap/starter-kit'
import { CharacterCount } from '@tiptap/extension-character-count'
import { Color } from '@tiptap/extension-color'
import { Highlight } from '@tiptap/extension-highlight'
import { Placeholder } from '@tiptap/extension-placeholder'
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table'
import { TaskItem } from '@tiptap/extension-task-item'
import { TaskList } from '@tiptap/extension-task-list'
import { TextStyle } from '@tiptap/extension-text-style'

import { useFeature } from '../../app/AppConfigProvider'
import { api } from '../../shared/api/client'
import { queryKeys } from '../../shared/query/queryClient'
import { Badge, Button, LiveStatus, Skeleton } from '../../shared/ui/primitives'
import { Icon } from '../../shared/ui/Icon'
import { useUpdateNote } from '../notes/hooks/useNotes'
import type { Note, NoteDocument, NoteMember } from '../../shared/api/types'

import { BubbleToolbar } from './BubbleToolbar'
import { ImageDialog, LinkDialog } from './EditorDialogs'
import { NoteLinkPicker } from './NoteLinkPicker'
import { PulseSelectionDialog } from './PulseSelectionDialog'
import { SlashCommandMenu } from './SlashCommandMenu'
import { toStorableDocument, withBlockIds } from './documentSchema'
import { filterSlashCommands } from './slashCommands'
import type { SlashActions, SlashCommand } from './slashCommands'
import { useAutosave } from './useAutosave'
import { useSuggestionMenu } from './useSuggestionMenu'
import { BlockId } from './extensions/blockId'
import { Callout } from './extensions/callout'
import { DateChip } from './extensions/dateChip'
import { NoteImage } from './extensions/image'
import { Mention, MentionSuggestion } from './extensions/mention'
import type { MentionItem } from './extensions/mention'
import { NoteLink } from './extensions/noteLink'
import { PasteHandling } from './extensions/pasteHandling'
import type { ImageUploader } from './extensions/pasteHandling'
import { SlashCommandExtension } from './extensions/slashCommand'
import { createSuggestionBridge } from './extensions/suggestionBridge'
import './editor.css'

export interface NoteEditorProps {
  note: Note
  /** Called with the server's copy after every accepted save. */
  onSaved?: (note: Note) => void
  /** Following a note link. Defaults to a plain navigation. */
  onOpenNote?: (noteId: string) => void
  /**
   * Stores a pasted or chosen image and returns its URL. Supplied by the
   * attachments feature; without it, images can still be inserted by address.
   */
  uploadImage?: ImageUploader
}

const READ_ONLY_REASON: Record<string, string> = {
  commenter: 'You can comment on this note, but not edit it.',
  viewer: 'You have view-only access to this note.',
}

/** Plain text, as paragraphs — `insertContent` on a raw string would eat the line breaks. */
function asParagraphs(text: string) {
  return text
    .split(/\n{2,}/)
    .map((block) => block.trim())
    .filter((block) => block !== '')
    .map((block) => ({ type: 'paragraph', content: [{ type: 'text', text: block }] }))
}

export function NoteEditor({ note, onSaved, onOpenNote, uploadImage }: NoteEditorProps) {
  const aiEnabled = useFeature('ai')
  const canEdit = note.capabilities.edit
  const queryClient = useQueryClient()
  const updateNote = useUpdateNote()

  const [title, setTitle] = useState(note.title ?? '')
  const [pasteError, setPasteError] = useState<string | null>(null)
  const [linkDialog, setLinkDialog] = useState<{ open: boolean; href: string }>({
    open: false,
    href: '',
  })
  const [imageDialogOpen, setImageDialogOpen] = useState(false)
  const [notePickerOpen, setNotePickerOpen] = useState(false)
  const [pulseSelection, setPulseSelection] = useState<{ from: number; to: number; text: string } | null>(
    null,
  )

  const titleField = useRef<HTMLTextAreaElement>(null)
  const slashMenuId = useId()
  const mentionMenuId = useId()
  const titleFieldId = useId()

  // What the editor has on screen, and what the server last confirmed. The
  // gap between the two is the whole cursor-jump problem; see the file note.
  const loadedNoteId = useRef(note.id)
  const loadedVersion = useRef(note.version)
  const applyingContent = useRef(false)

  const handleSaved = useCallback(
    (saved: Note) => {
      // Our own save came back. Recording its version here is what stops the
      // effect below from treating the response as someone else's edit and
      // re-setting the document out from under the caret.
      if (saved.version > loadedVersion.current) loadedVersion.current = saved.version
      onSaved?.(saved)
    },
    [onSaved],
  )

  const autosave = useAutosave({
    noteId: note.id,
    version: note.version,
    enabled: canEdit,
    save: ({ id, version, title: nextTitle, document }) =>
      updateNote.mutateAsync({
        id,
        version,
        title: nextTitle,
        document,
        revision_reason: 'autosave',
      }),
    onSaved: handleSaved,
  })

  const autosaveRef = useRef(autosave)
  autosaveRef.current = autosave

  const openNoteRef = useRef(onOpenNote)
  openNoteRef.current = onOpenNote

  const uploadImageRef = useRef(uploadImage)
  uploadImageRef.current = uploadImage

  const availabilityRef = useRef({ ai: aiEnabled })
  availabilityRef.current = { ai: aiEnabled }

  const actionsRef = useRef<SlashActions>({
    openNoteLinkPicker: () => setNotePickerOpen(true),
    openImageDialog: () => setImageDialogOpen(true),
    askPulse: () => undefined,
  })

  const slashBridge = useMemo(() => createSuggestionBridge(), [])
  const mentionBridge = useMemo(() => createSuggestionBridge(), [])

  // The note's members, fetched once per note: the `@` menu asks on every
  // keystroke and a request per character would be absurd.
  const membersRef = useRef<{ noteId: string; people: Promise<MentionItem[]> } | null>(null)
  const mentionItemsRef = useRef(async (query: string): Promise<MentionItem[]> => {
    const noteId = loadedNoteId.current
    if (membersRef.current?.noteId !== noteId) {
      membersRef.current = {
        noteId,
        people: api
          .get<NoteMember[]>(`/notes/${noteId}/members`)
          .then((members) =>
            members.map((member) => ({
              id: member.user_id,
              label: member.display_name?.trim() || member.email || 'Someone',
              entityType: 'user',
              entityId: member.user_id,
            })),
          )
          // A suggestion list is not the place to report a failed request:
          // an empty menu says "nobody to mention" and stays out of the way.
          .catch(() => []),
      }
    }

    const people = await membersRef.current.people
    const needle = query.toLowerCase()
    return people.filter((person) => person.label.toLowerCase().includes(needle)).slice(0, 8)
  })

  /**
   * Everything below is memoised with an empty dependency list on purpose.
   *
   * `useEditor` compares its options by identity on every render and calls
   * `setOptions` when any of them changed — which, with an extension array
   * rebuilt each render, would mean re-applying the whole ProseMirror view
   * while someone is typing into it. Nothing here needs to change after
   * creation: the editable flag is applied through `setEditable`, the document
   * through the effect below, and every callback reads a ref.
   */
  const initialContent = useMemo(() => withBlockIds(note.document), [])

  const editorProps = useMemo(
    () => ({
      attributes: {
        class: 'note-editor__surface',
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-label': 'Note body',
      },
    }),
    [],
  )

  const extensions = useMemo(
    () => [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
        // Link and Underline ship inside StarterKit in v3; adding the separate
        // packages as well would register each extension twice.
        link: {
          openOnClick: false,
          autolink: true,
          protocols: ['http', 'https', 'mailto', 'tel'],
          HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
        },
      }),
      Placeholder.configure({
        placeholder: 'Start writing, or press / for blocks',
      }),
      Highlight,
      TextStyle,
      Color,
      TaskList,
      TaskItem.configure({ nested: true }),
      Table.configure({ resizable: true }),
      TableRow,
      TableHeader,
      TableCell,
      NoteImage.configure({ allowBase64: false }),
      CharacterCount,
      Callout,
      DateChip,
      Mention,
      NoteLink.configure({
        onOpenNote: (noteId) => {
          const handler = openNoteRef.current
          if (handler) handler(noteId)
          else window.location.assign(`/notes/${noteId}`)
        },
      }),
      // Registered after every node that carries an id, so the global
      // attribute lands on all of them.
      BlockId,
      SlashCommandExtension.configure({
        bridge: slashBridge,
        items: (query) => filterSlashCommands(query, availabilityRef.current),
        run: (instance: Editor, command: SlashCommand) => command.run(instance, actionsRef.current),
      }),
      MentionSuggestion.configure({
        bridge: mentionBridge,
        items: (query) => mentionItemsRef.current(query),
      }),
      PasteHandling.configure({
        uploadImage: () => uploadImageRef.current ?? null,
        onError: setPasteError,
      }),
    ],
    [slashBridge, mentionBridge],
  )

  const editor = useEditor({
    immediatelyRender: false,
    content: initialContent,
    editable: canEdit,
    editorProps,
    extensions,
    onUpdate: ({ editor: instance }) => {
      if (applyingContent.current) return
      autosaveRef.current.schedule({ document: toStorableDocument(instance.getJSON()) })
    },
    onBlur: () => {
      void autosaveRef.current.flush()
    },
  })

  const applyContent = useCallback(
    (instance: Editor, document: NoteDocument) => {
      applyingContent.current = true
      instance.commands.setContent(withBlockIds(document), { emitUpdate: false })
      applyingContent.current = false
    },
    [],
  )

  useEffect(() => {
    if (!editor) return

    if (loadedNoteId.current !== note.id) {
      loadedNoteId.current = note.id
      loadedVersion.current = note.version
      setTitle(note.title ?? '')
      setPasteError(null)
      applyContent(editor, note.document)
      return
    }

    // Genuinely newer, and nothing of the user's would be thrown away by
    // taking it. Anything else is left alone — a caret that jumps mid-word
    // costs more than a few seconds of staleness.
    const isNewer = note.version > loadedVersion.current
    if (isNewer && !editor.isFocused && !autosave.hasPendingChanges) {
      loadedVersion.current = note.version
      setTitle(note.title ?? '')
      applyContent(editor, note.document)
    }
  }, [editor, note, autosave.hasPendingChanges, applyContent])

  useEffect(() => {
    editor?.setEditable(canEdit)
  }, [editor, canEdit])

  // The title grows with what is typed into it — including a long title that
  // arrived from the server, which is why this is an effect and not an
  // onChange handler.
  useEffect(() => {
    const field = titleField.current
    if (!field) return
    field.style.height = 'auto'
    field.style.height = `${field.scrollHeight}px`
  }, [title])

  const slashMenu = useSuggestionMenu(slashBridge)
  const mentionMenu = useSuggestionMenu(mentionBridge)
  const openMenu = slashMenu ?? mentionMenu
  const openMenuId = slashMenu ? slashMenuId : mentionMenuId

  // The caret never leaves the editor while a suggestion menu is open, so the
  // editor is what has to tell a screen reader which row is highlighted.
  useEffect(() => {
    const dom = editor?.view.dom
    if (!dom) return

    if (!openMenu) {
      dom.removeAttribute('aria-expanded')
      dom.removeAttribute('aria-controls')
      dom.removeAttribute('aria-activedescendant')
      return
    }

    dom.setAttribute('aria-expanded', 'true')
    dom.setAttribute('aria-controls', openMenuId)
    dom.setAttribute('aria-activedescendant', `${openMenuId}-option-${openMenu.activeIndex}`)
  }, [editor, openMenu, openMenuId])

  actionsRef.current = {
    openNoteLinkPicker: () => setNotePickerOpen(true),
    openImageDialog: () => setImageDialogOpen(true),
    askPulse: () => {
      if (!editor) return
      const { from, to } = editor.state.selection
      setPulseSelection({ from, to, text: editor.state.doc.textBetween(from, to, ' ') })
    },
  }

  const counts = useEditorState({
    editor,
    selector: ({ editor: instance }) => ({
      words: instance?.storage.characterCount.words() ?? 0,
      characters: instance?.storage.characterCount.characters() ?? 0,
    }),
  })

  const onTitleChange = (value: string) => {
    setTitle(value)
    autosave.schedule({ title: value.trim() === '' ? null : value })
  }

  const conflict = autosave.conflict

  const takeServerVersion = () => {
    const server = conflict?.serverNote
    if (!server || !editor) return

    loadedVersion.current = server.version
    setTitle(server.title ?? '')
    applyContent(editor, server.document)
    autosave.resume(server.version, true)
    // The rest of the app is still holding the version that lost; give it the
    // one now on screen rather than waiting for a refetch to disagree again.
    queryClient.setQueryData(queryKeys.notes.detail(server.id), server)
  }

  const keepMyVersion = () => {
    const version = conflict?.serverVersion ?? note.version
    autosave.resume(version)
    if (editor) autosave.schedule({ document: toStorableDocument(editor.getJSON()), title })
    void autosave.flush()
  }

  return (
    <div className="editor__scroll note-editor">
      <div className="editor__sheet">
        <label className="sr-only" htmlFor={titleFieldId}>
          Note title
        </label>
        <textarea
          ref={titleField}
          id={titleFieldId}
          className="editor__title"
          rows={1}
          placeholder="Untitled note"
          value={title}
          readOnly={!canEdit}
          spellCheck
          onChange={(event) => onTitleChange(event.target.value)}
          onBlur={() => void autosave.flush()}
          onKeyDown={(event) => {
            // A title is one line; Enter belongs to the body.
            if (event.key === 'Enter') {
              event.preventDefault()
              editor?.commands.focus('start')
            }
          }}
        />

        <div className="editor__meta-row">
          <span>
            {counts?.words ?? 0} {counts?.words === 1 ? 'word' : 'words'}
          </span>
          <span aria-hidden>·</span>
          <span>{counts?.characters ?? 0} characters</span>
          {!canEdit ? <Badge tone="neutral">Read only</Badge> : null}
          {autosave.state === 'saving' ? <span>Saving…</span> : null}
          <LiveStatus>
            {autosave.state === 'saved'
              ? 'Note saved'
              : autosave.state === 'conflict'
                ? 'This note was changed elsewhere'
                : autosave.state === 'error'
                  ? 'This note could not be saved'
                  : ''}
          </LiveStatus>
        </div>

        {!canEdit ? (
          <p className="editor__readonly-banner">
            <Icon name="lock" size={15} aria-hidden />
            {READ_ONLY_REASON[note.role] ?? 'You cannot edit this note.'}
          </p>
        ) : null}

        {conflict ? (
          <div className="editor__banner editor__banner--conflict" role="alert">
            <Icon name="alert" size={16} aria-hidden />
            <div className="editor__banner-body">
              <p className="editor__banner-title">{conflict.message}</p>
              <p className="editor__banner-text">
                Nothing has been overwritten. Choose which version to keep — your changes are
                still here until you do.
              </p>
              <div className="editor__banner-actions">
                <Button size="sm" onClick={takeServerVersion} disabled={!conflict.serverNote}>
                  Load their version
                </Button>
                <Button size="sm" variant="primary" onClick={keepMyVersion}>
                  Keep mine
                </Button>
              </div>
            </div>
          </div>
        ) : null}

        {autosave.state === 'error' && autosave.error ? (
          <div className="editor__banner editor__banner--error" role="alert">
            <Icon name={autosave.error.isOffline ? 'cloud-off' : 'alert'} size={16} aria-hidden />
            <div className="editor__banner-body">
              <p className="editor__banner-title">{autosave.error.message}</p>
              <div className="editor__banner-actions">
                <Button size="sm" icon="refresh" onClick={() => void autosave.flush()}>
                  Try again
                </Button>
              </div>
            </div>
          </div>
        ) : null}

        {pasteError ? (
          <div className="editor__banner editor__banner--error" role="alert">
            <Icon name="alert" size={16} aria-hidden />
            <div className="editor__banner-body">
              <p className="editor__banner-title">{pasteError}</p>
              <div className="editor__banner-actions">
                <Button size="sm" onClick={() => setPasteError(null)}>
                  Dismiss
                </Button>
              </div>
            </div>
          </div>
        ) : null}

        {editor ? (
          <EditorContent editor={editor} />
        ) : (
          <div className="note-editor__loading" aria-hidden>
            <Skeleton width="92%" height={17} />
            <Skeleton width="98%" height={17} />
            <Skeleton width="74%" height={17} />
            <Skeleton width="40%" height={17} />
          </div>
        )}
      </div>

      {editor ? (
        <BubbleToolbar
          editor={editor}
          aiEnabled={aiEnabled}
          onAskPulse={() => actionsRef.current.askPulse()}
          onEditLink={() => {
            const href: unknown = editor.getAttributes('link').href
            setLinkDialog({ open: true, href: typeof href === 'string' ? href : '' })
          }}
        />
      ) : null}

      {slashMenu ? (
        <SlashCommandMenu
          items={slashMenu.snapshot.items}
          activeIndex={slashMenu.activeIndex}
          loading={slashMenu.snapshot.loading}
          rect={slashMenu.snapshot.rect}
          idPrefix={slashMenuId}
          label="Blocks"
          emptyMessage="No blocks match what you typed."
          onHover={slashMenu.setActiveIndex}
          onSelect={slashMenu.select}
        />
      ) : null}

      {mentionMenu ? (
        <SlashCommandMenu
          items={mentionMenu.snapshot.items}
          activeIndex={mentionMenu.activeIndex}
          loading={mentionMenu.snapshot.loading}
          rect={mentionMenu.snapshot.rect}
          idPrefix={mentionMenuId}
          label="People on this note"
          emptyMessage="Nobody else has access to this note yet."
          onHover={mentionMenu.setActiveIndex}
          onSelect={mentionMenu.select}
        />
      ) : null}

      <LinkDialog
        open={linkDialog.open}
        initialHref={linkDialog.href}
        onClose={() => setLinkDialog({ open: false, href: '' })}
        onSubmit={(href) => {
          editor?.chain().focus().extendMarkRange('link').setLink({ href }).run()
          setLinkDialog({ open: false, href: '' })
        }}
        onRemove={() => {
          editor?.chain().focus().extendMarkRange('link').unsetLink().run()
          setLinkDialog({ open: false, href: '' })
        }}
      />

      <ImageDialog
        open={imageDialogOpen}
        uploader={uploadImage ?? null}
        onClose={() => setImageDialogOpen(false)}
        onInsert={(image) => {
          editor
            ?.chain()
            .focus()
            .insertContent({
              type: 'image',
              attrs: {
                src: image.src,
                alt: image.alt || null,
                attachmentId: image.attachmentId ?? null,
              },
            })
            .run()
          setImageDialogOpen(false)
        }}
      />

      <NoteLinkPicker
        open={notePickerOpen}
        currentNoteId={note.id}
        onClose={() => setNotePickerOpen(false)}
        onSelect={({ noteId, label }) => {
          editor?.chain().focus().insertNoteLink({ noteId, label }).run()
          setNotePickerOpen(false)
        }}
      />

      {/* Rendered only where the server reports AI; the toolbar entry that
          opens it is gated on the same flag. */}
      {aiEnabled && pulseSelection ? (
        <PulseSelectionDialog
          open
          noteId={note.id}
          selection={pulseSelection.text}
          onClose={() => setPulseSelection(null)}
          onReplaceSelection={(text) => {
            editor
              ?.chain()
              .focus()
              .setTextSelection({ from: pulseSelection.from, to: pulseSelection.to })
              .insertContent(asParagraphs(text))
              .run()
            setPulseSelection(null)
          }}
          onInsertBelow={(text) => {
            editor
              ?.chain()
              .focus()
              .setTextSelection(pulseSelection.to)
              .insertContent(asParagraphs(text))
              .run()
            setPulseSelection(null)
          }}
        />
      ) : null}
    </div>
  )
}

/** Re-exported so the notes feature can type the prop it passes in. */
export type { ImageUploader } from './extensions/pasteHandling'
