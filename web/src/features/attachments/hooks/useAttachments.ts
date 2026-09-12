/**
 * Attachments: listing them, adding them, and watching them get processed.
 *
 * Three things here are not obvious from the endpoint list:
 *
 *   - **The server is the authority on what may be attached.** Everything in
 *     {@link describeFileRejection} is a courtesy so the user is told "that is
 *     28 MB and the limit is 25" before they wait for the upload rather than
 *     after. It is not a control: the bytes are inspected server-side and a
 *     file that lies about its type is refused there. Never soften a server
 *     rejection because this passed.
 *   - **Processing is asynchronous and finite.** OCR and transcription are
 *     queued, so a fresh attachment arrives `queued` and turns `completed`
 *     minutes later. This polls with a backoff and then *stops* — a tab left
 *     open on a note must not hold a request every two seconds forever. When
 *     the window closes the UI says so and offers to look again.
 *   - **Uploading needs a network.** There is no `attachment.upload` operation
 *     in the offline queue, so an upload attempted offline fails and says so
 *     rather than pretending the file is safe on the device.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'

import { useAppConfig } from '../../../app/AppConfigProvider'
import { ensureSesKey } from '../../../auth/portal'
import { ApiError, api } from '../../../shared/api/client'
import { getApiBaseUrl } from '../../../config'
import { queryKeys } from '../../../shared/query/queryClient'
import { ERROR_CODES } from '../../../shared/api/types'
import type { Attachment, AttachmentKind } from '../../../shared/api/types'

// ---------------------------------------------------------------------------
// Polling
// ---------------------------------------------------------------------------

/** Watching stops here. Three minutes is longer than any job this UI waits on. */
const POLL_WINDOW_MS = 3 * 60_000

/**
 * 2s, 4s, 8s, then every 15s.
 *
 * Expressed as elapsed-time bands rather than an attempt counter so the
 * schedule is a pure function of when watching started: a re-render, a remount
 * or a second component watching the same note all land on the same step
 * instead of restarting the sequence.
 */
export function pollIntervalFor(elapsedMs: number): number | false {
  if (elapsedMs >= POLL_WINDOW_MS) return false
  if (elapsedMs < 2_000) return 2_000
  if (elapsedMs < 6_000) return 4_000
  if (elapsedMs < 14_000) return 8_000
  return 15_000
}

/** True while the server still has work queued for this file. */
export function isProcessing(attachment: Attachment): boolean {
  return attachment.processing_status === 'queued' || attachment.processing_status === 'processing'
}

// ---------------------------------------------------------------------------
// Client-side validation — a courtesy, never a control
// ---------------------------------------------------------------------------

/**
 * Extensions the server refuses outright, mirrored here only to fail fast.
 *
 * Deliberately the shorter list: these are the ones a person plausibly tries
 * to attach — a saved web page, an SVG logo, a script — and being told at once
 * is kinder than a round trip. The server's list is longer and is the one that
 * decides.
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
 * "Nothing obvious is wrong" is the strongest claim this can make: the bytes
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

/** The server's sentence where there is one; offline is its own answer. */
export function describeUploadError(error: unknown, filename: string): string {
  if (error instanceof ApiError) {
    if (error.isOffline) {
      return `${filename} needs a connection to upload. It has not been attached.`
    }
    const field = error.fieldErrors.file ?? error.fieldErrors.content_type
    return field ?? error.message
  }
  return `${filename} could not be attached. Please try again.`
}

// ---------------------------------------------------------------------------
// Reaching the bytes
// ---------------------------------------------------------------------------

/**
 * An address for the file itself.
 *
 * `content_url` is the path the presenter hands out and it is relative to the
 * API root, so it has to be joined to the base URL before a browser can follow
 * it. The endpoint re-checks the caller's permission on every request, which
 * is why nothing in this feature caches a link to a file.
 */
export function attachmentContentUrl(attachment: Attachment): string {
  return `${getApiBaseUrl()}${attachment.content_url}`
}

/**
 * The bytes, fetched with the session attached.
 *
 * The content endpoint is behind the same Bearer ses_key as the rest of the
 * API — `index.php` reads the credential from the Authorization header and
 * from nowhere else. A browser loading an `<img src>`, an `<object data>` or
 * following a link sends no such header, so pointing any of those straight at
 * {@link attachmentContentUrl} produces a 401 envelope: a broken image, and a
 * Download that saves an error message. Everything that needs the file itself
 * goes through here and hands the DOM an object URL instead.
 */
