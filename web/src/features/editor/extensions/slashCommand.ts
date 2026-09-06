/**
 * The "/" trigger.
 *
 * This extension owns exactly two things: noticing that a slash was typed at a
 * word boundary, and removing the typed text before the chosen command runs.
 * What the menu contains lives in the registry, and what the menu looks like
 * lives in React — so neither has to change when the other does.
 */

import { Extension } from '@tiptap/core'
import type { Editor } from '@tiptap/core'
import { PluginKey } from '@tiptap/pm/state'
import Suggestion from '@tiptap/suggestion'

import type { SlashCommand } from '../slashCommands'
import { createSuggestionBridge, suggestionRenderer } from './suggestionBridge'
import type { SuggestionBridge } from './suggestionBridge'

export interface SlashCommandOptions {
  bridge: SuggestionBridge
  /** Read fresh on every keystroke, so a flag flipping mid-session is honoured. */
  items: (query: string) => SlashCommand[]
  run: (editor: Editor, command: SlashCommand) => void
}

export const SlashCommandExtension = Extension.create<SlashCommandOptions>({
  name: 'slashCommand',

  addOptions() {
    return {
      bridge: createSuggestionBridge(),
      items: () => [],
      run: () => undefined,
    }
  },

  addProseMirrorPlugins() {
    return [
      Suggestion<SlashCommand, SlashCommand>({
        editor: this.editor,
        char: '/',
        pluginKey: new PluginKey('slashCommand'),
        // The default prefix rule (start of line, or after a space) is what
        // keeps `https://` and `and/or` from opening a menu mid-sentence.
        allowSpaces: false,
        items: ({ query }) => this.options.items(query),
        command: ({ editor, range, props }) => {
          // Delete the "/query" first: every command in the registry can then
          // assume an ordinary cursor and never has to know about ranges.
          editor.chain().focus().deleteRange(range).run()
          this.options.run(editor, props)
        },
        render: suggestionRenderer<SlashCommand>(this.options.bridge),
      }),
    ]
  },
})
