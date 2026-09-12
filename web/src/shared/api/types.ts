/**
 * The API's wire types.
 *
 * These mirror `server-php/src/Domain/Notes/NotePresenter.php` and the other
 * presenters exactly. When one changes, both change — that is the price of not
 * generating them, and it is cheaper than adding a codegen step to a build that
 * currently has none.
 *
 * `any` is not used anywhere in this file on purpose: an untyped note document
 * is how a renderer ends up trusting a shape the server never promised.
 */

// ---------------------------------------------------------------------------
// Envelope
// ---------------------------------------------------------------------------

export interface ApiSuccess<T> {
  success: true
  data: T
  meta?: ApiMeta
}

export interface ApiMeta {
  next_cursor?: string | null
  has_more?: boolean
  total?: number
  [key: string]: unknown
}

export interface ApiErrorBody {
  success: false
  error: {
    code: string
    message: string
    details?: Record<string, unknown>
  }
}

/** Error codes the UI branches on. Anything else is handled generically. */
export const ERROR_CODES = {
  unauthenticated: 'UNAUTHENTICATED',
  accessDenied: 'NOTE_ACCESS_DENIED',
  notebookAccessDenied: 'NOTEBOOK_ACCESS_DENIED',
  notePrivate: 'NOTE_PRIVATE',
  notFound: 'NOT_FOUND',
  conflict: 'VERSION_CONFLICT',
  validation: 'VALIDATION_FAILED',
  rateLimited: 'RATE_LIMITED',
  featureDisabled: 'FEATURE_DISABLED',
  databaseNotConfigured: 'DATABASE_NOT_CONFIGURED',
  offline: 'OFFLINE',
} as const

// ---------------------------------------------------------------------------
// Note document (ProseMirror)
// ---------------------------------------------------------------------------

export interface DocMark {
  type: string
  attrs?: Record<string, string | number | boolean | null>
}

export interface DocNode {
  type: string
  attrs?: Record<string, string | number | boolean | null | number[]>
  content?: DocNode[]
  marks?: DocMark[]
  text?: string
}

export interface NoteDocument extends DocNode {
  type: 'doc'
  content?: DocNode[]
}

export const EMPTY_DOCUMENT: NoteDocument = { type: 'doc', content: [] }

// ---------------------------------------------------------------------------
// Notes
// ---------------------------------------------------------------------------

export const NOTE_TYPES = [
  'document',
  'checklist',
  'voice',
  'meeting',
  'drawing',
  'canvas',
  'scan',
] as const

export type NoteType = (typeof NOTE_TYPES)[number]

export type NoteColor =
  | 'coral' | 'peach' | 'sand' | 'sage' | 'mint'
  | 'sky' | 'lavender' | 'blush' | 'graphite'

export type NoteRole = 'owner' | 'editor' | 'commenter' | 'viewer'

export type PrivacyMode = 'standard' | 'private'

export interface NoteCapabilities {
  view: boolean
  comment: boolean
  edit: boolean
  share: boolean
  delete: boolean
  restore: boolean
  manage_members: boolean
}

export interface Tag {
  id: string
  name: string
  slug: string
  color: string | null
  note_count?: number
}

export interface ChecklistProgress {
  total: number
  done: number
}

/** What a card needs. Never carries the document — see NotePresenter::summary. */
export interface NoteSummary {
  id: string
  note_type: NoteType
  title: string | null
  /** Title, or the note's first line when it has none. Computed server-side. */
  display_title: string
  excerpt: string
  notebook_id: string | null
  color: NoteColor | null
  is_pinned: boolean
  is_favourite: boolean
  is_archived: boolean
  is_locked: boolean
  privacy_mode: PrivacyMode
  version: number
  word_count: number
  char_count: number
  owner_user_id: string
  created_at: string | null
  updated_at: string | null
  deleted_at: string | null
  role: NoteRole
  is_shared: boolean
  attachment_count: number
  has_reminder: boolean
  checklist: ChecklistProgress | null
  tags: Tag[]
}

export interface Note extends NoteSummary {
  document: NoteDocument
  document_schema_version: number
  content_hash: string
  source: string | null
  language: string | null
  template_key: string | null
  capabilities: NoteCapabilities
  backlink_count?: number
  actions?: NoteAction[]
}

export interface NoteAction {
  id: string
  note_id: string
  block_id: string | null
  text: string
  status: 'open' | 'done' | 'cancelled'
  priority: 'low' | 'normal' | 'high' | 'urgent' | null
  due_at: string | null
  assigned_user_id: string | null
  origin: 'manual' | 'checklist' | 'pulse'
  completed_at: string | null
  created_at: string
  updated_at: string
  note_title?: string | null
  note_type?: NoteType
}

// ---------------------------------------------------------------------------
// Notebooks, tags, smart folders
// ---------------------------------------------------------------------------

export interface Notebook {
  id: string
  parent_id: string | null
  name: string
  description: string | null
  icon: string | null
  color: string | null
  position: number
  depth: number
  is_archived: boolean
  note_count: number
  role: NoteRole
  children: Notebook[]
}

export interface SmartFolder {
  id: string
  name: string
  icon: string | null
  color: string | null
  rules: SmartFolderRules
  position: number
  note_count?: number
}

