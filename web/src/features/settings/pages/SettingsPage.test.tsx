/**
 * What Settings promises.
 *
 * Three of these are the reason the page exists at all:
 *
 *   - **"Match my device" stays live.** Choosing it must leave the document
 *     unstamped so the CSS keeps following the system; a page that recorded
 *     whatever the system was at the time would be a snapshot pretending to be
 *     a preference.
 *   - **The capability list is honest.** Someone who cannot find Pulse comes
 *     here to learn whether it is missing or switched off, so the off case has
 *     to say "not enabled on this deployment" rather than promise it later.
 *   - **The clear-offline warning is true.** It is derived from the real queue
 *     count, because a warning that appears when nothing is queued is one
 *     people learn to click through — and then it is there when it matters.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import SettingsPage from './SettingsPage'
import { AppConfigProvider } from '../../../app/AppConfigProvider'
import { ThemeProvider } from '../../../shared/ui/ThemeProvider'
import type { AppConfig, FeatureFlags, Notebook } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

// The device's stores, stood in for so a test can say what is on this device.
// `clearOfflineData` itself is the real one — the point of the confirm test is
// that pressing the button reaches these.
const device = vi.hoisted(() => ({
  notes: [] as { id: string; document?: unknown }[],
  pending: 0,
  queueCleared: 0,
  notesCleared: 0,
  clearFails: false,
}))

vi.mock('../../../shared/offline/localNoteStore', () => ({
  localNoteStore: { all: async () => device.notes },
}))

vi.mock('../../../shared/offline/syncQueue', () => ({
  syncQueue: {
    count: async () => device.pending,
    clear: async () => {
      if (device.clearFails) throw new Error('QuotaExceededError')
      device.queueCleared += 1
      device.pending = 0
    },
  },
}))

vi.mock('../../../shared/offline/db', () => ({
  STORE_NOTES: 'notes',
  STORE_QUEUE: 'sync-queue',
  STORE_META: 'meta',
  idb: {
    clear: async (store: string) => {
      if (store === 'notes') {
        device.notesCleared += 1
        device.notes = []
      }
    },
  },
}))

vi.mock('../../../shared/offline/syncEngine', () => ({ refreshPendingCount: async () => device.pending }))

// The deployment's answer, swapped per test. Mocking the fetch rather than the
// provider keeps AppConfigProvider — and its "everything is off while loading"
// rule — in the test.
const deployment = vi.hoisted(() => ({ config: null as unknown }))

vi.mock('../../../shared/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../shared/api/client')>()
  return { ...actual, fetchAppConfig: async () => deployment.config }
})

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const ALL_OFF: FeatureFlags = {
  ai: false,
  semantic_search: false,
  ocr: false,
  transcription: false,
  realtime: false,
  canvas: false,
  private_notes: false,
  drive: false,
  calendar: false,
  contacts: false,
  connect: false,
}

function makeConfig(overrides: Partial<AppConfig> = {}): AppConfig {
  return {
    app: 'Notes',
    env: 'sandbox',
    features: { ...ALL_OFF },
    limits: { max_attachment_bytes: 52_428_800, trash_retention_days: 14 },
    ...overrides,
  }
}

function makeNotebook(id: string, name: string): Notebook {
  return {
    id,
    parent_id: null,
    name,
    description: null,
    icon: null,
    color: null,
    position: 0,
    depth: 0,
    is_archived: false,
    note_count: 0,
    role: 'owner',
    children: [],
  }
}

function envelope(data: unknown) {
  return { ok: true, status: 200, text: async () => JSON.stringify({ success: true, data }) } as unknown as Response
}

function failure(code: string, message: string, status = 500) {
  return {
    ok: false,
    status,
    text: async () => JSON.stringify({ success: false, error: { code, message } }),
  } as unknown as Response
}

const fetchMock = vi.fn()

/** A matchMedia whose answer can change, the way an OS setting changes. */
function stubSystemTheme(initial: 'light' | 'dark') {
  let matches = initial === 'dark'
  const listeners = new Set<(event: MediaQueryListEvent) => void>()

  vi.stubGlobal('matchMedia', (query: string) => ({
    get matches() {
      return matches
    },
    media: query,
    onchange: null,
    addEventListener: (_: string, listener: (event: MediaQueryListEvent) => void) => listeners.add(listener),
    removeEventListener: (_: string, listener: (event: MediaQueryListEvent) => void) => listeners.delete(listener),
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  }))

  return {
    set(next: 'light' | 'dark') {
      matches = next === 'dark'
      act(() => {
        for (const listener of listeners) listener({ matches } as MediaQueryListEvent)
      })
    },
  }
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })

  render(
    <QueryClientProvider client={client}>
      <AppConfigProvider>
        <ThemeProvider>
          <MemoryRouter>
            <SettingsPage />
          </MemoryRouter>
        </ThemeProvider>
      </AppConfigProvider>
    </QueryClientProvider>,
  )
}

