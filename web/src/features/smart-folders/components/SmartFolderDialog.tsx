/**
 * The rule builder.
 *
 * A smart folder is a saved query, and this is where the query is written. The
 * design decision that matters is that **the builder only offers what the
 * server can execute**: every field and every operator comes from
 * `rules.ts`, which is a transcription of `NoteQuery::FIELDS`. There is no
 * free-text field name and no way to compose a rule that comes back as a 422
 * the user cannot act on.
 *
 * Each field gets the control its value actually needs — a notebook picker for
 * a notebook, a tag picker for a tag, a date for before/after, a number of
 * days for "within", a checkbox for a flag — because a text box asking for a
 * notebook id is a text box nobody can fill in.
 *
 * What is wrong is said beside the rule that is wrong. With up to 25 rules, a
 * banner at the top saying "invalid rules" is not a message, it is a puzzle.
 */

import { useEffect, useId, useMemo, useState } from 'react'

import { ApiError } from '../../../shared/api/client'
import { Icon } from '../../../shared/ui/Icon'
import { Button, Dialog } from '../../../shared/ui/primitives'
import type { SmartFolderCondition, SmartFolderRules } from '../../../shared/api/types'
import { useAppConfig } from '../../../app/AppConfigProvider'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { ColorField, IconField, ORGANISE_COLORS } from '../../organise/appearance'
import { flattenNotebooks, useNotebooks } from '../../notebooks/hooks/useNotebooks'
import { TagPicker } from '../../tags/components/TagPicker'
import {
  ENTITY_TYPE_OPTIONS,
  MAX_CONDITIONS,
  NOTE_TYPE_OPTIONS,
  OPERATOR_LABELS,
  RULE_FIELDS,
  RULE_GROUPS,
  conditionError,
  defaultValue,
  entityValue,
  newCondition,
  ruleField,
  scalarValue,
  valueKind,
} from '../rules'
import { EMPTY_RULES, formatMatchCount, useCreateSmartFolder, useUpdateSmartFolder } from '../hooks/useSmartFolders'
import type { SmartFolderNode } from '../hooks/useSmartFolders'
import '../smart-folders.css'

export interface SmartFolderDialogProps {
  open: boolean
  /** The folder being edited. Absent when creating. */
  folder?: SmartFolderNode | null
  onClose: () => void
  onSaved?: (folder: SmartFolderNode, wasCreated: boolean) => void
}

