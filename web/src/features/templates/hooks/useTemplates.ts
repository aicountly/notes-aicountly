/**
 * The starting points a note can be created from.
 *
 * Three things about templates shape every screen in this folder, and all three
 * come from `NoteTemplateService`:
 *
 *   - **Scope decides who owns a template, not what is in it.** System
 *     templates ship with the deployment, company templates are shared with
 *     colleagues, and personal ones are private. The list arrives already in
 *     that order, and {@link groupTemplates} keeps it there.
 *   - **`editable` is the server's answer, not a guess.** A built-in refuses an
 *     edit with `TEMPLATE_READ_ONLY` and a colleague's with
 *     `TEMPLATE_NOT_OWNED`, so the UI reads the flag and offers the control
 *     only where it would work — see {@link readOnlyReason} for the sentence to
 *     show instead.
 *   - **The list never carries documents.** `GET /templates` deliberately omits
 *     `document_json`, so a preview is a second request for one template
 *     ({@link useTemplate}) rather than a payload every picker pays for.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { QueryClient, UseQueryResult } from '@tanstack/react-query'

import { ApiError, api } from '../../../shared/api/client'
import { queryKeys } from '../../../shared/query/queryClient'
import type { Note, NoteDocument, NoteTemplate, NoteType } from '../../../shared/api/types'

export type TemplateScope = NoteTemplate['scope']

/** What `GET /templates/{id}` answers: the row, document included. */
export interface NoteTemplateDetail extends NoteTemplate {
  document: NoteDocument
}

/** Limits mirrored from `NoteTemplateService`, so a field stops where it stops. */
export const MAX_NAME = 200
export const MAX_DESCRIPTION = 2000
export const MAX_TITLE_TEMPLATE = 500
export const MAX_DEFAULT_TAGS = 20

export const NOTE_TYPE_LABELS: Record<NoteType, string> = {
  document: 'Document',
  checklist: 'Checklist',
  voice: 'Voice note',
  meeting: 'Meeting',
  drawing: 'Drawing',
  canvas: 'Canvas',
  scan: 'Scan',
}

// ---------------------------------------------------------------------------
// Title tokens
// ---------------------------------------------------------------------------

/**
 * The tokens the server substitutes into a title, and the whole list.
 *
 * Transcribed from `NoteTemplateService::renderTitle()`. Anything else in
 * braces is left exactly as typed — `{{client}}` is a blank a person fills in,
 * not a token that quietly disappears — which is why this is stated in the
 * editor rather than left for someone to discover.
 */
export const TITLE_TOKENS: { token: string; description: string }[] = [
  { token: '{{date}}', description: "today's date, as 2026-09-06" },
  { token: '{{time}}', description: 'the time, 24-hour, as 14:05' },
]

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

/**
 * What the server would make of this title, right now.
 *
 * Computed in the browser's timezone because that is the timezone
 * {@link useCreateNoteFromTemplate} sends, so the preview and the note agree.
 * Only the two real tokens are replaced: showing `{{client}}` resolved to
 * nothing would advertise a substitution that will not happen.
 */
export function renderTitlePreview(titleTemplate: string | null, now: Date = new Date()): string {
  if (titleTemplate === null || titleTemplate.trim() === '') return ''

  const date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
  const time = `${pad(now.getHours())}:${pad(now.getMinutes())}`

  return titleTemplate.replaceAll('{{date}}', date).replaceAll('{{time}}', time).trim()
}

/**
 * The IANA name to resolve `{{date}}` in, or nothing.
 *
 * `undefined` is dropped from the JSON body, and the server then answers UTC —
 * which is the documented fallback, not a failure.
 */
export function browserTimezone(): string | undefined {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined
  } catch {
    return undefined
  }
}

// ---------------------------------------------------------------------------
// Grouping and permissions
// ---------------------------------------------------------------------------

export const SCOPE_LABEL: Record<TemplateScope, string> = {
  system: 'Built in',
  tenant: 'Company',
  user: 'Yours',
}

export interface TemplateGroup {
  scope: TemplateScope
  title: string
  description: string
  templates: NoteTemplate[]
}

const GROUPS: { scope: TemplateScope; title: string; description: string }[] = [
  {
    scope: 'system',
    title: 'Built in',
    description: 'Ship with Notes. Everyone has them, nobody can change them.',
  },
  {
    scope: 'tenant',
    title: 'Shared with your company',
    description: 'Everyone at your company can start a note from these.',
  },
  { scope: 'user', title: 'Yours', description: 'Only you can see these.' },
]

