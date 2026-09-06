/**
 * What this deployment can do.
 *
 * The property worth protecting: an optional capability is treated as **off**
 * until the server says otherwise. If the flags defaulted to on while loading,
 * every Pulse and Drive control would appear for a moment and then vanish on
 * an unconfigured deployment — which reads as a broken app rather than a
 * smaller one.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

vi.mock('../shared/api/client', () => ({
  fetchAppConfig: vi.fn(),
}))

const { fetchAppConfig } = await import('../shared/api/client')
const { AppConfigProvider, useAppConfig, useFeature } = await import('./AppConfigProvider')

const mockedFetch = fetchAppConfig as unknown as ReturnType<typeof vi.fn>

function Probe() {
  const config = useAppConfig()
  const ai = useFeature('ai')
  const drive = useFeature('drive')

  return (
    <div>
      <span data-testid="env">{config.env}</span>
      <span data-testid="retention">{config.limits.trash_retention_days}</span>
      {ai ? <button>Ask Pulse</button> : null}
      {drive ? <button>Choose from Drive</button> : null}
    </div>
  )
}

function renderWithClient() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <AppConfigProvider><Probe /></AppConfigProvider>
    </QueryClientProvider>,
  )
}

const CONFIG = {
  app: 'Notes',
  env: 'production',
  features: {
    ai: true, semantic_search: false, ocr: false, transcription: false,
    realtime: false, canvas: false, private_notes: false, drive: false,
    calendar: false, contacts: false, connect: false,
  },
  limits: { max_attachment_bytes: 26214400, trash_retention_days: 30 },
}

beforeEach(() => {
  mockedFetch.mockReset()
})

describe('AppConfigProvider', () => {
  it('treats every optional capability as off while the flags load', () => {
    // A promise that never settles: this is the loading state.
    mockedFetch.mockReturnValue(new Promise(() => undefined))
    renderWithClient()

    expect(screen.queryByRole('button', { name: 'Ask Pulse' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Choose from Drive' })).not.toBeInTheDocument()
  })

  it('shows only what the server says is enabled', async () => {
    mockedFetch.mockResolvedValue(CONFIG)
    renderWithClient()

    await waitFor(() => expect(screen.getByRole('button', { name: 'Ask Pulse' })).toBeInTheDocument())
    // Drive is off, so its control never exists — not disabled, absent.
    expect(screen.queryByRole('button', { name: 'Choose from Drive' })).not.toBeInTheDocument()
  })

  it('keeps everything off when the config call fails', async () => {
    mockedFetch.mockRejectedValue(new Error('unreachable'))
    renderWithClient()

    await waitFor(() => expect(screen.getByTestId('env')).toHaveTextContent('unknown'))
    // Failing closed: a deployment whose capabilities are unknown offers none
    // of them rather than offering all of them and failing on click.
    expect(screen.queryByRole('button', { name: 'Ask Pulse' })).not.toBeInTheDocument()
  })

  it('falls back to sane limits rather than undefined', async () => {
    mockedFetch.mockRejectedValue(new Error('unreachable'))
    renderWithClient()

    await waitFor(() => expect(screen.getByTestId('retention')).toHaveTextContent('30'))
  })

  it('asks for the flags once, not once per consumer', async () => {
    mockedFetch.mockResolvedValue(CONFIG)
    renderWithClient()

    await waitFor(() => expect(screen.getByRole('button', { name: 'Ask Pulse' })).toBeInTheDocument())
    expect(mockedFetch).toHaveBeenCalledTimes(1)
  })
})
