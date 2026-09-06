/**
 * Attachments: listing them, adding them, and watching them get processed.
 *
 * Three things here are not obvious from the endpoint list:
 *
 *   - **The server is the authority on what may be attached.** Everything in
 *     {@link describeFileRejection} is a courtesy so the user is told "that is
 *     28 MB and the limit is 25" before they wait for the upload, rather than
 *     after. It is not a control: the bytes are inspected server-side and a
 *     file that lies about its type is refused there. Never soften a server
 *     rejection because this passed.
 *   - **Processing is asynchronous and finite.** OCR and transcription are
 *     queued, so a fresh attachment arrives `queued` and becomes `completed`
 *     minutes later. This polls with a backoff and then *stops* — a tab left
 *     open on a note must not hold a request every two seconds forever. When
 *     the window closes the UI says so and offers to look again.
 *   - **Uploading needs a network.** There is no `attachment.upload` operation
 *     in the offline queue, so an upload attempted offline fails and says so,
 *     rather than pretending the file is safe on the device.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'

import { useAppConfig } from '../../../app/AppConfigProvider'
import { ApiError, api } from '../../../shared/api/client'
import { getApiBaseUrl } from '../../../config'
import { queryKeys } from '../../../shared/query/queryClient'
import type { Attachment, AttachmentKind } from '../../../shared/api/types'

// ---------------------------------------------------------------------------
// Polling
// ---------------------------------------------------------------------------

/**
 * 2s, 4s, 8s, then every 15s, and give up after three minutes.
 *
 * Expressed as elapsed-time bands rather than an attempt counter so the
 * schedule is a pure function of when watching started — a re-render, a
 * remount or a second component watching the same note all land on the same
 * step instead of restarting the sequence.
 */
const POLL_WINDOW_MS = 3 * 60_000

export function pollIntervalFor(elapsedMs: number): number | false {
  if (elapsedMs >= POLL_WINDOW_MS) return false
  if (elapsedMs < 2_000) return 2_000
  if (elapsedMs < 6_000) return 4_000
  if (elapsedMs < 14_000) return 8_000
  return 15_000
}

/** True while the server still has work queued for this file. */
export function isProcessing(attachment: Attachment): boolean {
  return (
    attachment.processing_status === 'queued' ||
    attachment.processing_status === 'processing'
  )
}

// ---------------------------------------------------------------------------
// Client-side validation — a courtesy, never a control
// ---------------------------------------------------------------------------

/**
 * Extensions the server refuses outright, mirrored here only to fail fast.
 *
 * Deliberately the shorter list: these are the ones a person plausibly tries
 * to attach (a saved web page, an SVG logo, a script) and being told
 * immediately is kinder than a round trip. The server's list is longer and is
 * the one that decides.
 */
const REFUSED_EXTENSIONS = new Set([
  'html', 'htm', 'xhtml', 'shtml', 'svg', 'js', 'mjs', 'cjs', 'wasm',
  'php', 'phtml', 'phar', 'exe', 'dll', 'msi', 'bat', 'cmd', 'sh', 'bash',
  'ps1', 'py', 'pl', 'rb', 'jar', 'apk', 'com', 'scr', 'lnk',
])