async function fetchBytes(url: string, signal?: AbortSignal): Promise<Blob> {
  // Same reasoning as the API client: navigator.onLine is worthless as a
  // positive signal and reliable as a negative one.
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    throw new ApiError(
      ERROR_CODES.offline,
      'You are offline, so this file cannot be opened right now.',
      0,
    )
  }

  let response: Response
  try {
    response = await fetch(url, {
      headers: { Authorization: `Bearer ${await ensureSesKey()}` },
      credentials: 'omit',
      signal,
    })
  } catch (error) {
    if (signal?.aborted) throw error

    // A fetch that throws is usually a dropped connection, but it is also what
    // a store redirect without CORS headers looks like — so `offline` is only
    // claimed when the browser agrees the connection is gone.
    const offline = typeof navigator !== 'undefined' && navigator.onLine === false
    throw new ApiError(
      offline ? ERROR_CODES.offline : 'FILE_UNREACHABLE',
      offline
        ? 'You are offline, so this file cannot be opened right now.'
        : 'That file could not be reached. Check your connection and try again.',
      0,
    )
  }

  if (!response.ok) {
    throw new ApiError(
      `HTTP_${response.status}`,
      response.status === 404
        ? 'That file is no longer stored on the server.'
        : 'That file could not be opened.',
      response.status,
    )
  }

  return response.blob()
}

export function fetchAttachmentBlob(attachment: Attachment, signal?: AbortSignal): Promise<Blob> {
  return fetchBytes(attachmentContentUrl(attachment), signal)
}

export interface AttachmentObjectUrl {
  url: string | null
  loading: boolean
  error: ApiError | null
}

/**
 * An object URL for one attachment, live for as long as it is asked for.
 *
 * `active` is what keeps a list of twenty files from fetching twenty of them:
 * the caller passes `false` until the preview is actually being shown, and the
 * URL is revoked the moment it stops being needed.
 */
