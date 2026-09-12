import { useEffect } from 'react'
import { BrowserRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'

import { useAuth } from './auth/AuthProvider'
import { AppRoutes } from './app/routes'
import { AppConfigProvider } from './app/AppConfigProvider'
import { ThemeProvider } from './shared/ui/ThemeProvider'
import { queryClient } from './shared/query/queryClient'
import SignIn from './pages/SignIn'
import { initAnalytics, trackPageView } from './utils/analytics'

import './styles/tokens.css'
import './styles/app.css'
import './styles/notes.css'
import './styles/editor.css'

initAnalytics()

/**
 * The application, once there is a session.
 *
 * Sign-in is still the portal's job and still happens before any of this
 * mounts, which is why the router lives inside the authenticated branch: an
 * unauthenticated visitor has exactly one thing they can do, and giving them
 * routes to a shell they cannot populate would only produce empty screens.
 */
function AuthenticatedApp() {
  useEffect(() => {
    // Page views are reported per route by the shell; this is the entry event.
    trackPageView(window.location.pathname, 'Notes')
  }, [])

  return (
    <QueryClientProvider client={queryClient}>
      <AppConfigProvider>
        <BrowserRouter>
          <a className="skip-link" href="#main-content">Skip to content</a>
          <AppRoutes />
        </BrowserRouter>
      </AppConfigProvider>
    </QueryClientProvider>
  )
}

export default function App() {
  const { status } = useAuth()

  useEffect(() => {
    if (status === 'signed-out') trackPageView('/sign-in', 'Sign in')
  }, [status])

  return (
    <ThemeProvider>
      {status === 'authenticated' ? (
        <AuthenticatedApp />
      ) : status === 'signed-out' ? (
        <SignIn />
      ) : (
        <main className="boot-screen">
          <span className="boot-screen__mark" aria-hidden />
          <p className="boot-screen__message">Signing you in…</p>
        </main>
      )}
    </ThemeProvider>
  )
}