export function SmartFolderDialog({ open, folder = null, onClose, onSaved }: SmartFolderDialogProps) {
  const create = useCreateSmartFolder()
  const update = useUpdateSmartFolder()
  const nameId = useId()
  const matchName = useId()

  const [name, setName] = useState('')
  const [icon, setIcon] = useState<string | null>(null)
  const [color, setColor] = useState<string | null>(null)
  const [rules, setRules] = useState<SmartFolderRules>(EMPTY_RULES)
  const [error, setError] = useState<unknown>(null)

  useEffect(() => {
    if (!open) return
    setName(folder?.name ?? '')
    setIcon(folder?.icon ?? null)
    setColor(folder?.color ?? null)
    setRules(folder?.rules ?? EMPTY_RULES)
    setError(null)
  }, [open, folder])

  const editing = folder !== null
  const busy = create.isPending || update.isPending
  const fieldErrors = error instanceof ApiError ? error.fieldErrors : {}
  const trimmed = name.trim()

  const conditionErrors = useMemo(
    () => rules.conditions.map((condition) => conditionError(condition)),
    [rules.conditions],
  )
  const hasInvalidRule = conditionErrors.some((message) => message !== null)
  const atLimit = rules.conditions.length >= MAX_CONDITIONS

  const setCondition = (index: number, next: SmartFolderCondition) => {
    setRules((current) => ({
      ...current,
      conditions: current.conditions.map((condition, position) => (position === index ? next : condition)),
    }))
  }

  const submit = () => {
    if (trimmed === '' || hasInvalidRule || busy) return
    setError(null)

    const payload = { name: trimmed, rules, icon, color }
    const work = editing ? update.mutateAsync({ id: folder.id, ...payload }) : create.mutateAsync(payload)

    work
      .then((saved) => {
        onSaved?.(saved, !editing)
        onClose()
      })
      .catch(setError)
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={editing ? 'Edit smart folder' : 'New smart folder'}
      description="A smart folder finds notes. It never moves them, so a note can be in several at once."
      width={640}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button
            variant="primary"
            icon="check"
            loading={busy}
            disabled={trimmed === '' || hasInvalidRule}
            onClick={submit}
          >
            {editing ? 'Save folder' : 'Create folder'}
          </Button>
        </>
      }
    >
      <div className="org-form">
        {error && Object.keys(fieldErrors).length === 0 ? <ErrorNotice error={error} /> : null}

        <div className="org-field">
          <label className="org-label" htmlFor={nameId}>
            Name
          </label>
          <input
            id={nameId}
            className="org-input"
            value={name}
            maxLength={200}
            autoComplete="off"
            required
            disabled={busy}
            data-autofocus=""
            aria-invalid={fieldErrors.name !== undefined}
            onChange={(event) => setName(event.target.value)}
          />
          {fieldErrors.name ? (
            <p className="org-error" role="alert">
              {fieldErrors.name}
            </p>
          ) : null}
        </div>

        <IconField value={icon} fallback="sparkle-folder" disabled={busy} onChange={setIcon} />
        <ColorField value={color} disabled={busy} onChange={setColor} />

        <fieldset className="org-field sf-fieldset">
          <legend className="org-label">Which notes</legend>
          <div className="sf-match">
            {(['all', 'any'] as const).map((mode) => (
              <label className="sf-match__option" key={mode}>
                <input
                  type="radio"
                  name={matchName}
                  value={mode}
                  checked={rules.match === mode}
                  disabled={busy}
                  onChange={() => setRules((current) => ({ ...current, match: mode }))}
                />
                {mode === 'all' ? 'Match every rule' : 'Match any rule'}
              </label>
            ))}
          </div>
        </fieldset>

        {rules.conditions.length === 0 ? (
          <p className="org-empty">
            No rules yet — this folder would match every note you can open. Add a rule to narrow it.
          </p>
        ) : (
          <ul className="sf-rules">
            {rules.conditions.map((condition, index) => (
              <ConditionRow
                key={index}
                condition={condition}
                index={index}
                error={conditionErrors[index]}
                disabled={busy}
                onChange={(next) => setCondition(index, next)}
                onRemove={() =>
                  setRules((current) => ({
                    ...current,
                    conditions: current.conditions.filter((_item, position) => position !== index),
                  }))
                }
              />
            ))}
          </ul>
        )}

        <div className="sf-rules__footer">
          <Button
            icon="plus"
            size="sm"
            disabled={busy || atLimit}
            title={atLimit ? `A smart folder may have at most ${MAX_CONDITIONS} rules.` : undefined}
            onClick={() =>
              setRules((current) => ({ ...current, conditions: [...current.conditions, newCondition()] }))
            }
          >
            Add rule
          </Button>
          <span className="org-hint">
            {atLimit
              ? `That is the limit of ${MAX_CONDITIONS} rules.`
              : `${rules.conditions.length} of ${MAX_CONDITIONS} rules`}
          </span>
        </div>

        {/* The count the server last worked out for this folder. It cannot be
            recomputed while the rules are being edited — there is no endpoint
            that evaluates an unsaved tree — so the panel says which rules the
            number belongs to rather than implying it follows the draft. */}
        <MatchPreview folder={folder} dirty={editing && JSON.stringify(folder.rules) !== JSON.stringify(rules)} />
      </div>
    </Dialog>
  )
}

// ---------------------------------------------------------------------------
// One rule
// ---------------------------------------------------------------------------

