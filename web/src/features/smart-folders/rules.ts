/**
 * The rule vocabulary, and only the rule vocabulary.
 *
 * This list is a transcription of `NoteQuery::FIELDS` — the server's complete
 * set of filterable fields and the operators each one accepts. It is kept as
 * data rather than as markup so the builder cannot offer a field the server
 * will reject: a rule tree is validated on save, and a folder that cannot be
 * executed can never reach the table, so the only way to give someone a
 * confusing 422 is to invent a field here.
 *
 * When `NoteQuery::FIELDS` changes, this changes with it. That is the price of
 * not generating it, and it is cheaper than a build step for one array.
 */

import type { FeatureFlags, SmartFolderCondition } from '../../shared/api/types'

/** A smart folder may hold at most this many conditions. Mirrors the server. */
export const MAX_CONDITIONS = 25

/** The control a condition's value needs, once its operator is known. */
export type RuleValueKind =
  | 'none'
  | 'notebook'
  | 'tag'
  | 'note_type'
  | 'color'
  | 'boolean'
  | 'owner'
  | 'entity'
  | 'date'
  | 'days'
  | 'text'

export type RuleGroup = 'Place' | 'Content' | 'Status' | 'People' | 'Time'

export interface RuleField {
  field: string
  label: string
  group: RuleGroup
  operators: readonly string[]
  /** The control this field takes when the operator does not decide it. */
  kind: RuleValueKind
}

export const RULE_GROUPS: readonly RuleGroup[] = ['Place', 'Content', 'Status', 'People', 'Time']

export const RULE_FIELDS: readonly RuleField[] = [
  { field: 'notebook', label: 'Notebook', group: 'Place', operators: ['is', 'is_not', 'is_empty'], kind: 'notebook' },
  { field: 'tag', label: 'Tag', group: 'Place', operators: ['is', 'is_not', 'is_empty'], kind: 'tag' },

  { field: 'note_type', label: 'Note type', group: 'Content', operators: ['is', 'is_not'], kind: 'note_type' },
  { field: 'color', label: 'Colour', group: 'Content', operators: ['is', 'is_empty'], kind: 'color' },
  { field: 'text', label: 'Text', group: 'Content', operators: ['contains'], kind: 'text' },
  { field: 'has_attachment', label: 'Has an attachment', group: 'Content', operators: ['is'], kind: 'boolean' },
  { field: 'has_pdf', label: 'Has a PDF', group: 'Content', operators: ['is'], kind: 'boolean' },
  { field: 'has_audio', label: 'Has audio', group: 'Content', operators: ['is'], kind: 'boolean' },

  { field: 'is_pinned', label: 'Pinned', group: 'Status', operators: ['is'], kind: 'boolean' },
  { field: 'is_favourite', label: 'Favourite', group: 'Status', operators: ['is'], kind: 'boolean' },
  { field: 'is_archived', label: 'Archived', group: 'Status', operators: ['is'], kind: 'boolean' },
  { field: 'has_reminder', label: 'Has a reminder', group: 'Status', operators: ['is'], kind: 'boolean' },
  { field: 'reminder_overdue', label: 'Reminder overdue', group: 'Status', operators: ['is'], kind: 'boolean' },
  { field: 'has_open_actions', label: 'Has open actions', group: 'Status', operators: ['is'], kind: 'boolean' },

  { field: 'is_shared', label: 'Shared with someone', group: 'People', operators: ['is'], kind: 'boolean' },
  { field: 'owner', label: 'Owner', group: 'People', operators: ['is', 'is_not'], kind: 'owner' },
  { field: 'entity', label: 'Linked record', group: 'People', operators: ['is'], kind: 'entity' },

  { field: 'created_at', label: 'Created', group: 'Time', operators: ['before', 'after', 'within_days'], kind: 'date' },
  { field: 'updated_at', label: 'Updated', group: 'Time', operators: ['before', 'after', 'within_days'], kind: 'date' },
]

const BY_FIELD = new Map(RULE_FIELDS.map((field) => [field.field, field]))

export function ruleField(name: string): RuleField | null {
  return BY_FIELD.get(name) ?? null
}

export const OPERATOR_LABELS: Record<string, string> = {
  is: 'is',
  is_not: 'is not',
  is_empty: 'is empty',
  before: 'is before',
  after: 'is after',
  within_days: 'is within the last',
  contains: 'contains',
}

