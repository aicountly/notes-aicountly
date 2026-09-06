/**
 * The seam between a ProseMirror suggestion plugin and a React menu.
 *
 * The plugin knows when a trigger character was typed and where the caret is;
 * React knows how to render a list and how to keep focus in the right place.
 * Rather than mounting a second React root from inside a plugin, the plugin
 * pushes a plain snapshot through this bridge and the editor renders it — one
 * React tree, one keyboard handler, and a menu that is trivially testable
 * because it takes props rather than an editor.
 *
 * The snapshot deliberately identifies a choice by `id` rather than carrying
 * the item back: it keeps this contract free of generics so `/` and `@` can
 * share one menu component.
 */

import type { SuggestionKeyDownProps, SuggestionProps } from '@tiptap/suggestion'

import type { IconName } from '../../../shared/ui/Icon'

export interface SuggestionMenuItem {
  id: string
  label: string
  /** Optional heading this item is filed under. */
  group?: string
  hint?: string
  icon?: IconName
}

export interface SuggestionMenuSnapshot {
  items: SuggestionMenuItem[]
  query: string
  loading: boolean
  /** The caret, in viewport coordinates. Null when it cannot be measured. */
  rect: DOMRect | null
  select: (id: string) => void
}

export interface SuggestionBridge {
  /** Installed by {@link useSuggestionMenu}; called by the plugin. */
  render: ((snapshot: SuggestionMenuSnapshot | null) => void) | null
  /** Returns true when the menu consumed the key. */
  keydown: ((event: KeyboardEvent) => boolean) | null
}

export function createSuggestionBridge(): SuggestionBridge {
  return { render: null, keydown: null }
}

interface SuggestionRenderer<T> {
  onStart: (props: SuggestionProps<T, T>) => void
  onUpdate: (props: SuggestionProps<T, T>) => void
  onExit: () => void
  onKeyDown: (props: SuggestionKeyDownProps) => boolean
}

export function suggestionRenderer<T extends SuggestionMenuItem>(
  bridge: SuggestionBridge,
): () => SuggestionRenderer<T> {
  const toSnapshot = (props: SuggestionProps<T, T>): SuggestionMenuSnapshot => ({
    items: props.items,
    query: props.query,
    loading: props.loading,
    rect: props.clientRect?.() ?? null,
    select: (id) => {
      const item = props.items.find((entry) => entry.id === id)
      if (item) props.command(item)
    },
  })

  return () => ({
    onStart: (props) => bridge.render?.(toSnapshot(props)),
    onUpdate: (props) => bridge.render?.(toSnapshot(props)),
    // Escape is handled by the plugin itself, which exits and lands here.
    onExit: () => bridge.render?.(null),
    onKeyDown: ({ event }) => bridge.keydown?.(event) ?? false,
  })
}
