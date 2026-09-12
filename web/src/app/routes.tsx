/**
 * Routes.
 *
 * Everything below `/` renders inside {@link AppShell}, so navigation, the
 * search dialog and the sync engine survive a route change rather than
 * remounting with it.
 *
 * The editor is a child of the notes list route on purpose: on a wide screen
 * both panes are visible at once, and on a narrow one the same URL simply shows
 * the editor. One route tree, two layouts, no duplicated state.
 */

import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'

import { AppShell } from './AppShell'
import { HomePage } from '../features/notes/pages/HomePage'
import { NotesPage } from '../features/notes/pages/NotesPage'
import { PageFallback } from '../shared/ui/PageFallback'

// Split out the screens a user may never open in a session. The editor is not
// among them — it must open instantly.
const RemindersPage = lazy(() => import('../features/reminders/pages/RemindersPage'))
const TemplatesPage = lazy(() => import('../features/templates/pages/TemplatesPage'))
const SettingsPage = lazy(() => import('../features/settings/pages/SettingsPage'))
const HelpPage = lazy(() => import('../features/settings/pages/HelpPage'))

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<AppShell />}>
        <Route index element={<HomePage />} />

        {/* The list and the editor share a route so the middle column keeps its
            scroll position while notes are opened from it. */}
        <Route path="notes" element={<NotesPage scope="active" />}>
          <Route path=":noteId" element={null} />
        </Route>
        <Route path="notebooks/:notebookId" element={<NotesPage scope="notebook" />}>
          <Route path="notes/:noteId" element={null} />
        </Route>
        <Route path="tags/:tagSlug" element={<NotesPage scope="tag" />}>
          <Route path="notes/:noteId" element={null} />
        </Route>
        <Route path="smart-folders/:folderId" element={<NotesPage scope="smart-folder" />}>
          <Route path="notes/:noteId" element={null} />
        </Route>
        <Route path="shared" element={<NotesPage scope="shared" />}>
          <Route path=":noteId" element={null} />
        </Route>
        <Route path="archive" element={<NotesPage scope="archive" />}>
          <Route path=":noteId" element={null} />
        </Route>
        <Route path="trash" element={<NotesPage scope="trash" />}>
          <Route path=":noteId" element={null} />
        </Route>

        <Route
          path="reminders"
          element={<Suspense fallback={<PageFallback />}><RemindersPage /></Suspense>}
        />
        <Route
          path="templates"
          element={<Suspense fallback={<PageFallback />}><TemplatesPage /></Suspense>}
        />
        <Route
          path="settings"
          element={<Suspense fallback={<PageFallback />}><SettingsPage /></Suspense>}
        />
        <Route
          path="help"
          element={<Suspense fallback={<PageFallback />}><HelpPage /></Suspense>}
        />

        {/* The portal lands here after sign-in; AuthProvider has already taken
            the token out of the URL by the time this renders. */}
        <Route path="auth/callback" element={<Navigate to="/" replace />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  )
}
