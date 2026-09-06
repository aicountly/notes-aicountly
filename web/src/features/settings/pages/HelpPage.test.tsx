/**
 * What Help promises.
 *
 * The load-bearing test is the first one: the page lists **exactly** the
 * shortcuts `shared/hooks/useKeyboardShortcuts` binds. The expected set below
 * is written out by hand from that file on purpose — if a binding is added or
 * dropped there and this page is not updated, this test is what says so. A help
 * page that documents a key which does nothing costs more trust than it saves
 * time, because the reader cannot then tell which of the others are real.
 *
 * The rest is the page being honest about the build it is running in: Pulse is
 * described as switched off rather than as coming, and everything it says about
 * links, tags, sharing, offline and version history is something this app does.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import HelpPage from './HelpPage'
import { AppConfigProvider } from '../../../app/AppConfigProvider'
import { ApiError } from '../../../shared/api/client'
import type { AppConfig, FeatureFlags } from '../../../shared/api/types'

vi.mock('../../../auth/portal', () => ({ ensureSesKey: async () => 'test-session-key' }))

/** What `GET /config` answers, or refuses to. */
const deployment = vi.hoisted(() => ({ config: null as unknown, error: null as unknown }))

vi.mock('../../../shared/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../shared/api/client')>()
  return {
    ...actual,
    fetchAppConfig: async () => {
      if (deployment.error) throw deployment.error
      return deployment.config
    },
  }
})

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

function makeConfig(features: Partial<FeatureFlags> = {}): AppConfig {
  return {
    app: 'Notes',
    env: 'sandbox',
    features: { ...ALL_OFF, ...features },
    limits: { max_attachment_bytes: 26_214_400, trash_retention_days: 30 },
  }
}

/** jsdom reports an empty platform; the page falls back to the user agent. */
function setPlatform(value: string): void {
  Object.defineProperty(window.navigator, 'platform', { value, configurable: true })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={client}>
      <AppConfigProvider>
        <MemoryRouter>
          <HelpPage />
        </MemoryRouter>
      </AppConfigProvider>
    </QueryClientProvider>,
  )
}

/** The key combinations one table documents, as "⌘+K" strings. */
function combosIn(caption: string): string[] {
  const table = screen.getByRole('table', { name: caption })

  return within(table)
    .getAllByRole('rowheader')
    .map((header) =>
      Array.from(header.querySelectorAll('kbd'))
        .map((key) => key.textContent ?? '')
        .join('+'),
    )
}

/** The explainer with this heading. */
function explainer(title: string | RegExp): HTMLElement {
  return screen.getByRole('heading', { name: title }).closest('article') as HTMLElement
}

beforeEach(() => {
  deployment.config = makeConfig()
  deployment.error = null
  setPlatform('MacIntel')
})

describe('HelpPage — keyboard', () => {
  it('documents every shortcut the app binds, and none it does not', () => {
    renderPage()

    // Straight from useKeyboardShortcuts: palette, search, new note, this page,
    // and the unmodified "/" that only fires outside a text field.
    expect(combosIn('Anywhere in the app')).toEqual(['⌘+K', '⌘+⇧+F', '⌘+N', '⌘+/', '/'])
  })

  it('says when the bare slash does not apply', () => {
    renderPage()

    expect(screen.getByText(/only when you are not typing in a note or a field/i)).toBeInTheDocument()
  })

  it('documents how dialogs and menus are driven from the keyboard', () => {
    renderPage()

    expect(combosIn('In a dialog or a menu')).toEqual(['Esc', 'Tab', '↑+↓', '↵'])
  })

  it('writes the modifier the way a Mac keyboard has it', () => {
    setPlatform('MacIntel')
    renderPage()

    expect(screen.getByText(/shown for a mac keyboard/i)).toBeInTheDocument()
    expect(combosIn('Anywhere in the app')).toContain('⌘+K')
  })

  it('writes it as Ctrl everywhere else', () => {
    setPlatform('Win32')
    renderPage()

    expect(screen.getByText(/shown for a pc keyboard/i)).toBeInTheDocument()
    expect(combosIn('Anywhere in the app')).toEqual(['Ctrl+K', 'Ctrl+Shift+F', 'Ctrl+N', 'Ctrl+/', '/'])
  })

  it('falls back to the user agent when the platform is not reported', () => {
    setPlatform('')
    Object.defineProperty(window.navigator, 'userAgent', {
      value: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
      configurable: true,
    })
    renderPage()

    expect(combosIn('Anywhere in the app')).toContain('⌘+K')
  })
})

