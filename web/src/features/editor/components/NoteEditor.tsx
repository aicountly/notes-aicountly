/**
 * Import shim.
 *
 * The editor lives at `features/editor/NoteEditor.tsx`; the notes feature
 * reaches for it at `features/editor/components/NoteEditor`. One re-export is
 * cheaper than a rename that would break whichever of the two moved second,
 * and it keeps a single implementation.
 */

export { NoteEditor } from '../NoteEditor'
export type { NoteEditorProps, ImageUploader } from '../NoteEditor'
