/**
 * The application header.
 *
 * Three things live here and nothing else: getting back to Home, finding
 * anything, and the state of the current session (sync, theme, account). The
 * search field is the widest element because search is the most-used control in
 * a notes app once a library grows past a screenful.
 */

import { useNavigate } from 'react-router-dom'
import { Icon } from '../shared/ui/Icon'
import { Button } from '../shared/ui/primitives'
import { SyncStatusIndicator } from '../features/notes/components/SyncStatusIndicator'
import { AccountMenu } from './AccountMenu'
import { AppLauncher } from '../components/AppLauncher'

interface TopBarProps {
  onOpenSearch: () => void
  onToggleSidebar: () => void
  sidebarOpen: boolean
}

export function TopBar({ onOpenSearch, onToggleSidebar, sidebarOpen }: TopBarProps) {
  const navigate = useNavigate()

  return (
    <header className="topbar">
      <div className="topbar__left">
        <Button
          icon="rows"
          iconOnly
          variant="ghost"
          size="sm"
          className="topbar__menu-toggle"
          aria-label={sidebarOpen ? 'Hide navigation' : 'Show navigation'}
          aria-expanded={sidebarOpen}
          onClick={onToggleSidebar}
        />
        <AppLauncher />
        <button type="button" className="topbar__brand" onClick={() => navigate('/')}>
          <span className="topbar__brand-mark" aria-hidden>
            <Icon name="note" size={16} />
          </span>
          <span className="topbar__brand-name">Notes</span>
        </button>
      </div>

      {/* A button, not an input: the real search is a dialog with filters and
          keyboard navigation, and two search fields on one screen is one too
          many. Ctrl/Cmd+K opens the same dialog. */}
      <button type="button" className="topbar__search" onClick={onOpenSearch}>
        <Icon name="search" size={16} />
        <span className="topbar__search-label">Search anything…</span>
        <kbd className="topbar__search-kbd">
          {navigator.platform?.toLowerCase().includes('mac') ? '⌘' : 'Ctrl'} K
        </kbd>
      </button>

      <div className="topbar__right">
        <SyncStatusIndicator />
        <AccountMenu />
      </div>
    </header>
  )
}
