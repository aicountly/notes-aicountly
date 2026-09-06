/**
 * Account, theme and sign-out.
 *
 * A menu rather than a settings page for the theme switch, because changing
 * theme is a thing people do idly and navigating away from a half-written note
 * to do it is the wrong trade.
 */

import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { useAuth } from '../auth/AuthProvider'
import { Icon } from '../shared/ui/Icon'
import { useTheme } from '../shared/ui/ThemeProvider'
import type { ThemePreference } from '../shared/ui/ThemeProvider'

const THEMES: Array<{ value: ThemePreference; label: string; icon: 'sun' | 'moon' | 'monitor' }> = [
  { value: 'light', label: 'Light', icon: 'sun' },
  { value: 'dark', label: 'Dark', icon: 'moon' },
  { value: 'system', label: 'System', icon: 'monitor' },
]

export function AccountMenu() {
  const [open, setOpen] = useState(false)
  const { signOut, profile } = useAuth()
  const { preference, setPreference } = useTheme()
  const navigate = useNavigate()
  const rootRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return undefined

    const onPointerDown = (event: MouseEvent) => {
      if (rootRef.current && event.target instanceof Node && !rootRef.current.contains(event.target)) {
        setOpen(false)
      }
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  const name = profile?.display_name || profile?.email || ''
  const initials =
    name
      .split(/[\s@.]+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('') || 'A'

  return (
    <div className="account" ref={rootRef}>
      <button
        type="button"
        className="account__trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label="Account and settings"
        onClick={() => setOpen((value) => !value)}
      >
        <span className="account__avatar" aria-hidden>{initials}</span>
      </button>

      {open ? (
        <div className="account__menu" role="menu">
          {name ? (
            <div className="account__identity">
              <p className="account__name">{profile?.display_name || 'Signed in'}</p>
              {profile?.email ? <p className="account__email">{profile.email}</p> : null}
            </div>
          ) : null}

          <div className="account__section">
            <p className="account__section-title" id="theme-label">Appearance</p>
            <div className="account__themes" role="radiogroup" aria-labelledby="theme-label">
              {THEMES.map((theme) => (
                <button
                  key={theme.value}
                  type="button"
                  role="radio"
                  aria-checked={preference === theme.value}
                  className={`account__theme ${preference === theme.value ? 'account__theme--active' : ''}`}
                  onClick={() => setPreference(theme.value)}
                >
                  <Icon name={theme.icon} size={15} />
                  <span>{theme.label}</span>
                </button>
              ))}
            </div>
          </div>

          <div className="account__section">
            <button type="button" role="menuitem" className="account__item" onClick={() => { setOpen(false); navigate('/settings') }}>
              <Icon name="settings" size={16} /> Settings
            </button>
            <button type="button" role="menuitem" className="account__item" onClick={() => { setOpen(false); navigate('/help') }}>
              <Icon name="help" size={16} /> Help &amp; shortcuts
            </button>
            <button type="button" role="menuitem" className="account__item account__item--danger" onClick={signOut}>
              <Icon name="undo" size={16} /> Log out
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
