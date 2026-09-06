import { createContext, useContext, useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'

import {
  AuthError,
  CALLBACK_PATH,
  clearCallbackFromUrl,
  clearLogoutFlag,
  clearRedirectGuard,
  ensureSesKey,
  isLogoutInProgress,
  performLogout,
  readAuthCallback,
  redirectToPortalLoginForm,
  redirectToPortalSso,
} from './portal'
import { clearAllTokens, getAuthToken, setAuthToken } from './tokens'
import { getApiBaseUrl } from '../config'

/** The signed-in user, as this product's API reports them. */
export interface Profile {
  user_id: string
  tenant_id: string | null
  display_name: string
  email: string
}

/**
 * `loading` covers both "starting up" and "leaving for the portal" — in the
 * redirect case the page is about to unload, so it never renders anything else.
 */
export type AuthStatus = 'loading' | 'authenticated' | 'signed-out'

interface AuthState {
  status: AuthStatus
  /** Why the user is looking at the signed-out screen, when it was not a plain sign-out. */
  message: string | null
  /**
   * Who is signed in. Null until `GET /api/session` answers — which is a
   * separate, non-blocking step: the app is usable the moment a ses_key exists,
   * and waiting for a profile to render the editor would add a round trip to
   * every cold start for the sake of two initials in the corner.
   */
  profile: Profile | null
}

interface AuthContextValue extends AuthState {
  signIn: () => void
  signOut: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

const PORTAL_ERROR_MESSAGES: Record<string, string> = {
  access_denied: 'The portal declined the sign-in request.',
  redirect_loop: 'Sign-in kept looping. Clear this site’s data, then try again.',
}

function describePortalError(code: string): string {
  return PORTAL_ERROR_MESSAGES[code] ?? `The portal reported an error (${code}).`
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: 'loading', message: null, profile: null })

  // StrictMode runs effects twice in development. Booting twice would send two
  // portal jumps and burn two of the three tries the redirect guard allows, so
  // the second pass is skipped outright.
  const booted = useRef(false)

  useEffect(() => {
    if (booted.current) return
    booted.current = true

    // No "cancelled" flag and no cleanup here. StrictMode's simulated unmount
    // would set it before the real mount re-ran, and because `booted` correctly
    // suppresses the second run, nothing would ever clear it again — the app
    // would sit on "Signing you in…" forever in development.
    const settle = setState

    async function boot() {
      const { authToken, authError } = readAuthCallback()
      const onCallbackPath = window.location.pathname === CALLBACK_PATH

      if (authError) {
        clearCallbackFromUrl()
        clearAllTokens()
        settle({ status: 'signed-out', message: describePortalError(authError), profile: null })
        return
      }

      if (authToken) {
        // A token in the URL is the end of a sign-in, so both the sign-out flag
        // and the loop counter from that attempt are stale now.
        setAuthToken(authToken)
        clearLogoutFlag()
        clearRedirectGuard()
      }

      // Leave neither the token nor the bare callback path in the address bar.
      if (authToken || onCallbackPath) {
        clearCallbackFromUrl()
      }

      if (!getAuthToken()) {
        // A deliberate sign-out must not be undone by the automatic jump below.
        if (isLogoutInProgress()) {
          settle({ status: 'signed-out', message: null, profile: null })
          return
        }
        if (!redirectToPortalSso()) {
          settle({
            status: 'signed-out',
            message: 'Sign-in kept looping. Clear this site’s data, then try again.',
            profile: null,
          })
        }
        return
      }

      try {
        const sesKey = await ensureSesKey()
        settle({ status: 'authenticated', message: null, profile: null })

        // Fire-and-forget: the app is already usable, and a profile lookup that
        // fails must not sign anybody out.
        void loadProfile(sesKey)
          .then((profile) => profile && setState((current) => ({ ...current, profile })))
          .catch(() => undefined)
      } catch (err) {
        // 401 means the stored auth_token is spent. Anything else — the portal
        // being down, a timeout — must not silently discard a good token, so it
        // is reported instead of bounced.
        if (err instanceof AuthError && err.status === 401) {
          clearAllTokens()
          if (!redirectToPortalSso()) {
            settle({ status: 'signed-out', message: 'Your session has expired.', profile: null })
          }
          return
        }
        settle({
          status: 'signed-out',
          message: err instanceof Error ? err.message : 'Could not reach the sign-in service.',
          profile: null,
        })
      }
    }

    void boot()
  }, [])

  const value: AuthContextValue = {
    ...state,
    signIn: () => {
      clearLogoutFlag()
      clearRedirectGuard()
      setState({ status: 'loading', message: null, profile: null })
      redirectToPortalLoginForm()
    },
    signOut: () => {
      setState({ status: 'loading', message: null, profile: null })
      performLogout()
    },
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

/**
 * Ask this product's own API who the caller is.
 *
 * Not the portal directly: /api/session is what resolves the company context
 * this deployment will scope every note to, and reading it from one place keeps
 * the frontend's idea of the tenant identical to the server's.
 */
async function loadProfile(sesKey: string): Promise<Profile | null> {
  const response = await fetch(`${getApiBaseUrl()}/session`, {
    headers: { Authorization: `Bearer ${sesKey}` },
  })
  if (!response.ok) return null

  const body = (await response.json()) as {
    data?: { uuid?: string; tenant_id?: string | null; display_name?: string; email?: string }
  }
  const data = body.data
  if (!data?.uuid) return null

  return {
    user_id: data.uuid,
    tenant_id: data.tenant_id ?? null,
    display_name: data.display_name ?? '',
    email: data.email ?? '',
  }
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
