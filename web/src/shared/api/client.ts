/**
 * The one place this app talks to its API.
 *
 * Components never call `fetch`. Everything goes through here so that four
 * things are true everywhere rather than in most places:
 *
 *   - a valid `ses_key` is attached, minted on demand when the last one expired;
 *   - a failure arrives as a typed {@link ApiError} with a stable `code`, so the
 *     UI branches on a code and not on a message;
 *   - being offline is a distinct, expected outcome — not a network exception
 *     that surfaces as "Failed to fetch";
 *   - a request that is safe to retry is retried, and one that is not never is.
 */

import { ensureSesKey } from '../../auth/portal'
import { getApiBaseUrl } from '../../config'
import { ERROR_CODES } from './types'
import type { ApiErrorBody, ApiMeta, ApiSuccess } from './types'

const DEFAULT_TIMEOUT_MS = 20_000
/** A save carries the user's writing; give it longer before giving up on it. */
const WRITE_TIMEOUT_MS = 30_000

export class ApiError extends Error {
  constructor(
    readonly code: string,
    message: string,
    readonly status: number,
    readonly details: Record<string, unknown> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** The caller is offline, or the request never reached the server. */
  get isOffline(): boolean {
    return this.code === ERROR_CODES.offline
  }

  /** The session is gone; the shell should send the user back to the portal. */
  get isUnauthenticated(): boolean {
    return this.status === 401
  }

  /** Someone else saved this note first. */
  get isConflict(): boolean {
    return this.code === ERROR_CODES.conflict
  }

  /** The capability is not enabled on this deployment. */
  get isFeatureDisabled(): boolean {
    return this.code === ERROR_CODES.featureDisabled
  }

  /** Field errors from a 422, keyed by field name. */
  get fieldErrors(): Record<string, string> {
    const fields = this.details.fields
    return fields && typeof fields === 'object' ? (fields as Record<string, string>) : {}
  }
}

export interface RequestOptions {
  query?: Record<string, string | number | boolean | undefined | null>
  body?: unknown
  signal?: AbortSignal
  timeoutMs?: number
  /**
   * Skip the Authorization header. Only `/config` and `/health`, which answer
   * before there is a session to attach.
   */
  anonymous?: boolean
  /** Sent as-is instead of JSON. Used for multipart uploads. */
  formData?: FormData
}

export interface ApiResult<T> {
  data: T
  meta: ApiMeta
}

/** Methods a network failure may safely be retried on. */
const IDEMPOTENT = new Set(['GET', 'HEAD'])

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const base = getApiBaseUrl()
  const url = new URL(`${base}${path.startsWith('/') ? path : `/${path}`}`, window.location.origin)

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value === undefined || value === null || value === '') continue
    url.searchParams.set(key, String(value))
  }

  return url.toString()
}

function newRequestId(): string {
  // Correlates a user's report with a line in the server log. crypto.randomUUID
  // is unavailable on insecure origins, hence the fallback.
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return Math.random().toString(36).slice(2) + Date.now().toString(36)
}

async function parseBody(response: Response): Promise<unknown> {
  const text = await response.text()
  if (text === '') return null
  try {
    return JSON.parse(text) as unknown
  } catch {
    // A proxy error page or an HTML 500 — anything but the envelope. Reporting
    // it as a parse failure is more useful than "unexpected token <".
    throw new ApiError(
      'BAD_RESPONSE',
      'The server sent a response this app could not read.',
      response.status,
    )
  }
}