/** The note types the server will accept for `note_type`. */
export const NOTE_TYPE_OPTIONS: { value: string; label: string }[] = [
  { value: 'document', label: 'Document' },
  { value: 'checklist', label: 'Checklist' },
  { value: 'voice', label: 'Voice note' },
  { value: 'meeting', label: 'Meeting' },
  { value: 'drawing', label: 'Drawing' },
  { value: 'canvas', label: 'Canvas' },
  { value: 'scan', label: 'Scan' },
]

/**
 * The records a note can be linked to.
 *
 * Each belongs to another AICOUNTLY product, so the ones behind a switched-off
 * capability are not offered — a rule matching Drive files on a deployment
 * without Drive is a rule that silently matches nothing.
 */
export const ENTITY_TYPE_OPTIONS: { value: string; label: string; feature?: keyof FeatureFlags }[] = [
  { value: 'contact', label: 'Contact', feature: 'contacts' },
  { value: 'company', label: 'Company', feature: 'contacts' },
  { value: 'employee', label: 'Employee' },
  { value: 'project', label: 'Project' },
  { value: 'invoice', label: 'Invoice' },
  { value: 'voucher', label: 'Voucher' },
  { value: 'calendar_event', label: 'Calendar event', feature: 'calendar' },
  { value: 'drive_file', label: 'Drive file', feature: 'drive' },
  { value: 'connect_meeting', label: 'Connect meeting', feature: 'connect' },
]

/** `is_empty` and `within_days` decide the control; otherwise the field does. */
export function valueKind(field: RuleField, operator: string): RuleValueKind {
  if (operator === 'is_empty') return 'none'
  if (operator === 'within_days') return 'days'
  if (operator === 'before' || operator === 'after') return 'date'

  return field.kind
}

/** A value the control can render the moment a field or operator changes. */
export function defaultValue(field: RuleField, operator: string): SmartFolderCondition['value'] {
  switch (valueKind(field, operator)) {
    case 'none':
      return null
    case 'boolean':
      return true
    case 'note_type':
      return 'document'
    case 'color':
      return 'sage'
    case 'days':
      return 30
    case 'date':
      return new Date().toISOString().slice(0, 10)
    case 'entity':
      return { entity_type: 'contact', entity_id: '' }
    default:
      return ''
  }
}

/** The condition a newly added row starts as. */
export function newCondition(): SmartFolderCondition {
  const field = RULE_FIELDS[0]
  const operator = field.operators[0]

  return { field: field.field, operator, value: defaultValue(field, operator) }
}

/** The object half of a condition value, or an empty one. */
export function entityValue(value: SmartFolderCondition['value']): { entity_type: string; entity_id: string } {
  if (value !== null && typeof value === 'object') {
    return { entity_type: value.entity_type ?? '', entity_id: value.entity_id ?? '' }
  }

  return { entity_type: '', entity_id: '' }
}

/** The scalar half, as a string the inputs can hold. */
export function scalarValue(value: SmartFolderCondition['value']): string {
  if (value === null || typeof value === 'object') return ''

  return String(value)
}

/**
 * What is wrong with this condition, in the words the user needs.
 *
 * The server refuses the same things on save. Checking here as well is not
 * duplication for its own sake: it puts the message beside the rule that is
 * wrong instead of at the top of a form with twenty-five of them.
 */
export function conditionError(condition: SmartFolderCondition): string | null {
  const field = ruleField(condition.field)
  if (field === null) return 'This rule uses a field this version does not know.'
  if (!field.operators.includes(condition.operator)) return 'Choose how to compare.'

  switch (valueKind(field, condition.operator)) {
    case 'none':
      return null
    case 'notebook':
      return scalarValue(condition.value) === '' ? 'Choose a notebook.' : null
    case 'tag':
      return scalarValue(condition.value).trim() === '' ? 'Choose or type a tag.' : null
    case 'owner':
      return scalarValue(condition.value).trim() === '' ? 'Enter an account id.' : null
    case 'text':
      return scalarValue(condition.value).trim() === '' ? 'Enter the words to look for.' : null
    case 'date':
      return scalarValue(condition.value) === '' ? 'Choose a date.' : null
    case 'days': {
      const days = Number(condition.value)
      return Number.isInteger(days) && days >= 1 && days <= 3650 ? null : 'Enter between 1 and 3650 days.'
    }
    case 'entity': {
      const entity = entityValue(condition.value)
      if (entity.entity_type === '') return 'Choose a record type.'
      return entity.entity_id.trim() === '' ? 'Enter the record id.' : null
    }
    default:
      return null
  }
}
