/**
 * Settings.
 *
 * A short page on purpose. Notes has very few preferences, and every one that
 * is here is read by something — the theme by the document, the default view by
 * the note list, the default notebook by the template picker. A control that
 * only records an opinion would be worse than not offering it.
 *
 * The second half is not settings at all but **facts**: what this deployment
 * has switched on, what it is keeping on this device, and which build is
 * running. They live here because this is where people come when something is
 * missing and they want to know whether it is them or the server — and the
 * honest answer to "where is Pulse?" is a line on this page, not silence.
 */

import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { Badge, Button, Dialog, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { useTheme } from '../../../shared/ui/ThemeProvider'
import type { ThemePreference } from '../../../shared/ui/ThemeProvider'
import { useAppConfig } from '../../../app/AppConfigProvider'
import { APP_ENV, APP_NAME, getApiBaseUrl } from '../../../config'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { flattenNotebooks, useNotebooks } from '../../notebooks/hooks/useNotebooks'
import { NOTE_SORTS, NOTE_VIEWS, SORT_KEY, VIEW_KEY, useDefaultNotebook, usePreference } from '../preferences'
import type { NoteView } from '../preferences'
import { clearOfflineData, formatBytes, useOfflineUsage } from '../offlineStorage'
import { FEATURE_ROWS } from '../features'
import '../settings.css'

const THEMES: { value: ThemePreference; label: string; icon: IconName }[] = [
  { value: 'light', label: 'Light', icon: 'sun' },
  { value: 'dark', label: 'Dark', icon: 'moon' },
  { value: 'system', label: 'Match my device', icon: 'monitor' },
]

const VIEW_VALUES = NOTE_VIEWS.map((view) => view.value)
const SORT_VALUES = NOTE_SORTS.map((sort) => sort.value)

export default function SettingsPage() {
  const config = useAppConfig()
  const { preference, resolved, setPreference } = useTheme()

  const [view, setView] = usePreference<NoteView>(VIEW_KEY, VIEW_VALUES, 'grid')
  const [sort, setSort] = usePreference(SORT_KEY, SORT_VALUES, 'updated_desc')
  const [defaultNotebook, setDefaultNotebook] = useDefaultNotebook()

  const notebooks = useNotebooks()
  const { usage, refresh } = useOfflineUsage()

  const [clearing, setClearing] = useState(false)
  const [confirmClear, setConfirmClear] = useState(false)
  const [clearError, setClearError] = useState<unknown>(null)
  const [announcement, setAnnouncement] = useState('')

  const notebookOptions = flattenNotebooks(notebooks.data ?? []).filter((option) => !option.is_archived)

  // Dismissing the dialog drops the failure with it: reopening it should not
  // reopen an error about an attempt the user has moved on from.
  const closeConfirm = () => {
    setConfirmClear(false)
    setClearError(null)
  }

  const clear = () => {
    setClearing(true)
    setClearError(null)

    clearOfflineData()
      .then(() => {
        setConfirmClear(false)
        setAnnouncement('Offline data cleared from this device.')
        refresh()
      })
      .catch(setClearError)
      .finally(() => setClearing(false))
  }

  return (
    <div className="set-page">
      <div className="set-page__inner">
        <header>
          <h1 className="set-page__title">Settings</h1>
          <p className="set-page__subtitle">
            These apply to {APP_NAME} on this device. Nothing here is shared with anyone you share notes with.
          </p>
        </header>

        {/* --- Appearance ------------------------------------------------- */}
        <Section title="Appearance" description="Light, dark, or whatever this device is set to.">
          <fieldset className="set-choice">
            <legend className="sr-only">Theme</legend>
            {THEMES.map((theme) => (
              <label className="set-choice__option" key={theme.value}>
                <input
                  type="radio"
                  name="theme"
                  value={theme.value}
                  checked={preference === theme.value}
                  onChange={() => setPreference(theme.value)}
                />
                <Icon name={theme.icon} size={16} />
                <span>{theme.label}</span>
              </label>
            ))}
          </fieldset>

          <p className="set-hint">
            {preference === 'system'
              ? `Following this device, which is currently ${resolved}.`
              : `Always ${preference}, whatever this device is set to.`}
          </p>
        </Section>

        {/* --- Notes ------------------------------------------------------ */}
        <Section title="Notes" description="How your lists open, and where new notes go.">
          <Field label="Default view" hint="The layout a note list opens in. You can still switch it per list.">
            <select className="org-select" value={view} onChange={(event) => setView(event.target.value as NoteView)}>
              {NOTE_VIEWS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label} — {option.description}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Default order" hint="How a list is sorted before you change it.">
            <select className="org-select" value={sort} onChange={(event) => setSort(event.target.value)}>
              {NOTE_SORTS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </Field>

          <Field
            label="Default notebook"
            hint="Where a note goes when it is created without a notebook in mind — starting one from a template, for instance."
            notice={
              notebooks.isError ? (
                <ErrorNotice error={notebooks.error} onRetry={() => void notebooks.refetch()} />
              ) : null
            }
          >
            {notebooks.isPending ? (
              <Skeleton width="100%" height={36} radius={8} />
            ) : notebooks.isError ? null : (
              <select
                className="org-select"
                value={defaultNotebook ?? ''}
                onChange={(event) => setDefaultNotebook(event.target.value === '' ? null : event.target.value)}
              >
                <option value="">No notebook</option>
                {notebookOptions.map((option) => (
                  <option key={option.id} value={option.id}>
                    {'— '.repeat(option.depth)}
                    {option.name}
                  </option>
                ))}
              </select>
            )}
          </Field>
        </Section>

        {/* --- Capabilities ----------------------------------------------- */}
        <Section
          title="What this deployment can do"
          description="Set by whoever runs this server, not by you. Anything switched off is simply not in the app — there are no disabled buttons to find."
        >
          <ul className="set-features">
            {FEATURE_ROWS.map((row) => {
              const on = config.features[row.flag]

              return (
                <li className={`set-feature ${on ? '' : 'set-feature--off'}`.trim()} key={row.flag}>
                  {/* Decorative: the badge below carries the state in words,
                      so the colour and the icon only echo it. */}
                  <span className="set-feature__state">
                    <Icon name={on ? 'check' : 'close'} size={15} />
                  </span>

                  <div className="set-feature__body">
                    <p className="set-feature__name">
                      {row.label}
                      <Badge tone={on ? 'primary' : 'neutral'}>{on ? 'On' : 'Off'}</Badge>
                    </p>
                    <p className="set-feature__description">{on ? row.on : row.off}</p>
                  </div>
                </li>
              )
            })}
          </ul>
        </Section>

        {/* --- Offline ---------------------------------------------------- */}
        <Section
          title="Offline copy"
          description="Notes are mirrored onto this device so the app still works without a connection."
        >
          {usage === null ? (
            <div className="set-usage" aria-busy>
              <Skeleton width="60%" height={14} />
              <Skeleton width="40%" height={14} />
            </div>
          ) : usage.unavailable ? (
            <p className="set-hint">
              This browser will not let {APP_NAME} store anything on the device — a private window, usually. The
              app works, but nothing is available offline.
            </p>
          ) : (
            <>
              <dl className="set-usage">
                <div>
                  <dt>Notes on this device</dt>
                  <dd>
                    {usage.notes} cached, {usage.readable} openable offline
                  </dd>
                </div>
                <div>
                  <dt>Changes not yet sent</dt>
                  <dd>{usage.pending === 0 ? 'None' : `${usage.pending} waiting for a connection`}</dd>
                </div>
                <div>
                  <dt>Storage used by this site</dt>
                  <dd>{formatBytes(usage.bytes)}</dd>
                </div>
              </dl>

              <div className="set-actions">
                <Button icon="trash" variant={usage.pending > 0 ? 'danger' : 'secondary'} onClick={() => setConfirmClear(true)}>
                  Clear offline data
                </Button>
                <Button icon="refresh" variant="ghost" onClick={refresh}>
                  Recheck
                </Button>
              </div>
            </>
          )}
        </Section>

        {/* --- About ------------------------------------------------------ */}
        <Section title="About this app" description="Useful when you are reporting something that went wrong.">
          <dl className="set-usage">
            <div>
              <dt>Application</dt>
              <dd>
                {config.app} ({APP_NAME})
              </dd>
            </div>
            <div>
              <dt>Build</dt>
              <dd>{APP_ENV}</dd>
            </div>
            <div>
              <dt>Server</dt>
              <dd>{config.env}</dd>
            </div>
            <div>
              <dt>API</dt>
              <dd className="set-usage__mono">{getApiBaseUrl()}</dd>
            </div>
            <div>
              <dt>Is the server up?</dt>
              <dd>
                {/* The health endpoint answers without a session, so this link
                    works even when the thing that is broken is sign-in. */}
                <a className="set-link" href={`${getApiBaseUrl()}/health`} target="_blank" rel="noreferrer">
                  Open the health check
                </a>
                <span className="set-usage__note"> — whether the API, its database and its job queue are answering</span>
              </dd>
            </div>
            <div>
              <dt>Trash is emptied after</dt>
              <dd>
                {config.limits.trash_retention_days}{' '}
                {config.limits.trash_retention_days === 1 ? 'day' : 'days'}
                <span className="set-usage__note"> — set on the server</span>
              </dd>
            </div>
            <div>
              <dt>Largest attachment</dt>
              <dd>
                {formatBytes(config.limits.max_attachment_bytes)}
                <span className="set-usage__note"> — set on the server</span>
              </dd>
            </div>
          </dl>

          <p className="set-hint">
            Keyboard shortcuts and how the pieces fit together are on the <Link to="/help">Help page</Link>.
          </p>
        </Section>
      </div>

      <LiveStatus>{announcement}</LiveStatus>

      {confirmClear && usage ? (
        <Dialog
          open
          onClose={closeConfirm}
          title="Clear offline data?"
          description={
            usage.pending > 0
              ? `There ${usage.pending === 1 ? 'is 1 change' : `are ${usage.pending} changes`} on this device that have not reached the server. Clearing throws them away.`
              : 'Your notes stay on the server. This only empties the copy held on this device, which will be rebuilt the next time you open them.'
          }
          width={440}
          footer={
            <>
              <Button onClick={closeConfirm} disabled={clearing}>
                Cancel
              </Button>
              <Button variant="danger" icon="trash" loading={clearing} onClick={clear}>
                {usage.pending > 0 ? 'Discard and clear' : 'Clear offline data'}
              </Button>
            </>
          }
        >
          {clearError ? <ErrorNotice error={clearError} /> : null}

          <p className="set-hint">
            {usage.notes} cached {usage.notes === 1 ? 'note' : 'notes'} will be removed from this device. You will
            need a connection to read them again.
          </p>
        </Dialog>
      ) : null}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Layout
// ---------------------------------------------------------------------------

function Section({
  title,
  description,
  children,
}: {
  title: string
  description: string
  children: ReactNode
}) {
  return (
    <section className="set-section" aria-labelledby={`set-${slug(title)}`}>
      <div className="set-section__head">
        <h2 className="set-section__title" id={`set-${slug(title)}`}>
          {title}
        </h2>
        <p className="set-section__description">{description}</p>
      </div>
      <div className="set-section__body">{children}</div>
    </section>
  )
}

function Field({
  label,
  hint,
  notice,
  children,
}: {
  label: string
  hint: string
  /** Shown beside the field rather than inside its label — see below. */
  notice?: ReactNode
  children: ReactNode
}) {
  return (
    <div className="set-field">
      {/* A label wrapping its control needs no id to match, which is one fewer
          thing to get wrong when the control is a skeleton half the time. It
          may only wrap the control, though: anything else inside it — a retry
          button in an error notice — takes the label's words as its own
          accessible name. So a field with no control renders plain text, and
          `notice` sits outside the label. */}
      {children === null ? (
        <span className="org-label">{label}</span>
      ) : (
        <label className="set-field__label">
          <span className="org-label">{label}</span>
          {children}
        </label>
      )}
      {notice}
      <p className="set-hint">{hint}</p>
    </div>
  )
}

function slug(value: string): string {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-')
}
