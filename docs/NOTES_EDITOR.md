# The editor

Tiptap (ProseMirror) — not a `contenteditable` div and not a from-scratch editor.
Notes needs structured documents, custom blocks, slash commands, mentions,
tables, comments anchored to blocks and a path to collaborative editing; writing
that is a multi-year project that has already been done well.

## The document

A ProseMirror JSON tree, stored in `notes.document_json` as `jsonb`. See
`ARCHITECTURE.md` for why it is a tree rather than an HTML field.

**Every block node carries a `blockId`** — a UUID minted by the editor. It is
what lets a comment stay anchored, a due date stay attached to a checklist line
after the line is reworded, and a backlink scroll to the right paragraph. The
server mints one for any block that arrives without it, so the invariant holds
even for documents that came from an import.

The editor may only produce node types and attributes that
`NoteDocument::ALLOWED_NODES` / `ALLOWED_ATTRS` accept. Anything else is silently
dropped on save — which would look like a bug to the user, so adding a block type
means adding it in both places.

## What the editor supports

Paragraphs; H1–H3; bold, italic, underline, strikethrough, inline code,
highlight, text colour; bullet, numbered and nested lists; checklists;
blockquotes; callouts; code blocks; horizontal rules; tables; links; internal
`[[note links]]`; `@mentions`; date chips; images; file and audio attachments;
undo/redo; markdown-style input rules (`# `, `- `, `1. `, `> `, ``` ).

## Slash commands

Typing `/` opens a searchable menu backed by a **registry**, not a hardcoded
list:

```ts
{ id, label, group, icon, keywords, isAvailable?, run(editor) }
```

New blocks register an entry; nothing else changes. Commands whose feature is
switched off for the deployment are not listed at all — a menu item that fails on
click is worse than one that is absent.

## The selection toolbar

Appears on selection, disappears when it is gone. Bold, italic, underline,
strike, highlight, code, link — and **Ask Pulse**, which is only rendered when
the AI flag is on.

## Autosave, and the cursor

Debounced, with a hard flush during continuous typing, plus a flush on blur and
on page hide. Full details in `OFFLINE_SYNC.md`.

The subtle part is **not moving the cursor**. A background refetch that returns
the note and re-sets the editor's content while someone is typing will jump the
caret to the start and lose what they were writing. Content is set only when the
note id changes, or when a genuinely newer version arrives *and* the editor is
not focused. Everything else is applied to the cache, not to the editor.

## Paste

- Plain text stays plain.
- A URL pasted over a selection becomes a link on that selection.
- Rich HTML goes through ProseMirror's parser, which keeps only schema-valid
  nodes; `style` attributes and `on*` handlers are stripped as well.
- Pasted or dropped images upload as attachments and become image nodes.

The server sanitises again on save regardless. Client-side cleaning is for
tidiness; the server's allowlist is the control.

## Read-only

When `note.capabilities.edit` is false the editor is not editable and a banner
says why — "You can comment on this note, but not edit it" for a commenter,
"You have view-only access" for a viewer. The distinction matters: silently
swallowing keystrokes reads as a broken editor.

## Not implemented

- **Collaborative cursors and per-keystroke co-editing.** The document format is
  Yjs-compatible; there is no realtime server, and none was built to fake it.
  What the `realtime` flag does turn on — who else has a note open, and a
  prompt refetch when someone else's save lands — is `docs/REALTIME.md`.
- **Infinite canvas.** The note type and its storage exist; the canvas editor
  does not, and the flag stays off.
- **Handwriting, ink-to-text, stylus and shape recognition.** Extension points
  exist (a canvas note type, an attachment kind, the OCR job pipeline); the
  features do not.
