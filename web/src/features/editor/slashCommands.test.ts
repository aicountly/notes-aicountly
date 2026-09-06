/**
 * The slash registry.
 *
 * The behaviours worth protecting are the ones a user meets: typing a word
 * finds the block they meant, a switched-off capability is not offered at all,
 * and a feature that adds its own command gets it in the right group.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Editor } from '@tiptap/core'

import {
  filterSlashCommands,
  registerSlashCommand,
  resetSlashCommands,
  slashCommands,
  unregisterSlashCommand,
} from './slashCommands'
import type { SlashActions } from './slashCommands'

const ALL_ON = { ai: true }
const ALL_OFF = { ai: false }

function ids(query: string, availability = ALL_ON): string[] {
  return filterSlashCommands(query, availability).map((command) => command.id)
}

describe('slash command registry', () => {
  afterEach(() => {
    resetSlashCommands()
  })

  it('offers every block type when nothing has been typed', () => {
    const offered = ids('')

    expect(offered).toEqual(
      expect.arrayContaining([
        'text',
        'h1',
        'h2',
        'h3',
        'bullet-list',
        'ordered-list',
        'task-list',
        'quote',
        'callout',
        'divider',
        'table',
        'code-block',
        'image',
        'note-link',
        'date',
      ]),
    )
  })

  it('finds a command by a word that is not in its label', () => {
    expect(ids('todo')).toEqual(['task-list'])
    expect(ids('backlink')).toEqual(['note-link'])
    expect(ids('separator')).toEqual(['divider'])
  })

  it('groups the results in a stable order', () => {
    const groups = filterSlashCommands('', ALL_ON).map((command) => command.group)
    const firstOfEach = groups.filter((group, index) => groups.indexOf(group) === index)

    expect(firstOfEach).toEqual(['Basic', 'Lists', 'Blocks', 'Insert'])
  })

  it('does not list a command whose capability is switched off', () => {
    expect(ids('', ALL_ON)).toContain('pulse')
    expect(ids('', ALL_OFF)).not.toContain('pulse')
    // Not merely filtered out of the menu — unfindable, so it cannot be
    // reached by typing its name either.
    expect(ids('pulse', ALL_OFF)).toEqual([])
  })

  it('accepts a command from another feature', () => {
    registerSlashCommand({
      id: 'voice-note',
      label: 'Voice note',
      group: 'Insert',
      icon: 'mic',
      keywords: ['record', 'audio'],
      run: () => undefined,
    })

    expect(ids('record')).toEqual(['voice-note'])
    expect(slashCommands().filter((command) => command.id === 'voice-note')).toHaveLength(1)

    unregisterSlashCommand('voice-note')
    expect(ids('record')).toEqual([])
  })

  it('replaces a built-in rather than listing it twice', () => {
    registerSlashCommand({
      id: 'date',
      label: 'Date',
      group: 'Insert',
      icon: 'calendar',
      keywords: [],
      run: () => undefined,
    })

    expect(slashCommands().filter((command) => command.id === 'date')).toHaveLength(1)
    expect(ids('date')).toEqual(['date'])
  })

  it('hands a command that needs to ask something the actions it needs', () => {
    const actions: SlashActions = {
      openNoteLinkPicker: vi.fn(),
      openImageDialog: vi.fn(),
      askPulse: vi.fn(),
    }
    const editor = {} as Editor

    filterSlashCommands('note-link', ALL_ON)
    const noteLink = slashCommands().find((command) => command.id === 'note-link')
    noteLink?.run(editor, actions)

    expect(actions.openNoteLinkPicker).toHaveBeenCalledTimes(1)
  })
})