export function useAttachmentObjectUrl(
  attachment: Attachment,
  active: boolean,
): AttachmentObjectUrl {
  const [state, setState] = useState<AttachmentObjectUrl>({
    url: null,
    loading: false,
    error: null,
  })

  // A string, not the attachment object: a poll that returns a new row with a
  // changed `processing_status` must not re-download the file.
  const contentUrl = attachmentContentUrl(attachment)

  useEffect(() => {
    if (!active) {
      setState({ url: null, loading: false, error: null })
      return undefined
    }

    const controller = new AbortController()
    let objectUrl: string | null = null
    setState({ url: null, loading: true, error: null })

    void fetchBytes(contentUrl, controller.signal)
      .then((blob) => {
        if (controller.signal.aborted) return
        objectUrl = URL.createObjectURL(blob)
        setState({ url: objectUrl, loading: false, error: null })
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return
        setState({
          url: null,
          loading: false,
          error:
            error instanceof ApiError
              ? error
              : new ApiError('BAD_RESPONSE', 'That file could not be opened.', 0),
        })
      })

    return () => {
      controller.abort()
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [active, contentUrl])

  return state
}

/**
 * Save the file to the user's device.
 *
 * A plain `<a download>` cannot do this — see {@link fetchBytes} — so the bytes
 * are fetched first and offered as a blob, which is also what makes `download`
 * honour the filename regardless of where the store redirected to.
 */
export async function downloadAttachment(attachment: Attachment): Promise<void> {
  const blob = await fetchAttachmentBlob(attachment)
  const url = URL.createObjectURL(blob)

  const link = document.createElement('a')
  link.href = url
  link.download = attachment.filename
  link.rel = 'noopener'
  document.body.append(link)
  link.click()
  link.remove()

  // The click starts a save that outlives this function; revoking straight
  // away cancels it in some browsers.
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
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
 * dropped image and wants an {@link Attachment} back — not a React hook, and
 * not a cache. Callers that also need the list refreshed use
 * {@link useAttachments}.
 */
export async function uploadFile(
  noteId: string,
  file: File,
  options: UploadOptions = {},
): Promise<Attachment> {
  const form = new FormData()
  // `file` is the field the server reads; `block_id` is what ties an image in
  // the document back to its row in the attachment list.
  form.append('file', file, file.name)
  if (options.blockId) form.append('block_id', options.blockId)

  return api.upload<Attachment>(`/notes/${noteId}/attachments`, form, { signal: options.signal })
}

/**
 * The uploader the editor plugs into paste and drop handling.
 *
 * Returns the shape the editor's `ImageUploader` expects — a URL to render
 * plus the id that ties the image node to its attachment — without this
 * feature importing the editor, which is a sibling rather than a dependency.
 *
 * `src` is deliberately the canonical {@link attachmentContentUrl} and not an
 * object URL: the editor writes this string into the saved document, and a
 * `blob:` address is dead the moment the tab closes. That URL is only reachable
 * with the session's Bearer token, so an image node pointing at it renders only
 * once the editor resolves it through {@link fetchAttachmentBlob} (or the
 * server starts issuing a signed link on the attachment). `attachmentId` is
 * what makes either possible.
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
 * Held in component state rather than in the query cache: it is not an
 * attachment yet, and a half-real row in the cache is how a list ends up
 * showing a file that vanishes on the next refetch.
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
  /**
   * False for a file this app refused before it was sent — an empty file, one
   * over the deployment's ceiling, an extension the server will not take.
   * Sending it again cannot change the answer, so no retry is offered.
   */
  retryable: boolean
}

function newKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

/** A file the tray is still holding, and the sentence explaining why. */
export interface UploadFailure {
  key: string
  filename: string
  message: string
}

export interface UploadOutcome {
  stored: Attachment[]
  failed: UploadFailure[]
}

export interface UseAttachmentsResult {
  attachments: Attachment[]
  pending: PendingUpload[]
  isPending: boolean
  isError: boolean
  error: ApiError | null
  /** True while the server still has work queued for at least one file. */
  isBusy: boolean
  /** True once watching stopped without that work finishing. */
  watchExpired: boolean
  refetch: () => void
  /** Start watching again after {@link watchExpired}. */
  checkAgain: () => void
  upload: (files: FileList | File[], options?: UploadOptions) => Promise<UploadOutcome>
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
  /**
   * Bumped by {@link checkAgain}. Without it the expiry timer below is keyed
   * only on `isBusy` — which does not change when the user asks to look again —
   * so the second watch would run out in silence and the notice offering the
   * button would never come back.
   */
  const [watchGeneration, setWatchGeneration] = useState(0)
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

  // The interval above stops on its own; this is what lets the UI say so,
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
  }, [isBusy, watchGeneration])

  // An object URL lives as long as the document unless it is revoked, and a
  // note with twenty scanned pages open all day is where that starts to show.
  const objectUrls = useRef<Set<string>>(new Set())
  useEffect(() => {
    const urls = objectUrls.current
    return () => {
      for (const url of urls) URL.revokeObjectURL(url)
      urls.clear()
    }
  }, [])

  const releasePreview = useCallback((url: string | null) => {
    if (!url || !objectUrls.current.has(url)) return
    URL.revokeObjectURL(url)
    objectUrls.current.delete(url)
  }, [])

  const invalidate = useCallback(() => {
    if (!noteId) return
    void client.invalidateQueries({ queryKey: queryKeys.notes.attachments(noteId) })
    // `attachment_count` lives on the note, and the info panel reads it.
    void client.invalidateQueries({ queryKey: queryKeys.notes.detail(noteId) })
  }, [client, noteId])

  /** Upload one queued row, leaving it in place with its message on failure. */
  const send = useCallback(
    async (
      item: PendingUpload,
      options: UploadOptions,
    ): Promise<{ ok: true; attachment: Attachment } | { ok: false; message: string }> => {
      if (!noteId) return { ok: false, message: 'Open a note before attaching a file.' }

      setPending((current) =>
        current.map((row) => (row.key === item.key ? { ...row, uploading: true, error: null } : row)),
      )

      try {
        const attachment = await uploadFile(noteId, item.file, options)
        releasePreview(item.previewUrl)
        setPending((current) => current.filter((row) => row.key !== item.key))
        invalidate()
        return { ok: true, attachment }
      } catch (error) {
        const message = describeUploadError(error, item.filename)
        setPending((current) =>
          current.map((row) =>
            // The file did reach the network, so asking again is a real
            // option — unlike a rejection this app made on its own.
            row.key === item.key ? { ...row, uploading: false, error: message, retryable: true } : row,
          ),
        )
        return { ok: false, message }
      }
    },
    [invalidate, noteId, releasePreview],
  )

  const upload = useCallback(
    async (files: FileList | File[], options: UploadOptions = {}): Promise<UploadOutcome> => {
      if (!noteId) return { stored: [], failed: [] }

      const queued: PendingUpload[] = Array.from(files).map((file) => {
        const rejection = describeFileRejection(file, maxBytes)
        // Only an image gets a local preview: an object URL for a 40 MB video
        // buys nothing on a row that shows a filename and a size.
        const previewUrl =
          rejection === null && file.type.startsWith('image/') ? URL.createObjectURL(file) : null
        if (previewUrl) objectUrls.current.add(previewUrl)

        return {
          key: newKey(),
          file,
          filename: file.name,
          byteSize: file.size,
          kind: kindFromMimeType(file.type),
          previewUrl,
          uploading: false,
          error: rejection,
          retryable: rejection === null,
        }
      })

      setPending((current) => [...current, ...queued])

      // One at a time: uploads share a rate-limit bucket on the server, and a
      // burst of ten is how a drop of a folder turns into a 429.
      const stored: Attachment[] = []
      const failed: UploadFailure[] = []

      for (const item of queued) {
        if (item.error !== null) {
          failed.push({ key: item.key, filename: item.filename, message: item.error })
          continue
        }

        const result = await send(item, options)
        if (result.ok) stored.push(result.attachment)
        else failed.push({ key: item.key, filename: item.filename, message: result.message })
      }

      return { stored, failed }
    },
    [maxBytes, noteId, send],
  )

  const retryPending = useCallback(
    (key: string) => {
      const item = pending.find((row) => row.key === key)
      if (!item || item.uploading || !item.retryable) return
      void send(item, {})
    },
    [pending, send],
  )

  const dismissPending = useCallback(
    (key: string) => {
      setPending((current) => {
        const item = current.find((row) => row.key === key)
        releasePreview(item?.previewUrl ?? null)
        return current.filter((row) => row.key !== key)
      })
    },
    [releasePreview],
  )

  const attachDriveFile = useCallback(
    async (driveFileId: string, options: UploadOptions = {}): Promise<Attachment> => {
      if (!noteId) throw new ApiError('NO_NOTE', 'Open a note first.', 0)

      const attachment = await api.post<Attachment>(`/notes/${noteId}/attachments/link-drive`, {
        drive_file_id: driveFileId,
        block_id: options.blockId ?? null,
      })
      invalidate()
      return attachment
    },
    [invalidate, noteId],
  )

  const remove = useCallback(
    async (attachmentId: string) => {
      if (!noteId) return
      setRemovingId(attachmentId)
      try {
        await api.delete(`/notes/${noteId}/attachments/${attachmentId}`)
        invalidate()
      } finally {
        setRemovingId(null)
      }
    },
    [invalidate, noteId],
  )

  // `refetch` is stable across renders, so depending on it rather than on the
  // whole query result keeps these callbacks stable too — a row does not
  // re-render every poll just because its "Check again" handler was recreated.
  const { refetch: refetchQuery } = list

  const checkAgain = useCallback(() => {
    pollStartedAt.current = Date.now()
    setWatchExpired(false)
    setWatchGeneration((generation) => generation + 1)
    void refetchQuery()
  }, [refetchQuery])

  const refetch = useCallback(() => {
    void refetchQuery()
  }, [refetchQuery])

  return {
    attachments: rows,
    pending,
    isPending: list.isPending && Boolean(noteId),
    isError: list.isError,
    error: list.error ?? null,
    isBusy,
    watchExpired,
    refetch,
    checkAgain,
    upload,
    retryPending,
    dismissPending,
    attachDriveFile,
    remove,
    removingId,
    maxBytes,
  }
}

/**
 * The bytes at an attachment URL, for a caller that has the URL and not the row.
 *
 * The editor is the caller that needs this: an image node carries the `src`
 * that was stored in the document, not the {@link Attachment} it came from.
 * Same fetch, same session, same reasoning as {@link fetchAttachmentBlob}.
 */
export function fetchAttachmentBytes(url: string, signal?: AbortSignal): Promise<Blob> {
  return fetchBytes(url, signal)
}

/**
 * A loader the editor can hand to its image nodes.
 *
 * Object URLs are revoked when the hook unmounts rather than per image: an
 * editor holds its nodes for as long as the note is open, and revoking one
 * while its `<img>` is still on screen blanks the picture.
 */
export function useImageSrcLoader(): (src: string, signal: AbortSignal) => Promise<string> {
  const urls = useRef<string[]>([])

  useEffect(
    () => () => {
      for (const url of urls.current) URL.revokeObjectURL(url)
      urls.current = []
    },
    [],
  )

  return useCallback(async (src: string, signal: AbortSignal) => {
    const blob = await fetchAttachmentBytes(src, signal)
    const url = URL.createObjectURL(blob)
    urls.current.push(url)
    return url
  }, [])
}