async function performRequest<T>(
  method: string,
  path: string,
  options: RequestOptions,
  attempt: number,
): Promise<ApiResult<T>> {
  const controller = new AbortController()
  const timeoutMs =
    options.timeoutMs ?? (IDEMPOTENT.has(method) ? DEFAULT_TIMEOUT_MS : WRITE_TIMEOUT_MS)
  const timer = window.setTimeout(() => controller.abort(), timeoutMs)

  // The caller's own signal (a cancelled search) must also abort the request.
  const onAbort = () => controller.abort()
  options.signal?.addEventListener('abort', onAbort)

  const headers: Record<string, string> = { 'X-Request-Id': newRequestId() }

  if (!options.anonymous) {
    // Minted lazily and shared across concurrent callers by ensureSesKey().
    headers.Authorization = `Bearer ${await ensureSesKey()}`
  }

  let payload: BodyInit | undefined
  if (options.formData) {
    // Content-Type is set by the browser, boundary included. Setting it here
    // would produce a boundary-less header and an unparseable upload.
    payload = options.formData
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(options.body)
  }

  let response: Response
  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      headers,
      body: payload,
      signal: controller.signal,
      credentials: 'omit',
    })
  } catch (error) {
    if (options.signal?.aborted) throw error

    const timedOut = (error as Error)?.name === 'AbortError'

    // One retry, and only for a request that repeating cannot duplicate.
    if (!timedOut && IDEMPOTENT.has(method) && attempt === 0) {
      await new Promise((resolve) => window.setTimeout(resolve, 400))
      return performRequest<T>(method, path, options, attempt + 1)
    }

    throw new ApiError(
      ERROR_CODES.offline,
      timedOut
        ? 'That took too long. Check your connection and try again.'
        : 'You appear to be offline. Your changes are saved on this device.',
      0,
    )
  } finally {
    window.clearTimeout(timer)
    options.signal?.removeEventListener('abort', onAbort)
  }

  if (response.status === 204) {
    return { data: undefined as T, meta: {} }
  }

  const body = await parseBody(response)

  if (!response.ok) {
    const error = (body as ApiErrorBody | null)?.error
    throw new ApiError(
      error?.code ?? `HTTP_${response.status}`,
      error?.message ?? 'Something went wrong. Please try again.',
      response.status,
      error?.details ?? {},
    )
  }

  const envelope = body as ApiSuccess<T> | null
  if (!envelope || envelope.success !== true) {
    throw new ApiError('BAD_RESPONSE', 'The server sent an unexpected response.', response.status)
  }

  return { data: envelope.data, meta: envelope.meta ?? {} }
}

async function request<T>(method: string, path: string, options: RequestOptions = {}): Promise<ApiResult<T>> {
  // navigator.onLine is unreliable as a positive signal but trustworthy as a
  // negative one: when it says offline, there is no point burning a timeout.
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    throw new ApiError(
      ERROR_CODES.offline,
      'You are offline. Your changes are saved on this device and will sync when you reconnect.',
      0,
    )
  }

  return performRequest<T>(method, path, options, 0)
}

export const api = {
  /** The full envelope, for endpoints whose `meta` carries a cursor. */
  request,

  async get<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return (await request<T>('GET', path, options)).data
  },
  async getWithMeta<T>(path: string, options: RequestOptions = {}): Promise<ApiResult<T>> {
    return request<T>('GET', path, options)
  },
  async post<T>(path: string, body?: unknown, options: RequestOptions = {}): Promise<T> {
    return (await request<T>('POST', path, { ...options, body })).data
  },
  async patch<T>(path: string, body?: unknown, options: RequestOptions = {}): Promise<T> {
    return (await request<T>('PATCH', path, { ...options, body })).data
  },
  async delete<T = void>(path: string, options: RequestOptions = {}): Promise<T> {
    return (await request<T>('DELETE', path, options)).data
  },
  async upload<T>(path: string, formData: FormData, options: RequestOptions = {}): Promise<T> {
    return (await request<T>('POST', path, { ...options, formData })).data
  },
}

/**
 * The deployment's feature flags.
 *
 * Fetched once and cached for the session: the UI hides what this deployment
 * cannot do, and a control that is hidden must not flicker into view because a
 * second fetch was in flight.
 */
let configPromise: Promise<import('./types').AppConfig> | null = null

export function fetchAppConfig(): Promise<import('./types').AppConfig> {
  configPromise ??= api
    .get<import('./types').AppConfig>('/config', { anonymous: true })
    .catch((error: unknown) => {
      configPromise = null
      throw error
    })

  return configPromise
}