export interface SmartFolderRules {
  match: 'all' | 'any'
  conditions: SmartFolderCondition[]
}

export interface SmartFolderCondition {
  field: string
  operator: string
  value: string | number | boolean | Record<string, string> | null
}

// ---------------------------------------------------------------------------
// Collaboration
// ---------------------------------------------------------------------------

export interface NoteMember {
  user_id: string
  role: NoteRole
  display_name?: string | null
  email?: string | null
  invited_by?: string
  created_at?: string
}

export interface NoteComment {
  id: string
  note_id: string
  parent_id: string | null
  block_id: string | null
  anchor_text: string | null
  body: string
  author_user_id: string
  mentions: string[]
  resolved_at: string | null
  resolved_by: string | null
  /** True when the block this was anchored to no longer exists in the note. */
  orphaned?: boolean
  created_at: string
  updated_at: string
  replies?: NoteComment[]
}

export interface NoteRevision {
  id: string
  revision_number: number
  title: string | null
  reason: 'autosave' | 'manual' | 'restore' | 'import' | 'template'
  created_by: string
  created_at: string
  size: number
}

export interface NoteRevisionDetail extends Omit<NoteRevision, 'size'> {
  document: NoteDocument
}

export interface ActivityEntry {
  id: string
  actor_user_id: string
  action: string
  context: Record<string, unknown>
  created_at: string
}

export interface BacklinkEntry {
  note_id: string
  title: string | null
  note_type: NoteType
  excerpt?: string
  block_id?: string | null
  label?: string | null
  updated_at: string
}

export interface NoteLinks {
  incoming: BacklinkEntry[]
  outgoing: BacklinkEntry[]
}

// ---------------------------------------------------------------------------
// Attachments and reminders
// ---------------------------------------------------------------------------

export type AttachmentKind =
  | 'image' | 'pdf' | 'audio' | 'video' | 'document'
  | 'spreadsheet' | 'presentation' | 'text' | 'file'

export interface Attachment {
  id: string
  note_id: string
  block_id: string | null
  filename: string
  mime_type: string
  byte_size: number
  kind: AttachmentKind
  duration_seconds: number | null
  width: number | null
  height: number | null
  upload_status: 'pending' | 'uploading' | 'ready' | 'failed'
  processing_status: 'pending' | 'queued' | 'processing' | 'completed' | 'failed' | 'skipped'
  processing_error: string | null
  storage_provider: string
  drive_file_id: string | null
  content_url: string
  thumbnail_url: string | null
  created_at: string
}

export interface Reminder {
  id: string
  note_id: string
  action_id: string | null
  reminder_type: 'datetime' | 'recurring'
  due_at: string
  timezone: string
  recurrence_rule: string | null
  status: 'scheduled' | 'snoozed' | 'completed' | 'cancelled'
  snoozed_until: string | null
  completed_at: string | null
  note_title?: string | null
}

// ---------------------------------------------------------------------------
// Templates, search, Pulse
// ---------------------------------------------------------------------------

export interface NoteTemplate {
  id: string
  scope: 'system' | 'tenant' | 'user'
  template_key: string | null
  name: string
  description: string | null
  icon: string | null
  note_type: NoteType
  title_template: string | null
  default_tags: string[]
  document?: NoteDocument
  position: number
  editable: boolean
}

export interface SearchHit {
  note_id: string
  title: string | null
  display_title: string
  note_type: NoteType
  notebook_id: string | null
  /** Highlighted with the server's marker, never raw HTML. See parseHighlights(). */
  snippet: string
  score: number
  updated_at: string
  tags?: Tag[]
}

export interface SearchResponse {
  results: SearchHit[]
  has_more: boolean
  mode?: 'vector' | 'json_fallback' | 'keyword_fallback' | 'keyword'
}

export interface PulseCitation {
  note_id: string
  title: string | null
  block_id: string | null
  snippet: string
}

export interface PulseAnswer {
  answer: string
  citations: PulseCitation[]
  /** False when the model answered without grounding in the user's own notes. */
  grounded: boolean
  model?: string | null
}

export interface PulseActionDefinition {
  id: string
  label: string
  group: string
  scope: 'selection' | 'note' | 'notebook' | 'notes'
  output: 'text' | 'document_fragment' | 'checklist' | 'table' | 'structured'
  enabled: boolean
}

// ---------------------------------------------------------------------------
// Deployment configuration
// ---------------------------------------------------------------------------

export interface FeatureFlags {
  ai: boolean
  semantic_search: boolean
  ocr: boolean
  transcription: boolean
  canvas: boolean
  private_notes: boolean
  drive: boolean
  calendar: boolean
  contacts: boolean
  connect: boolean
}

export interface AppConfig {
  app: string
  env: string
  features: FeatureFlags
  limits: {
    max_attachment_bytes: number
    trash_retention_days: number
  }
}

export interface SidebarCounts {
  active: number
  archived: number
  trashed: number
  pinned: number
  favourite: number
  shared_with_me: number
}

export interface SessionInfo {
  authenticated: boolean
  uuid: string
  tenant_id: string | null
  display_name: string
  email: string
}
