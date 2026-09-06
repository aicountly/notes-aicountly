/**
 * Help.
 *
 * The rule this page is written under: **nothing is documented that does not
 * exist.** Every shortcut below is one `shared/hooks/useKeyboardShortcuts.ts`
 * actually binds, and every behaviour described is one the code does. A help
 * page that lists an aspirational shortcut costs more trust than it saves time,
 * because the reader cannot tell which of the other nine are real either.
 *
 * The modifier is written the way the reader's keyboard has it — ⌘ on a Mac,
 * Ctrl everywhere else — since "Cmd/Ctrl+K" makes every reader do a small
 * translation on every line.
 */

import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import type { IconName } from '../../../shared/ui/Icon'
import { useFeature } from '../../../app/AppConfigProvider'
import { describeFeature } from '../features'
import '../settings.css'

/**
 * Which modifier this keyboard has.
 *
 * `navigator.platform` is deprecated and is still the only thing that answers
 * on every engine this app runs in; the user-agent string is the fallback. The
 * cost of guessing wrong is one wrong glyph, so neither is worth a feature
 * detection dance.
 *
 * Read while rendering rather than when the module loads: this page is a lazy
 * chunk, and a value fixed at import time is one more thing that depends on
 * *when* the chunk happened to arrive.
 */
function isMac(): boolean {
  const platform = navigator.platform ?? ''
  if (platform !== '') return platform.toLowerCase().includes('mac')

  return /mac/i.test(navigator.userAgent ?? '')
}

interface Shortcut {
  keys: string[]
  what: string
  /** The bit people get wrong, where there is one. */
  note?: string
}

/**
 * Exactly what `shared/hooks/useKeyboardShortcuts` binds — nothing aspirational.
 *
 * If a binding is added there and not here, this page is wrong; that is the
 * cost of documenting behaviour that lives in another file, and it is cheaper
 * than the alternative of a help page nobody trusts.
 */
function globalShortcuts(mod: string, shift: string): Shortcut[] {
  return [
    { keys: [mod, 'K'], what: 'Open the command palette' },
    { keys: [mod, shift, 'F'], what: 'Open search' },
    { keys: [mod, 'N'], what: 'Start a new note' },
    { keys: [mod, '/'], what: 'Open this page' },
    {
      keys: ['/'],
      what: 'Open search',
      note: 'Only when you are not typing in a note or a field — otherwise it types a slash.',
    },
  ]
}

/** Behaviour of the dialog and menu primitives, which every screen inherits. */
const DIALOG_SHORTCUTS: Shortcut[] = [
  { keys: ['Esc'], what: 'Close the dialog or menu you are in' },
  { keys: ['Tab'], what: 'Move between the controls inside a dialog', note: 'Focus stays inside until it closes.' },
  { keys: ['↑', '↓'], what: 'Move through the items in a menu or a list of results' },
  { keys: ['↵'], what: 'Choose the highlighted item' },
]

