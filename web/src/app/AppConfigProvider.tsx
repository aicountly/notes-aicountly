/**
 * What this deployment can actually do.
 *
 * Fetched once from `GET /api/config` and read by every component that would
 * otherwise render a control for a capability the server has switched off.
 * Hiding those controls is the point: a Pulse button that 503s on click is
 * worse than no Pulse button.
 *
 * While the flags are loading everything optional is treated as OFF, so a
 * disabled feature never flickers into view and back out.
 */

import { createContext, useContext } from 'react'
import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'

import { fetchAppConfig } from '../shared/api/client'
import { queryKeys } from '../shared/query/queryClient'
import type { AppConfig, FeatureFlags } from '../shared/api/types'

const ALL_OFF: FeatureFlags = {
  ai: false,
  semantic_search: false,
  ocr: false,
  transcription: false,
  canvas: false,
  realtime: false,
  private_notes: false,
  drive: false,
  calendar: false,
  contacts: false,
  connect: false,
}

const FALLBACK: AppConfig = {
  app: 'Notes',
  env: 'unknown',
  features: ALL_OFF,
  limits: { max_attachment_bytes: 25 * 1024 * 1024, trash_retention_days: 30 },
}

const ConfigContext = createContext<{ config: AppConfig; loading: boolean }>({
  config: FALLBACK,
  loading: true,
})

export function AppConfigProvider({ children }: { children: ReactNode }) {
  const { data, isLoading } = useQuery({
    queryKey: queryKeys.config,
    queryFn: fetchAppConfig,
    // The flags change when someone edits the server's .env, not during a
    // session. Refetching them would be pure noise.
    staleTime: Infinity,
    retry: 1,
  })

  return (
    <ConfigContext.Provider value={{ config: data ?? FALLBACK, loading: isLoading }}>
      {children}
    </ConfigContext.Provider>
  )
}

export function useAppConfig(): AppConfig {
  return useContext(ConfigContext).config
}

/** True only when the server says the capability is on. */
export function useFeature(flag: keyof FeatureFlags): boolean {
  return useContext(ConfigContext).config.features[flag]
}