/** The section a heading names, so an assertion cannot match the wrong one. */
function section(name: RegExp | string): HTMLElement {
  return screen.getByRole('region', { name })
}

beforeEach(() => {
  window.localStorage.clear()
  document.documentElement.removeAttribute('data-theme')

  device.notes = []
  device.pending = 0
  device.queueCleared = 0
  device.notesCleared = 0
  device.clearFails = false
  deployment.config = makeConfig()

  fetchMock.mockReset()
  fetchMock.mockImplementation(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url.includes('/notebooks')) return envelope([makeNotebook('nb-1', 'Clients')])
    throw new Error(`unexpected request: ${url}`)
  })
  vi.stubGlobal('fetch', fetchMock)
  stubSystemTheme('light')
})

// ---------------------------------------------------------------------------

describe('SettingsPage — appearance', () => {
  it('offers light, dark and following the device', () => {
    renderPage()

    const appearance = section(/appearance/i)
    expect(within(appearance).getByRole('radio', { name: /light/i })).toBeInTheDocument()
    expect(within(appearance).getByRole('radio', { name: /dark/i })).toBeInTheDocument()
    expect(within(appearance).getByRole('radio', { name: /match my device/i })).toBeChecked()
  })

  it('applies an explicit choice to the document', async () => {
    renderPage()

    await userEvent.click(screen.getByRole('radio', { name: /dark/i }))

    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
    expect(screen.getByText(/always dark, whatever this device is set to/i)).toBeInTheDocument()
  })

  it('keeps "match my device" following the device rather than freezing it', async () => {
    const system = stubSystemTheme('light')
    renderPage()

    await userEvent.click(screen.getByRole('radio', { name: /dark/i }))
    await userEvent.click(screen.getByRole('radio', { name: /match my device/i }))

    // Nothing stamped: the stylesheet falls through to prefers-color-scheme.
    expect(document.documentElement.hasAttribute('data-theme')).toBe(false)
    expect(screen.getByText(/following this device, which is currently light/i)).toBeInTheDocument()

    system.set('dark')
    expect(screen.getByText(/following this device, which is currently dark/i)).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /match my device/i })).toBeChecked()
  })
})

describe('SettingsPage — this deployment', () => {
  it('says what is off without promising it later', async () => {
    renderPage()

    const capabilities = section(/what this deployment can do/i)
    const pulse = await within(capabilities).findByText('Pulse')
    const row = pulse.closest('li') as HTMLElement

    expect(within(row).getByText('Off')).toBeInTheDocument()
    expect(within(row).getByText(/not enabled on this deployment/i)).toBeInTheDocument()
    expect(screen.queryByText(/coming soon/i)).not.toBeInTheDocument()
  })

  it('marks the capabilities the server has switched on', async () => {
    deployment.config = makeConfig({ features: { ...ALL_OFF, ai: true, drive: true } })
    renderPage()

    const capabilities = section(/what this deployment can do/i)
    const pulseRow = (await within(capabilities).findByText('Pulse')).closest('li') as HTMLElement

    await waitFor(() => expect(within(pulseRow).getByText('On')).toBeInTheDocument())
    expect(within(pulseRow).getByText(/answers cite the notes they came from/i)).toBeInTheDocument()

    const driveRow = (within(capabilities).getByText('Drive attachments').closest('li')) as HTMLElement
    expect(within(driveRow).getByText('On')).toBeInTheDocument()
  })

  it('states each capability in words as well as in colour', async () => {
    renderPage()

    const capabilities = section(/what this deployment can do/i)
    // Eleven flags, each with a readable On/Off — never a green dot on its own.
    await waitFor(() => expect(within(capabilities).getAllByRole('listitem')).toHaveLength(11))
    expect(within(capabilities).getAllByText('Off').length).toBeGreaterThan(0)
  })
})

