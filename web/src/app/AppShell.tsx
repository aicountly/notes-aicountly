/**
 * The three-pane frame.
 *
 * Desktop shows navigation, the note list and the editor at once. Below
 * 1100px the list and editor share the space; below 760px only one pane is on
 * screen at a time and navigation happens by drilling in, because a
 * three-column layout squeezed onto a phone is three unusable columns rather
 * than one good one.
 *
 * Which pane is visible on a small screen follows the route — the list on a
 * list route, the editor once a note is open — so the browser's back button
 * does the obvious thing instead of needing a bespoke mobile stack.
 */

import { useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'

import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import { CommandPalette } from '../features/search/components/CommandPalette'
import { SearchDialog } from '../features/search/components/SearchDialog'
import { ConflictBanner } from '../features/notes/components/ConflictBanner'
import { useKeyboardShortcuts } from '../shared/hooks/useKeyboardShortcuts'
import { startSyncEngine } from '../shared/offline/syncEngine'
import { localNoteStore } from '../shared/offline/localNoteStore'
import { useAuth } from '../auth/AuthProvider'

export function AppShell() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [paletteOpen, setPaletteOpen] = useState(false)
  const location = useLocation()
  const { profile } = useAuth()

  useEffect(() => startSyncEngine(), [])

  // Clear another user's cached notes before anything reads them.
  useEffect(() => {
    if (profile?.user_id) void localNoteStore.ensureOwner(profile.user_id)
  }, [profile?.user_id])

  // Navigating closes the mobile drawer; leaving it open would cover the page
  // the user just asked for.
  useEffect(() => setSidebarOpen(false), [location.pathname])

  useKeyboardShortcuts({
    onOpenPalette: () => setPaletteOpen(true),
    onOpenSearch: () => setSearchOpen(true),
  })

  const editorOpen = /^\/notes\/[^/]+/.test(location.pathname)

  return (
    <div className={`shell ${editorOpen ? 'shell--editor' : ''}`}>
      <TopBar
        onOpenSearch={() => setSearchOpen(true)}
        onToggleSidebar={() => setSidebarOpen((open) => !open)}
        sidebarOpen={sidebarOpen}
      />

      <div className="shell__body">
        <div className={`shell__sidebar ${sidebarOpen ? 'shell__sidebar--open' : ''}`}>
          <Sidebar onNavigate={() => setSidebarOpen(false)} />
        </div>

        {/* Only rendered while the drawer is open, so it cannot swallow clicks
            on the desktop layout where the sidebar is always present. */}
        {sidebarOpen ? (
          <button
            type="button"
            className="shell__scrim"
            aria-label="Close navigation"
            onClick={() => setSidebarOpen(false)}
          />
        ) : null}

        <main className="shell__main" id="main-content">
          <ConflictBanner />
          <Outlet />
        </main>
      </div>

      <SearchDialog open={searchOpen} onClose={() => setSearchOpen(false)} />
      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </div>
  )
}