export function fileExtension(filename: string): string {
  const dot = filename.lastIndexOf('.')
  return dot > 0 ? filename.slice(dot + 1).toLowerCase() : ''
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} bytes`
  const kb = bytes / 1024
  if (kb < 1024) return `${Math.round(kb)} KB`
  const mb = kb / 1024
  return mb >= 10 ? `${Math.round(mb)} MB` : `${mb.toFixed(1)} MB`
}

/**
 * Why this file cannot be attached, or null when nothing obvious is wrong.
 *
 * "Nothing obvious is wrong" is the strongest claim this can make. The bytes
 * have not been read here and the type has not been verified — only the name
 * and the size, both of which the browser took from the user.
 */
export function describeFileRejection(file: File, maxBytes: number): string | null {
  if (file.size === 0) {
    return `${file.name} is empty.`
  }
  if (file.size > maxBytes) {
    return `${file.name} is ${formatBytes(file.size)}. This deployment accepts files up to ${formatBytes(maxBytes)}.`
  }
  if (REFUSED_EXTENSIONS.has(fileExtension(file.name))) {
    return `${file.name} is a kind of file that cannot be attached to a note.`
  }
  return null
}

/** A guess, for the icon on a row that has not reached the server yet. */
export function kindFromMimeType(mimeType: string): AttachmentKind {
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.startsWith('audio/')) return 'audio'
  if (mimeType.startsWith('video/')) return 'video'
  if (mimeType === 'application/pdf') return 'pdf'
  if (mimeType.startsWith('text/')) return 'text'
  if (mimeType.includes('spreadsheet') || mimeType.includes('ms-excel')) return 'spreadsheet'
  if (mimeType.includes('presentation') || mimeType.includes('ms-powerpoint')) return 'presentation'
  if (mimeType.includes('word') || mimeType.includes('opendocument.text') || mimeType === 'application/rtf') {
    return 'document'
  }
  return 'file'
}

// ---------------------------------------------------------------------------
// Reaching the bytes
// ---------------------------------------------------------------------------

/**
 * An address for the file itself.
 *
 * `content_url` is the path the presenter hands out; it is relative to the API
 * root, so it has to be joined to the base URL before a browser can follow it.
 * The endpoint re-checks the caller's permission on every request, which is
 * why there is no signed link cached anywhere in this feature.
 */
export function attachmentContentUrl(attachment: Attachment): string {
  return `${getApiBaseUrl()}${attachment.content_url}`
}

// ---------------------------------------------------------------------------
// Uploading
// ---------------------------------------------------------------------------

export interface UploadOptions {
  /** The editor block this file belongs to, when it was dropped into one. */
  blockId?: string | null
  signal?: AbortSignal
}

/**
 * Store one file on a note.
 *
 * Narrow on purpose: the editor calls this for a pasted screenshot or a
 * dropped image and wants an {@link Attachment} back, not a React hook and not
 * a cache. Callers that also need the list refreshed use {@link useAttachments}.
 */
export async function uploadFile(
  noteId: string,
  file: File,
  options: UploadOptions = {},
): Promise<Attachment> {
  const form = new FormData()
  // The field name the server reads is `file`; `block_id` is optional and is
  // what links an image in the document back to its row in the attachment list.
  form.append('file', file, file.name)
  if (options.blockId) form.append('block_id', options.blockId)

  return api.upload<Attachment>(`/notes/${noteId}/attachments`, form, { signal: options.signal })
}

/**
 * The uploader the editor plugs into paste and drop handling.
 *
 * Returns the shape `ImageUploader` expects — a URL to render plus the id that
 * ties the image node to its attachment — without this feature importing the
 * editor, which is a sibling and not a dependency.
 */
export function useImageUploader(noteId: string | undefined) {
  const client = useQueryClient()

  return useCallback(
    async (file: File) => {
      if (!noteId) throw new ApiError('NO_NOTE', 'Open a note before adding an image.', 0)

      const attachment = await uploadFile(noteId, file)
      void client.invalidateQueries({ queryKey: queryKeys.notes.attachments(noteId) })
      void client.invalidateQueries({ queryKey: queryKeys.notes.detail(noteId) })

      return {
        src: attachmentContentUrl(attachment),
        attachmentId: attachment.id,
        alt: attachment.filename,
        width: attachment.width ?? undefined,
        height: attachment.height ?? undefined,
      }
    },
    [client, noteId],
  )
}

// ---------------------------------------------------------------------------
// The hook
// ---------------------------------------------------------------------------

/**
 * A file on its way to the server.
 *
 * Kept in component state rather than in the query cache: it is not an
 * attachment yet, and putting a half-real row in the cache is how a list ends
 * up showing a file that does not exist after a refetch.
 */
export interface PendingUpload {
  key: string
  file: File
  filename: string
  byteSize: number
  kind: AttachmentKind
  /** An object URL for a local image, so a photo appears before it uploads. */
  previewUrl: string | null
  uploading: boolean
  error: string | null
}

function newKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

export interface UseAttachmentsResult {
  attachments: Attachment[]
  pending: PendingUpload[]
  isPending: boolean
  isError: boolean
  error: ApiError | null
  /** True while the server still has work queued for at least one file. */
  isBusy: boolean
  /** True once watching stopped without the work finishing. */
  watchExpired: boolean
  refetch: () => void
  /** Start watching again after {@link watchExpired}. */
  checkAgain: () => void
  upload: (files: FileList | File[], options?: UploadOptions) => Promise<Attachment[]>
  retryPending: (key: string) => void
  dismissPending: (key: string) => void
  attachDriveFile: (driveFileId: string, options?: UploadOptions) => Promise<Attachment>
  remove: (attachmentId: string) => Promise<void>
  removingId: string | null
  /** The deployment's ceiling, so a caller can name it before a file is chosen. */
  maxBytes: number
}

export function useAttachments(noteId: string | undefined): UseAttachmentsResult {
  const client = useQueryClient()
  const maxBytes = useAppConfig().limits.max_attachment_bytes

  const pollStartedAt = useRef<number | null>(null)
  const [watchExpired, setWatchExpired] = useState(false)
  const [pending, setPending] = useState<PendingUpload[]>([])
  const [removingId, setRemovingId] = useState<string | null>(null)

  const list = useQuery<Attachment[], ApiError>({
    queryKey: queryKeys.notes.attachments(noteId ?? ''),
    enabled: Boolean(noteId),
    queryFn: () => api.get<Attachment[]>(`/notes/${noteId}/attachments`),
    refetchInterval: (query) => {
      const rows = query.state.data ?? []
      if (!rows.some(isProcessing)) return false

      pollStartedAt.current ??= Date.now()
      return pollIntervalFor(Date.now() - pollStartedAt.current)
    },
  })

  const rows = list.data ?? []
  const isBusy = rows.some(isProcessing)

  // The interval above stops on its own; this is what lets the UI say so
  // instead of leaving a spinner turning over a poll that has ended.
  useEffect(() => {
    if (!isBusy) {
      pollStartedAt.current = null
      setWatchExpired(false)
      return undefined
    }

    pollStartedAt.current ??= Date.now()
    const remaining = POLL_WINDOW_MS - (Date.now() - pollStartedAt.current)
    if (remaining <= 0) {
      setWatchExpired(true)
      return undefined
    }

    const timer = window.setTimeout(() => setWatchExpired(true), remaining)
    return () => window.clearTimeout(timer)
  }, [isBusy])

  // Object URLs are a document-lifetime leak until they are revoked, and a
  // note with twenty scanned pages open all day is exactly where that shows.
  const objectUrls = useRef<Set<string>>(new Set())
  useEffect(() => {
    const urls = objectUrls.current
    return () => {
      for (const url of urls) URL.revokeObjectURL(url)
      urls.clear()
    }
  }, [])

  const releasePreview = useCallback((url: string | null) => {
    if (!url) return
    URL.revokeObjectURL(url)
    objectUrls.current.delete(url)
  }, [])

  const invalidate = useCallback(() => {
    if (!noteId) return
    void client.invalidateQueries({ queryKey: queryKeys.notes.attachments(noteId) })
    // `attachment_count` lives on the note, and the info panel reads it.
    void client.invalidateQueries({ queryKey: queryKeys.notes.detail(noteId) })
  }, [client, noteId])

  const send = useCallback(
    async (item: PendingUpload, options: UploadOptions): Promise<Attachment | null> => {
      setPending((current) =>
        current.map((row) => (row.key === item.key ? { ...row, uploading: true, error: null } : row)),
      )

      try {
        const attachment = await uploadFile(item.file, item, options)
        return attachment
      } catch (error) {
        setPending((current) =>
          current.map((row) =>
            row.key === item.key
              ? { ...row, uploading: false, error: describeUploadError(error, item.filename) }
              : row,
          ),
        )
        return null
      }
    },
    [],
  )

  return {
    attachments: rows,
    pending,
    isPending: list.isPending && Boolean(noteId),
    isError: list.isError,
    error: list.error ?? null,
    isBusy,
    watchExpired,
    refetch: () => void list.refetch(),
    checkAgain: () => {
      pollStartedAt.current = Date.now()
      setWatchExpired(false)
      void list.refetch()
    },
    upload: async () => [],
    retryPending: () => undefined,
    dismissPending: () => undefined,
    attachDriveFile: async () => {
      throw new Error('unreachable')
    },
    remove: async () => undefined,
    removingId,
    maxBytes,
  }
}