export default function HelpPage() {
  const pulseEnabled = useFeature('ai')

  const mac = isMac()
  const globals = globalShortcuts(mac ? '⌘' : 'Ctrl', mac ? '⇧' : 'Shift')

  return (
    <div className="set-page">
      <div className="set-page__inner">
        <header>
          <h1 className="set-page__title">Help</h1>
          <p className="set-page__subtitle">
            How Notes works, and every keyboard shortcut it has. Preferences live in{' '}
            <Link to="/settings">Settings</Link>.
          </p>
        </header>

        <section className="set-section" aria-labelledby="help-keys">
          <div className="set-section__head">
            <h2 className="set-section__title" id="help-keys">
              Keyboard
            </h2>
            <p className="set-section__description">
              Shown for {mac ? 'a Mac keyboard' : 'a PC keyboard'}, which is what this device reports.
            </p>
          </div>

          <div className="set-section__body">
            <ShortcutTable caption="Anywhere in the app" rows={globals} />
            <ShortcutTable caption="In a dialog or a menu" rows={DIALOG_SHORTCUTS} />

            <p className="set-hint">
              Inside the note body, the usual formatting shortcuts your browser and this editor provide still
              work — bold, italic, undo. Everything Notes itself adds is in the list above.
            </p>
          </div>
        </section>

        <section className="set-section" aria-labelledby="help-how">
          <div className="set-section__head">
            <h2 className="set-section__title" id="help-how">
              How Notes works
            </h2>
            <p className="set-section__description">The handful of things worth knowing before you start.</p>
          </div>

          <div className="set-section__body">
            <Explainer icon="link" title="Linking one note to another">
              <p>
                Type <kbd>/</kbd> in a note and choose <strong>Link to note</strong>. The link stores the other
                note’s identity rather than its title, so renaming the target keeps every link to it working — and
                the target grows a <strong>Backlinks</strong> list showing everything that points at it.
              </p>
              <p>
                Copy a note out of the app and its links come with it as{' '}
                <code>[[Note title]]</code>, which is what other note tools understand.
              </p>
            </Explainer>

            <Explainer icon="list" title="The “/” menu">
              <p>
                Typing <kbd>/</kbd> in the body opens the block menu: headings, bulleted and numbered lists,
                checklists, quotes, callouts, code, tables, dividers, images, today’s date and a link to another
                note. Keep typing to filter it, <kbd>↑</kbd> <kbd>↓</kbd> to move, <kbd>↵</kbd> to insert.
              </p>
              <p>
                Anything this deployment has switched off is <em>not in the menu</em> rather than greyed out, so
                what you see there is what will work.
              </p>
              <p>
                <kbd>@</kbd> does the same for people, when the note is shared with someone to mention.
              </p>
            </Explainer>

            <Explainer icon="tag" title="Tags">
              <p>
                Tags are added from the tag field on a note, with or without the <code>#</code> — typing{' '}
                <code>#gst</code> and typing <code>gst</code> reach the same tag. They are case-insensitive, so
                “GST” and “gst” are one tag and not two, and every tag you use appears in the sidebar as a way
                back to the notes carrying it.
              </p>
              <p>A note can carry many tags, and a tag is a label rather than a place: nothing moves when you add one.</p>
            </Explainer>

            <Explainer icon="cloud-off" title="Working offline">
              <p>
                Every note you open or list is mirrored onto this device, so the app still opens and still shows
                your library with no connection. Notes you have actually opened can be read and edited; ones you
                have only seen in a list will need a connection.
              </p>
              <p>
                Changes made offline are queued and sent when you reconnect — the header shows how many are
                waiting. If someone else changed the same note while you were away, nothing is{' '}
                <strong>merged silently</strong>: a banner asks which copy to keep, and keeping yours saves it
                as a new note rather than overwriting theirs, so neither version is thrown away. Deleting a note
                for good is the one thing that never happens offline, because it cannot be taken back.
              </p>
              <p>
                What is stored on this device, and a way to clear it, is on the <Link to="/settings">Settings</Link>{' '}
                page.
              </p>
            </Explainer>

            <Explainer icon="share" title="Sharing, and what each role can do">
              <p>Sharing a note grants one of three roles. Ownership is not one of them — it cannot be given away by sharing.</p>
              <dl className="set-roles">
                <div>
                  <dt>Viewer</dt>
                  <dd>Can read the note and its attachments. Cannot change anything.</dd>
                </div>
                <div>
                  <dt>Commenter</dt>
                  <dd>Everything a viewer can do, plus leaving and resolving comments.</dd>
                </div>
                <div>
                  <dt>Editor</dt>
                  <dd>Everything a commenter can do, plus editing the note itself.</dd>
                </div>
                <div>
                  <dt>Owner</dt>
                  <dd>
                    The person who created it. Only the owner can share it, change who has access, and move it to
                    the trash or restore it.
                  </dd>
                </div>
              </dl>
              <p className="set-hint">
                Reminders are personal: two people sharing a note each set their own, and neither can see the
                other’s.
              </p>
            </Explainer>

            <Explainer icon="history" title="Earlier versions of a note">
              <p>
                Notes keeps earlier versions of a note as you edit it. Open the note, then{' '}
                <strong>Show note info</strong> in the header: the panel lists the versions with when each was
                saved, alongside the note’s links, its backlinks and what has happened to it.
              </p>
              <p>
                Versions are written as you write, so there is nothing to remember to do. A note in the trash
                keeps its history; deleting one for good takes the history with it, along with its attachments
                and comments.
              </p>
            </Explainer>

            {/* Somebody looking for Pulse should find out here whether it is
                missing or simply switched off, rather than assuming they have
                not found the button yet. */}
            <Explainer icon="pulse" title="Pulse">
              <p>{describeFeature('ai', pulseEnabled)}</p>
              {pulseEnabled ? (
                <p>
                  Ask a question of one note, one notebook, or everything you can read. Every answer lists the
                  notes it was drawn from; when Pulse answers without finding anything of yours, it says so above
                  the answer instead of presenting it as sourced.
                </p>
              ) : (
                <p className="set-hint">
                  There is nothing to switch on here — it is a server setting. The full list of what this
                  deployment has enabled is on the <Link to="/settings">Settings</Link> page.
                </p>
              )}
            </Explainer>
          </div>
        </section>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Pieces
// ---------------------------------------------------------------------------

function ShortcutTable({ caption, rows }: { caption: string; rows: Shortcut[] }) {
  return (
    <table className="set-keys">
      <caption className="set-keys__caption">{caption}</caption>
      <tbody>
        {rows.map((row) => (
          <tr key={row.what + row.keys.join('')}>
            <th scope="row" className="set-keys__combo">
              {row.keys.map((key, index) => (
                <span key={key}>
                  {index > 0 ? <span className="set-keys__plus" aria-hidden> + </span> : null}
                  <kbd>{key}</kbd>
                </span>
              ))}
            </th>
            <td className="set-keys__what">
              {row.what}
              {row.note ? <span className="set-keys__note">{row.note}</span> : null}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function Explainer({
  icon,
  title,
  children,
}: {
  icon: IconName
  title: string
  children: ReactNode
}) {
  return (
    <article className="set-explainer">
      <h3 className="set-explainer__title">
        <span className="set-explainer__icon" aria-hidden>
          <Icon name={icon} size={15} />
        </span>
        {title}
      </h3>
      <div className="set-explainer__body">{children}</div>
    </article>
  )
}