describe('SettingsPage — limits and build', () => {
  it("reports the server's limits, in human units and read-only", async () => {
    renderPage()

    const about = section(/about this app/i)
    expect(await within(about).findByText('50 MB')).toBeInTheDocument()
    expect(within(about).getByText(/14 days/)).toBeInTheDocument()
    expect(within(about).getAllByText(/set on the server/i)).toHaveLength(2)

    // They are facts, not fields: nothing in here can be typed into or chosen.
    expect(within(about).queryByRole('textbox')).not.toBeInTheDocument()
    expect(within(about).queryByRole('combobox')).not.toBeInTheDocument()
  })

  it('names the environment and links to the health check', async () => {
    renderPage()

    const about = section(/about this app/i)
    expect(await within(about).findByText('sandbox')).toBeInTheDocument()

    const health = within(about).getByRole('link', { name: /health check/i })
    expect(health).toHaveAttribute('href', `${window.location.origin}/api/health`)
  })
})

describe('SettingsPage — offline data', () => {
  it('reports what this device is holding', async () => {
    device.notes = [{ id: 'a', document: { type: 'doc' } }, { id: 'b' }, { id: 'c' }]
    renderPage()

    const offline = section(/offline copy/i)
    expect(await within(offline).findByText(/3 cached, 1 openable offline/i)).toBeInTheDocument()
    expect(within(offline).getByText('None')).toBeInTheDocument()
  })

  it('warns that clearing discards unsent work, and then clears it', async () => {
    device.notes = [{ id: 'a', document: { type: 'doc' } }]
    device.pending = 2
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /clear offline data/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/2 changes on this device that have not reached the server/i)).toBeInTheDocument()

    await userEvent.click(within(dialog).getByRole('button', { name: /discard and clear/i }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(device.queueCleared).toBe(1)
    expect(device.notesCleared).toBe(1)

    // And the figures are re-read, so the page is not still describing a
    // device state that was just thrown away.
    const offline = section(/offline copy/i)
    await waitFor(() => expect(within(offline).getByText(/0 cached/i)).toBeInTheDocument())
  })

  it('does not claim there is unsent work when the queue is empty', async () => {
    device.notes = [{ id: 'a' }]
    device.pending = 0
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /clear offline data/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).queryByText(/have not reached the server/i)).not.toBeInTheDocument()
    expect(within(dialog).getByText(/your notes stay on the server/i)).toBeInTheDocument()
  })
})

describe('SettingsPage — failures', () => {
  it("shows the server's own message when the notebook list fails", async () => {
    fetchMock.mockImplementation(async () => failure('DATABASE_NOT_CONFIGURED', 'The database is not configured.'))
    renderPage()

    expect(await screen.findByText('The database is not configured.')).toBeInTheDocument()

    // The retry sits beside the field rather than inside its label, so it is
    // "Try again" and not "Default notebook The database is not configured".
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  })

  it('says so plainly when the device is offline', async () => {
    fetchMock.mockImplementation(async () => {
      throw new TypeError('Failed to fetch')
    })
    renderPage()

    expect(await screen.findByText(/you appear to be offline/i)).toBeInTheDocument()
  })

  it('keeps the confirmation open, and says why, when clearing fails', async () => {
    device.notes = [{ id: 'a' }]
    device.clearFails = true
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /clear offline data/i }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Clear offline data' }))

    // Reported where the user is looking — behind the modal it would be
    // announced and invisible.
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/something went wrong/i)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})
