/**
 * The "/" menu, as a registry.
 *
 * A hardcoded menu means every new block type is a change to the menu
 * component, its keyboard handling and its tests. Here a block type is one
 * object: what it is called, what it is filed under, how it is found, and what
 * it does. Adding one — from this feature or from another — is
 * {@link registerSlashCommand}, and nothing else moves.
 *
 * Two rules the registry enforces rather than leaves to callers:
 *
 *   - **A command whose capability is off is not listed.** Not greyed out, not
 *     "coming soon" — absent. A menu entry that returns a 503 is worse than no
 *     entry at all.
 *   - **The typed range is already gone** by the time `run` is called, so a
 *     command never has to know it was invoked from a menu.
 */

import type { Editor } from '@tiptap/core'

import type { IconName } from '../../shared/ui/Icon'
import type { SuggestionMenuItem } from './extensions/suggestionBridge'

/** Group headings, in the order they appear in the menu. */
export const SLASH_GROUPS = ['Basic', 'Lists', 'Blocks', 'Insert'] as const
export type SlashGroup = (typeof SLASH_GROUPS)[number]

/** What the deployment and the current note allow. */
export interface SlashAvailability {
  ai: boolean
  canvas: boolean
  /** False until something can actually accept an image. */
  canInsertImages: boolean
}

/**
 * Side effects a command cannot perform on the editor alone — anything that
 * needs to ask the user something first.
 */
export interface SlashActions {
  openNoteLinkPicker: () => void
  openImageDialog: () => void
}

export interface SlashCommand extends SuggestionMenuItem {
  id: string
  label: string
  group: SlashGroup
  icon: IconName
  /** Extra words that should find this command; the label always matches. */
  keywords: readonly string[]
  hint?: string
  /** Omitted means always available. */
  isAvailable?: (availability: SlashAvailability) => boolean
  run: (editor: Editor, actions: SlashActions) => void
}

const BUILT_IN: SlashCommand[] = [
  {
    id: 'text',
    label: 'Text',
    group: 'Basic',
    icon: 'note',
    keywords: ['paragraph', 'plain', 'body'],
    hint: 'Plain paragraph',
    run: (editor) => editor.chain().focus().setParagraph().run(),
  },
  {
    id: 'h1',
    label: 'Heading 1',
    group: 'Basic',
    icon: 'heading',
    keywords: ['title', 'large', 'h1'],
    hint: '#',
    run: (editor) => editor.chain().focus().setNode('heading', { level: 1 }).run(),
  },
  {
    id: 'h2',
    label: 'Heading 2',
    group: 'Basic',
    icon: 'heading',
    keywords: ['section', 'medium', 'h2'],
    hint: '##',
    run: (editor) => editor.chain().focus().setNode('heading', { level: 2 }).run(),
  },
  {
    id: 'h3',
    label: 'Heading 3',
    group: 'Basic',
    icon: 'heading',
    keywords: ['subsection', 'small', 'h3'],
    hint: '###',
    run: (editor) => editor.chain().focus().setNode('heading', { level: 3 }).run(),
  },
  {
    id: 'bullet-list',
    label: 'Bulleted list',
    group: 'Lists',
    icon: 'list',
    keywords: ['unordered', 'ul', 'points'],
    hint: '-',
    run: (editor) => editor.chain().focus().toggleBulletList().run(),
  },
  {
    id: 'ordered-list',
    label: 'Numbered list',
    group: 'Lists',
    icon: 'ordered-list',
    keywords: ['ordered', 'ol', 'steps'],
    hint: '1.',
    run: (editor) => editor.chain().focus().toggleOrderedList().run(),
  },
  {
    id: 'task-list',
    label: 'Checklist',
    group: 'Lists',
    icon: 'checklist',
    keywords: ['todo', 'task', 'tick', 'box'],
    hint: '[]',
    run: (editor) => editor.chain().focus().toggleTaskList().run(),
  },
  {
    id: 'quote',
    label: 'Quote',
    group: 'Blocks',
    icon: 'quote',
    keywords: ['blockquote', 'citation'],
    hint: '>',
    run: (editor) => editor.chain().focus().toggleBlockquote().run(),
  },
  {
    id: 'callout',
    label: 'Callout',
    group: 'Blocks',
    icon: 'callout',
    keywords: ['note', 'warning', 'panel', 'aside'],
    run: (editor) => editor.chain().focus().toggleCallout('info').run(),
  },
  {
    id: 'code-block',
    label: 'Code block',
    group: 'Blocks',
    icon: 'code',
    keywords: ['snippet', 'monospace', 'pre'],
    hint: '```',
    run: (editor) => editor.chain().focus().toggleCodeBlock().run(),
  },
  {
    id: 'divider',
    label: 'Divider',
    group: 'Blocks',
    icon: 'divider',
    keywords: ['rule', 'separator', 'hr', 'line'],
    hint: '---',
    run: (editor) => editor.chain().focus().setHorizontalRule().run(),
  },
  {
    id: 'table',
    label: 'Table',
    group: 'Blocks',
    icon: 'table',
    keywords: ['grid', 'rows', 'columns'],
    run: (editor) =>
      editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
  },
  {
    id: 'image',
    label: 'Image',
    group: 'Insert',
    icon: 'image',
    keywords: ['picture', 'photo', 'upload'],
    run: (_editor, actions) => actions.openImageDialog(),
  },
  {
    id: 'note-link',
    label: 'Link to note',
    group: 'Insert',
    icon: 'link',
    keywords: ['mention', 'reference', 'backlink', 'wiki'],
    run: (_editor, actions) => actions.openNoteLinkPicker(),
  },
  {
    id: 'date',
    label: "Today's date",
    group: 'Insert',
    icon: 'calendar',
    keywords: ['today', 'time', 'day'],
    run: (editor) => editor.chain().focus().insertDateChip(new Date()).run(),
  },
]

const registry: SlashCommand[] = [...BUILT_IN]

/**
 * Add a command, or replace one with the same id.
 *
 * Replacing rather than appending means a feature can override a built-in
 * without the menu showing both.
 */
export function registerSlashCommand(command: SlashCommand): void {
  const existing = registry.findIndex((entry) => entry.id === command.id)
  if (existing >= 0) registry[existing] = command
  else registry.push(command)
}

export function unregisterSlashCommand(id: string): void {
  const index = registry.findIndex((entry) => entry.id === id)
  if (index >= 0) registry.splice(index, 1)
}

export function slashCommands(): readonly SlashCommand[] {
  return registry
}

/** Restores the built-in set. Used by tests, so one case cannot leak into the next. */
export function resetSlashCommands(): void {
  registry.splice(0, registry.length, ...BUILT_IN)
}

function matches(command: SlashCommand, query: string): boolean {
  if (query === '') return true

  const needle = query.toLowerCase()
  if (command.label.toLowerCase().includes(needle)) return true
  if (command.group.toLowerCase().startsWith(needle)) return true
  return command.keywords.some((keyword) => keyword.toLowerCase().includes(needle))
}

/**
 * The commands to show for what has been typed so far.
 *
 * Group order comes from {@link SLASH_GROUPS} rather than from registration
 * order, so a command added later still lands under the right heading.
 */
export function filterSlashCommands(
  query: string,
  availability: SlashAvailability,
  commands: readonly SlashCommand[] = registry,
): SlashCommand[] {
  const usable = commands.filter(
    (command) => (command.isAvailable?.(availability) ?? true) && matches(command, query),
  )

  return usable.sort((a, b) => SLASH_GROUPS.indexOf(a.group) - SLASH_GROUPS.indexOf(b.group))
}
