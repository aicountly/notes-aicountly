/**
 * The API client.
 *
 * The properties here are the ones that decide whether a user loses work or
 * sees a lie: a write is never retried (a retried POST /notes is a second
 * note), being offline is a distinct outcome rather than "Failed to fetch",
 * and a non-envelope response is reported as such instead of being unwrapped
 * into `undefined`.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../auth/portal', () => ({
  ensureSesKey: vi.fn(async () => 'test-ses-key'),
}))

vi.mock('../../config', () => ({
  getApiBaseUrl: () => 'https://notes.test/api',
  APP_NAME: 'Notes',
  APP_ENV: 'test',
}))

const { ApiError, api } = await import('./client')

const fetchMock = vi.fn()

function envelope(data: unknown, meta?: unknown): Response {
  return new Response(JSON.stringify(meta ? { success: true, data, meta } : { success: true, data }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

function failure(status: number, code: string, message = 'nope', details?: unknown): Response {
  return new Response(JSON.stringify({ success: false, error: { code, message, details } }), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock)
  fetchMock.mockReset()
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, value: true })
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('requests', () => {
  it('unwraps the envelope and attaches the session key', async () => {
    fetchMock.mockResolvedValue(envelope({ id: 'n1' }))

    const note = await api.get<{ id: string }>('/notes/n1')

    expect(note).toEqual({ id: 'n1' })
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('https://notes.test/api/notes/n1')
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer test-ses-key')
  })

  it('returns meta alongside data when asked', async () => {
    fetchMock.mockResolvedValue(envelope([{ id: 'n1' }], { has_more: true, next_cursor: 'abc' }))

    const { data, meta } = await api.getWithMeta<Array<{ id: string }>>('/notes')

    expect(data).toHaveLength(1)
    expect(meta.next_cursor).toBe('abc')
  })

  it('drops empty query parameters instead of sending them blank', async () => {
    fetchMock.mockResolvedValue(envelope([]))

    await api.get('/notes', { query: { q: 'gst', notebook_id: undefined, tags: '', limit: 20 } })

    const url = new URL(fetchMock.mock.calls[0][0] as string)
    expect(url.searchParams.get('q')).toBe('gst')
    expect(url.searchParams.has('notebook_id')).toBe(false)
    expect(url.searchParams.has('tags')).toBe(false)
    expect(url.searchParams.get('limit')).toBe('20')
  })

  it('sends a request id so a report can be traced to a log line', async () => {
    fetchMock.mockResolvedValue(envelope(null))

    await api.get('/notes')

    const headers = fetchMock.mock.calls[0][1].headers as Record<string, string>
    expect(headers['X-Request-Id']).toBeTruthy()
  })

  it('does not set Content-Type on an upload, so the boundary survives', async () => {
    fetchMock.mockResolvedValue(envelope({ id: 'a1' }))
    const form = new FormData()
    form.append('file', new Blob(['x']), 'note.txt')

    await api.upload('/notes/n1/attachments', form)

    const headers = fetchMock.mock.calls[0][1].headers as Record<string, string>
    // Setting it by hand produces a boundary-less header and an unparseable
    // upload; the browser must fill it in.
    expect(headers['Content-Type']).toBeUndefined()
  })

  it('does not attach a session key to an anonymous request', async () => {
    fetchMock.mockResolvedValue(envelope({ features: {} }))

    await api.get('/config', { anonymous: true })

    const headers = fetchMock.mock.calls[0][1].headers as Record<string, string>
    expect(headers.Authorization).toBeUndefined()
  })
})

describe('errors', () => {
  it('turns a failure body into a typed error with a stable code', async () => {
    fetchMock.mockResolvedValue(failure(403, 'NOTE_ACCESS_DENIED', 'You have view-only access.'))

    await expect(api.get('/notes/n1')).rejects.toMatchObject({
      code: 'NOTE_ACCESS_DENIED',
      status: 403,
      message: 'You have view-only access.',
    })
  })

  it('exposes field errors from a validation failure', async () => {
    fetchMock.mockResolvedValue(
      failure(422, 'VALIDATION_FAILED', 'Some fields need attention.', { fields: { name: 'Required' } }),
    )

    try {
      await api.post('/notebooks', {})
      expect.unreachable('should have thrown')
    } catch (error) {
      expect(error).toBeInstanceOf(ApiError)
      expect((error as InstanceType<typeof ApiError>).fieldErrors).toEqual({ name: 'Required' })
    }
  })

  it('flags a conflict so the caller can offer a recovery path', async () => {
    fetchMock.mockResolvedValue(failure(409, 'VERSION_CONFLICT', 'changed elsewhere'))

    try {
      await api.patch('/notes/n1', {})
      expect.unreachable('should have thrown')
    } catch (error) {
      expect((error as InstanceType<typeof ApiError>).isConflict).toBe(true)
    }
  })

  it('flags a disabled feature rather than reporting a generic failure', async () => {
    fetchMock.mockResolvedValue(failure(503, 'FEATURE_DISABLED', 'not enabled here'))

    try {
      await api.post('/pulse/notes/ask', {})
      expect.unreachable('should have thrown')
    } catch (error) {
      expect((error as InstanceType<typeof ApiError>).isFeatureDisabled).toBe(true)
    }
  })

  it('reports a non-envelope response instead of unwrapping it to undefined', async () => {
    // A proxy error page, or an HTML 500 from the host.
    fetchMock.mockResolvedValue(new Response('<html>502 Bad Gateway</html>', { status: 502 }))

    await expect(api.get('/notes')).rejects.toMatchObject({ code: 'BAD_RESPONSE' })
  })

  it('treats a 204 as an empty success, not as a broken envelope', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    await expect(api.delete('/notes/n1')).resolves.toBeUndefined()
  })
})

describe('offline and retries', () => {
  it('reports being offline without burning a timeout', async () => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, value: false })

    try {
      await api.get('/notes')
      expect.unreachable('should have thrown')
    } catch (error) {
      expect((error as InstanceType<typeof ApiError>).isOffline).toBe(true)
    }
    // navigator.onLine is unreliable as a positive signal but trustworthy as a
    // negative one, so there is no point asking the network.
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('turns a transport failure into an offline error, not "Failed to fetch"', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    try {
      await api.patch('/notes/n1', { title: 'x' })
      expect.unreachable('should have thrown')
    } catch (error) {
      expect((error as InstanceType<typeof ApiError>).isOffline).toBe(true)
      expect((error as Error).message).toMatch(/offline/i)
    }
  })

  it('retries a GET once when the network drops', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    fetchMock.mockResolvedValueOnce(envelope({ id: 'n1' }))

    await expect(api.get('/notes/n1')).resolves.toEqual({ id: 'n1' })
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('never retries a write', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(api.post('/notes', { title: 'x' })).rejects.toBeInstanceOf(ApiError)
    // A retried POST /notes is a second note. The offline queue is what makes a
    // failed write survive, not a retry loop.
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('does not retry a 4xx', async () => {
    fetchMock.mockResolvedValue(failure(404, 'NOT_FOUND'))

    await expect(api.get('/notes/missing')).rejects.toMatchObject({ status: 404 })
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })
})