describe('HelpPage — how Notes works', () => {
  it('explains note links in both the menu and the copied-out form', () => {
    renderPage()

    const links = explainer(/linking one note to another/i)
    expect(within(links).getByText(/link to note/i)).toBeInTheDocument()
    expect(within(links).getByText('[[Note title]]')).toBeInTheDocument()
    expect(within(links).getByText(/backlinks/i)).toBeInTheDocument()
  })

  it('explains the "/" menu and the "@" one beside it', () => {
    renderPage()

    const slash = explainer(/the “\/” menu/i)
    expect(within(slash).getByText(/headings, bulleted and numbered lists/i)).toBeInTheDocument()
    expect(within(slash).getByText('@')).toBeInTheDocument()
  })

  it('explains that a tag is a label and not a place', () => {
    renderPage()

    const tags = explainer('Tags')
    expect(within(tags).getByText(/case-insensitive/i)).toBeInTheDocument()
    expect(within(tags).getByText(/a label rather than a place/i)).toBeInTheDocument()
  })

  it('says what the four sharing roles can do', () => {
    renderPage()

    const sharing = explainer(/sharing, and what each role can do/i)
    for (const role of ['Viewer', 'Commenter', 'Editor', 'Owner']) {
      expect(within(sharing).getByText(role)).toBeInTheDocument()
    }
    expect(within(sharing).getByText(/only the owner can share it/i)).toBeInTheDocument()
  })

  it('says what happens to a change made offline', () => {
    renderPage()

    const offline = explainer(/working offline/i)
    expect(within(offline).getByText(/queued and sent when you reconnect/i)).toBeInTheDocument()
    // The conflict rule, which is the part people are surprised by.
    expect(within(offline).getByText(/merged silently/i)).toBeInTheDocument()
    expect(within(offline).getByText(/saves it as a new note/i)).toBeInTheDocument()
  })

  it('says where version history actually lives', () => {
    renderPage()

    const history = explainer(/earlier versions of a note/i)
    expect(within(history).getByText(/show note info/i)).toBeInTheDocument()
  })
})

describe('HelpPage — honesty about this deployment', () => {
  it('says Pulse is switched off rather than promising it', async () => {
    renderPage()

    expect(await screen.findByText(/not enabled on this deployment/i)).toBeInTheDocument()

    const pulse = explainer('Pulse')
    expect(within(pulse).getByText(/there is nothing to switch on here/i)).toBeInTheDocument()
    expect(screen.queryByText(/coming soon/i)).not.toBeInTheDocument()
  })

  // Both halves of "is it off, or has nobody asked yet?" are states of their
  // own. Answering the first while the second is true is the same lie as a
  // "Coming soon" badge, just harder to spot.
  it('does not say Pulse is off before the server has answered', () => {
    renderPage()

    const pulse = explainer('Pulse')
    expect(within(pulse).getByText(/checking whether this deployment has pulse/i)).toBeInTheDocument()
    expect(within(pulse).queryByText(/not enabled on this deployment/i)).not.toBeInTheDocument()
  })

  it('says it could not find out, rather than guessing, when the server is unreachable', async () => {
    deployment.error = new ApiError('OFFLINE', 'You appear to be offline.', 0)

    renderPage()

    const pulse = explainer('Pulse')
    expect(await within(pulse).findByText(/could not reach the server/i, undefined, { timeout: 4000 })).toBeInTheDocument()
    expect(within(pulse).queryByText(/not enabled on this deployment/i)).not.toBeInTheDocument()
  })

  it('describes what Pulse does when the server has it on', async () => {
    deployment.config = makeConfig({ ai: true })
    renderPage()

    expect(await screen.findByText(/answers cite the notes they came from/i)).toBeInTheDocument()
    expect(screen.queryByText(/not enabled on this deployment/i)).not.toBeInTheDocument()
  })
})
