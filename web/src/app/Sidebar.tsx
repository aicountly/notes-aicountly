/**
 * Primary navigation.
 *
 * Ordered by how often it is used, not by hierarchy: Home and My Notes first,
 * the containers next, and the things you visit rarely — Archive, Trash —
 * pushed to the end. Counts appear only where the number changes a decision
 * ("is there anything in my trash?"), because a badge on everything is a badge
 * on nothing.
 */

import { NavLink } from 'react-router-dom'
import { Icon } from '../shared/ui/Icon'
import type { IconName } from '../shared/ui/Icon'
import { useSidebarCounts } from '../features/notes/hooks/useNotes'
import { NotebookTree } from '../features/notebooks/components/NotebookTree'
import { SmartFolderList } from '../features/smart-folders/components/SmartFolderList'
import { TagRail } from '../features/tags/components/TagRail'

interface NavItem {
  to: string
  label: string
  icon: IconName
  countKey?: 'active' | 'archived' | 'trashed' | 'shared_with_me'
  end?: boolean
}

const PRIMARY: NavItem[] = [
  { to: '/', label: 'Home', icon: 'home', end: true },
  { to: '/notes', label: 'My Notes', icon: 'note', countKey: 'active' },
  { to: '/shared', label: 'Shared with me', icon: 'shared', countKey: 'shared_with_me' },
  { to: '/reminders', label: 'Reminders', icon: 'bell' },
  { to: '/templates', label: 'Templates', icon: 'template' },
]

const SECONDARY: NavItem[] = [
  { to: '/archive', label: 'Archive', icon: 'archive', countKey: 'archived' },
  { to: '/trash', label: 'Trash', icon: 'trash', countKey: 'trashed' },
]

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { data: counts } = useSidebarCounts()

  const renderItem = (item: NavItem) => {
    const count = item.countKey ? counts?.[item.countKey] : undefined

    return (
      <li key={item.to}>
        <NavLink
          to={item.to}
          end={item.end}
          onClick={onNavigate}
          className={({ isActive }) => `nav-item ${isActive ? 'nav-item--active' : ''}`}
        >
          <Icon name={item.icon} size={17} />
          <span className="nav-item__label">{item.label}</span>
          {count ? <span className="nav-item__count">{count > 999 ? '999+' : count}</span> : null}
        </NavLink>
      </li>
    )
  }

  return (
    <nav className="sidebar" aria-label="Notes navigation">
      <div className="sidebar__scroll">
        <ul className="nav-list">{PRIMARY.map(renderItem)}</ul>

        <NotebookTree onNavigate={onNavigate} />
        <SmartFolderList onNavigate={onNavigate} />
        <TagRail onNavigate={onNavigate} />

        <ul className="nav-list nav-list--secondary">{SECONDARY.map(renderItem)}</ul>
      </div>

      <div className="sidebar__footer">
        <NavLink to="/settings" onClick={onNavigate} className={({ isActive }) => `nav-item nav-item--compact ${isActive ? 'nav-item--active' : ''}`}>
          <Icon name="settings" size={16} />
          <span className="nav-item__label">Settings</span>
        </NavLink>
        <NavLink to="/help" onClick={onNavigate} className={({ isActive }) => `nav-item nav-item--compact ${isActive ? 'nav-item--active' : ''}`}>
          <Icon name="help" size={16} />
          <span className="nav-item__label">Help</span>
        </NavLink>
      </div>
    </nav>
  )
}