function ConditionRow({
  condition,
  index,
  error,
  disabled,
  onChange,
  onRemove,
}: {
  condition: SmartFolderCondition
  index: number
  error: string | null
  disabled: boolean
  onChange: (next: SmartFolderCondition) => void
  onRemove: () => void
}) {
  const fieldId = useId()
  const operatorId = useId()
  const errorId = useId()

  const field = ruleField(condition.field)
  const operators = field?.operators ?? [condition.operator]

  return (
    <li className={`sf-rule ${error ? 'sf-rule--invalid' : ''}`.trim()}>
      <div className="org-field sf-rule__field">
        <label className="org-label" htmlFor={fieldId}>
          Rule {index + 1}
        </label>
        <select
          id={fieldId}
          className="org-select"
          value={condition.field}
          disabled={disabled}
          onChange={(event) => {
            const next = ruleField(event.target.value)
            if (next === null) return
            const operator = next.operators[0]
            onChange({ field: next.field, operator, value: defaultValue(next, operator) })
          }}
        >
          {/* Unknown fields can arrive from a folder saved by a newer release;
              keeping the stored value selectable stops editing the name from
              silently rewriting a rule nobody meant to touch. */}
          {field === null ? <option value={condition.field}>{condition.field}</option> : null}
          {RULE_GROUPS.map((group) => (
            <optgroup label={group} key={group}>
              {RULE_FIELDS.filter((option) => option.group === group).map((option) => (
                <option value={option.field} key={option.field}>
                  {option.label}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
      </div>

      <div className="org-field sf-rule__operator">
        <label className="org-label" htmlFor={operatorId}>
          Compare
        </label>
        <select
          id={operatorId}
          className="org-select"
          value={condition.operator}
          disabled={disabled || field === null}
          onChange={(event) => {
            if (field === null) return
            const operator = event.target.value
            onChange({ ...condition, operator, value: defaultValue(field, operator) })
          }}
        >
          {operators.map((operator) => (
            <option value={operator} key={operator}>
              {OPERATOR_LABELS[operator] ?? operator}
            </option>
          ))}
        </select>
      </div>

      <ConditionValue
        condition={condition}
        disabled={disabled}
        describedBy={error ? errorId : undefined}
        onChange={(value) => onChange({ ...condition, value })}
      />

      <span className="sf-rule__remove">
        <Button
          icon="close"
          iconOnly
          size="sm"
          variant="ghost"
          aria-label={`Remove rule ${index + 1}`}
          disabled={disabled}
          onClick={onRemove}
        />
      </span>

      {error ? (
        <p className="sf-rule__error" id={errorId} role="alert">
          {error}
        </p>
      ) : null}
    </li>
  )
}

// ---------------------------------------------------------------------------
// The control a value needs
// ---------------------------------------------------------------------------

function ConditionValue({
  condition,
  disabled,
  describedBy,
  onChange,
}: {
  condition: SmartFolderCondition
  disabled: boolean
  describedBy?: string
  onChange: (value: SmartFolderCondition['value']) => void
}) {
  const features = useAppConfig().features
  const notebooks = useNotebooks()
  const valueId = useId()
  const typeId = useId()

  const field = ruleField(condition.field)
  if (field === null) {
    return <p className="sf-rule__static">This rule was made by a newer version of Notes.</p>
  }

  const kind = valueKind(field, condition.operator)
  const invalid = describedBy !== undefined

  if (kind === 'none') {
    return <p className="sf-rule__static">Nothing more to choose.</p>
  }

  if (kind === 'boolean') {
    return (
      <div className="org-field sf-rule__value">
        <span className="org-label">Value</span>
        <label className="sf-toggle">
          <input
            type="checkbox"
            checked={condition.value === true || condition.value === 'true'}
            disabled={disabled}
            onChange={(event) => onChange(event.target.checked)}
          />
          {condition.value === true || condition.value === 'true' ? 'Yes' : 'No'}
        </label>
      </div>
    )
  }

  if (kind === 'tag') {
    // One tag per rule, so the picker is capped at one — and the same picker
    // as everywhere else, which is what makes "GST" and "gst" one tag here too.
    return (
      <div className="sf-rule__value">
        <TagPicker
          label="Tag"
          value={scalarValue(condition.value) === '' ? [] : [scalarValue(condition.value)]}
          max={1}
          disabled={disabled}
          onChange={(names) => onChange(names[0] ?? '')}
        />
      </div>
    )
  }

  if (kind === 'notebook') {
    const options = flattenNotebooks(notebooks.data ?? [])

    return (
      <div className="org-field sf-rule__value">
        <label className="org-label" htmlFor={valueId}>
          Notebook
        </label>
        <select
          id={valueId}
          className="org-select"
          value={scalarValue(condition.value)}
          disabled={disabled || notebooks.isPending}
          aria-invalid={invalid}
          aria-describedby={describedBy}
          onChange={(event) => onChange(event.target.value)}
        >
          <option value="">
            {notebooks.isPending ? 'Loading notebooks…' : notebooks.isError ? 'Notebooks unavailable' : 'Choose…'}
          </option>
          {options.map((option) => (
            <option value={option.id} key={option.id}>
              {`${'  '.repeat(option.depth)}${option.name}`}
            </option>
          ))}
        </select>
      </div>
    )
  }

  if (kind === 'note_type' || kind === 'color') {
    const options =
      kind === 'note_type'
        ? NOTE_TYPE_OPTIONS
        : ORGANISE_COLORS.filter((colour) => colour.value !== null).map((colour) => ({
            value: colour.value ?? '',
            label: colour.label,
          }))

    return (
      <div className="org-field sf-rule__value">
        <label className="org-label" htmlFor={valueId}>
          {kind === 'note_type' ? 'Type' : 'Colour'}
        </label>
        <select
          id={valueId}
          className="org-select"
          value={scalarValue(condition.value)}
          disabled={disabled}
          aria-invalid={invalid}
          aria-describedby={describedBy}
          onChange={(event) => onChange(event.target.value)}
        >
          {options.map((option) => (
            <option value={option.value} key={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>
    )
  }

  if (kind === 'entity') {
    const entity = entityValue(condition.value)
    const available = ENTITY_TYPE_OPTIONS.filter((option) => option.feature === undefined || features[option.feature])

    if (available.length === 0) {
      return <p className="sf-rule__static">No linked products are switched on for this workspace.</p>
    }

    return (
      <div className="sf-rule__value sf-rule__value--inline">
        <div className="org-field">
          <label className="org-label" htmlFor={typeId}>
            Record type
          </label>
          <select
            id={typeId}
            className="org-select"
            value={entity.entity_type}
            disabled={disabled}
            onChange={(event) => onChange({ ...entity, entity_type: event.target.value })}
          >
            <option value="">Choose…</option>
            {available.map((option) => (
              <option value={option.value} key={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
        <div className="org-field">
          <label className="org-label" htmlFor={valueId}>
            Record id
          </label>
          <input
            id={valueId}
            className="org-input"
            value={entity.entity_id}
            autoComplete="off"
            disabled={disabled}
            aria-invalid={invalid}
            aria-describedby={describedBy}
            onChange={(event) => onChange({ ...entity, entity_id: event.target.value })}
          />
        </div>
      </div>
    )
  }

  if (kind === 'days') {
    return (
      <div className="org-field sf-rule__value">
        <label className="org-label" htmlFor={valueId}>
          Days
        </label>
        <input
          id={valueId}
          className="org-input"
          type="number"
          min={1}
          max={3650}
          value={scalarValue(condition.value)}
          disabled={disabled}
          aria-invalid={invalid}
          aria-describedby={describedBy}
          onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))}
        />
      </div>
    )
  }

  if (kind === 'date') {
    return (
      <div className="org-field sf-rule__value">
        <label className="org-label" htmlFor={valueId}>
          Date
        </label>
        <input
          id={valueId}
          className="org-input"
          type="date"
          // A stored value may carry a time; the control only holds a day.
          value={scalarValue(condition.value).slice(0, 10)}
          disabled={disabled}
          aria-invalid={invalid}
          aria-describedby={describedBy}
          onChange={(event) => onChange(event.target.value)}
        />
      </div>
    )
  }

  return (
    <div className="org-field sf-rule__value">
      <label className="org-label" htmlFor={valueId}>
        {kind === 'owner' ? 'Account id' : 'Words'}
      </label>
      <input
        id={valueId}
        className="org-input"
        value={scalarValue(condition.value)}
        autoComplete="off"
        placeholder={kind === 'text' ? 'gst return "section 44"' : undefined}
        disabled={disabled}
        aria-invalid={invalid}
        aria-describedby={describedBy}
        onChange={(event) => onChange(event.target.value)}
      />
    </div>
  )
}

// ---------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------

function MatchPreview({ folder, dirty }: { folder: SmartFolderNode | null; dirty: boolean }) {
  if (folder === null) {
    return (
      <p className="org-hint">
        <Icon name="info" size={13} /> The number of matching notes appears here once the folder is saved.
      </p>
    )
  }

  if (folder.rules_valid === false) {
    return (
      <div className="sf-preview" role="status">
        <Icon name="alert" size={16} />
        <span className="sf-preview__body">
          These rules could not be run last time they were tried. Fixing a rule below and saving will re-check them.
        </span>
      </div>
    )
  }

  const count = formatMatchCount(folder)
  if (count === null) return null

  return (
    <div className="sf-preview" role="status">
      <span className="sf-preview__count">{count}</span>
      <span className="sf-preview__body">
        {folder.note_count === 1 ? 'note matches' : 'notes match'}
        {dirty ? ' the rules as they are saved. Save to bring this up to date.' : ' right now.'}
      </span>
    </div>
  )
}