/**
 * The list split into the three scopes, in picker order.
 *
 * A scope nobody has anything in is dropped rather than rendered as an empty
 * heading: a person with no company templates should not be shown a section
 * telling them so on every visit.
 */
export function groupTemplates(templates: NoteTemplate[]): TemplateGroup[] {
  return GROUPS.map((group) => ({
    ...group,
    templates: templates.filter((template) => template.scope === group.scope),
  })).filter((group) => group.templates.length > 0)
}

/** Why this template cannot be changed, in the server's own words, or null. */
export function readOnlyReason(template: NoteTemplate): string | null {
  if (template.editable) return null

  return template.scope === 'system'
    ? 'Built-in templates cannot be changed. Start a note from it and save that as your own template instead.'
    : 'Only the person who created this template can change it.'
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

export function useTemplates(): UseQueryResult<NoteTemplate[], ApiError> {
  return useQuery<NoteTemplate[], ApiError>({
    queryKey: queryKeys.templates,
    queryFn: () => api.get<NoteTemplate[]>('/templates'),
    // Templates change when somebody edits one, which is rare and always from
    // this screen — where the mutation invalidates the list anyway.
    staleTime: 5 * 60_000,
  })
}

/** One template with its document. Only fetched when something is previewing it. */
export function useTemplate(
  templateId: string | null | undefined,
): UseQueryResult<NoteTemplateDetail, ApiError> {
  return useQuery<NoteTemplateDetail, ApiError>({
    // Under the list's key, so invalidating the list re-reads an open preview.
    queryKey: [...queryKeys.templates, templateId ?? ''],
    enabled: Boolean(templateId),
    queryFn: () => api.get<NoteTemplateDetail>(`/templates/${templateId}`),
  })
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

export interface CreateTemplateInput {
  name: string
  description?: string | null
  icon?: string | null
  note_type?: NoteType
  title_template?: string | null
  default_tags?: string[]
  scope?: Exclude<TemplateScope, 'system'>
  /** Save an existing note as a template: its document, type and tags are copied. */
  from_note_id?: string
}

export type UpdateTemplateInput = Omit<CreateTemplateInput, 'scope' | 'from_note_id'> & { id: string }

export function useCreateTemplate() {
  const client = useQueryClient()

  return useMutation<NoteTemplateDetail, ApiError, CreateTemplateInput>({
    mutationFn: (input) => api.post<NoteTemplateDetail>('/templates', input),
    onSuccess: () => invalidateTemplates(client),
  })
}

export function useUpdateTemplate() {
  const client = useQueryClient()

  return useMutation<NoteTemplateDetail, ApiError, UpdateTemplateInput>({
    mutationFn: ({ id, ...patch }) => api.patch<NoteTemplateDetail>(`/templates/${id}`, patch),
    onSuccess: () => invalidateTemplates(client),
  })
}

export function useDeleteTemplate() {
  const client = useQueryClient()

  return useMutation<void, ApiError, string>({
    mutationFn: (id) => api.delete(`/templates/${id}`),
    onSuccess: () => invalidateTemplates(client),
  })
}

export interface CreateFromTemplateInput {
  templateId: string
  /** Overrides the template's own title. Goes through the same substitution. */
  title?: string
  notebook_id?: string | null
  /** Replaces the template's default tags rather than adding to them. */
  tags?: string[]
}

/**
 * Start a note from a template.
 *
 * The answer is a note exactly as `POST /notes` would have made it, so it is
 * written straight into the note caches — the editor the caller navigates to
 * then opens on it without a second round trip.
 */
export function useCreateNoteFromTemplate() {
  const client = useQueryClient()

  return useMutation<Note, ApiError, CreateFromTemplateInput>({
    mutationFn: ({ templateId, ...input }) =>
      api.post<Note>(`/templates/${templateId}/create-note`, {
        ...input,
        timezone: browserTimezone(),
      }),
    onSuccess: (note) => {
      client.setQueryData(queryKeys.notes.detail(note.id), note)
      void client.invalidateQueries({ queryKey: queryKeys.notes.all })
      void client.invalidateQueries({ queryKey: queryKeys.notes.counts })
    },
  })
}

function invalidateTemplates(client: QueryClient): void {
  void client.invalidateQueries({ queryKey: queryKeys.templates })
}
