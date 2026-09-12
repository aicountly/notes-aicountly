/**
 * One cache entry, two readers, two shapes.
 *
 * `['notebooks']` is shared by the sidebar tree, the "go to notebook" picker
 * and every screen that needs a notebook's name. The picker wants a flat,
 * indented list; everyone else wants the nested rows the API returns. Storing
 * the flattened form let whichever query mounted first decide what the others
 * read — a sidebar that silently lost every child notebook, or a picker that
 * listed only roots.
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { ReactNode } from 'react'

import { useNotebookOptions } from './useSearch'
import { queryKeys } from '../../../shared/query/queryClient'
import type { Notebook } from '../../../shared/api/types'

vi.mock('../../../shared/api/client', async () => {
  const actual = await vi.importActual<typeof import('../../../shared/api/client')>(
    '../../../shared/api/client',
  )
  return { ...actual, api: { ...actual.api, get: vi.fn() } }
})

const { api } = await import('../../../shared/api/client')
const mockedGet = api.get as unknown as ReturnType<typeof vi.fn>

function notebook(id: string, name: string, children: unknown[] = []): Notebook {
  return {
    id,
    parent_id: null,
    name,
    description: null,
    icon: null,
    color: null,
    position: 0,
    depth: 0,
    children,
  } as unknown as Notebook
}

describe('useNotebookOptions', () => {
  it('flattens for its own reader without flattening the shared cache', async () => {
    const tree = [notebook('a', 'Work', [notebook('b', 'Invoices')])]
    mockedGet.mockResolvedValue(tree)

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    )

    const { result } = renderHook(() => useNotebookOptions(true), { wrapper })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    // The picker gets its flat, indented list...
    expect(result.current.data).toEqual([
      { id: 'a', name: 'Work', depth: 0 },
      { id: 'b', name: 'Invoices', depth: 1 },
    ])

    // ...and the cache still holds the nested rows every other reader of this
    // key came for, children included.
    const cached = client.getQueryData<Notebook[]>(queryKeys.notebooks)
    expect(cached).toEqual(tree)
    expect((cached?.[0] as unknown as { children: unknown[] }).children).toHaveLength(1)
  })
})
