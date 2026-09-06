/**
 * Smart folders in the sidebar.
 *
 * Flat, not a tree: a saved query has nothing to nest. Each row carries the
 * live number of notes it matches, which is the only thing that tells you a
 * folder is still doing its job — a rule that quietly stopped matching looks
 * exactly like a rule that never matched.
 *
 * Two states are worth naming because they are not errors:
 *
 *   - **Capped.** The server stops counting at 500 so the sidebar does not get
 *     slower as a library grows, and says so; the row shows "500+".
 *   - **No longer valid.** A folder saved against a rule vocabulary this
 *     release has dropped comes back flagged rather than breaking the list.
 *     Its row says so and offers the editor, which is the only place it can be
 *     fixed.
 */

import { useId, useState } from 'react'
import { NavLink } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, Dialog, EmptyState, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { RowMenu } from '../../organise/RowMenu'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { colorDotClass, iconOrDefault } from '../../organise/appearance'
import {
  MAX_SMART_FOLDERS,
  formatMatchCount,
  useCreateSmartFolder,
  useDeleteSmartFolder,
  useSmartFolders,
} from '../hooks/useSmartFolders'
import type { SmartFolderNode } from '../hooks/useSmartFolders'
import { SmartFolderDialog } from './SmartFolderDialog'
import '../smart-folders.css'

type ListDialog =
  | { kind: 'create' }
  | { kind: 'edit'; folder: SmartFolderNode }
  | { kind: 'delete'; folder: SmartFolderNode }

export function SmartFolderList({ onNavigate }: { onNavigate?: () => void }) {
  const folders = useSmartFolders()
  const duplicate = useCreateSmartFolder()
  const headingId = useId()

  const [dialog, setDialog] = useState<ListDialog | null>(null)
  const [status, setStatus] = useState<string | null>(null)
  const [actionError, setActionError] = useState<unknown>(null)

  const all = folders.data ?? []
  const full = all.length >= MAX_SMART_FOLDERS

  return (
    <section className="nav-section" aria-labelledby={headingId}>
      <div className="nav-section__header">
        <h2 className="nav-section__title" id={headingId}>
          Smart folders
        </h2>
        <Button
          icon="plus"
          iconOnly
          size="sm"
          variant="ghost"
          aria-label="New smart folder"
          title={full ? `You can keep ${MAX_SMART_FOLDERS} smart folders.` : 'New smart folder'}
          disabled={full}
          onClick={() => setDialog({ kind: 'create' })}
        />
      </div>

      {actionError ? <ErrorNotice error={actionError} /> : null}

      {folders.isPending ? (
        <div className="org-skeletons" aria-hidden>
          {[0, 1].map((row) => (
            <div className="org-skeleton-row" key={row}>
              <Skeleton width={16} height={16} radius={4} />
              <Skeleton width={`${62 - row * 10}%`} height={12} />
            </div>
          ))}
        </div>
      ) : folders.isError ? (
        <ErrorNotice error={folders.error} onRetry={() => void folders.refetch()} />
      ) : all.length === 0 ? (
        <EmptyState
          icon="sparkle-folder"
          title="No smart folders"
          description="A saved search that stays up to date — “tagged gst, updated this month”."
          action={
            <Button icon="plus" variant="primary" size="sm" onClick={() => setDialog({ kind: 'create' })}>
              New smart folder
            </Button>
          }
        />
      ) : (
        <ul className="sf-list">
          {all.map((folder) => {
            const count = formatMatchCount(folder)
            const broken = folder.rules_valid === false

            return (
              <li key={folder.id}>
                <div className="org-row">
                  <NavLink
                    to={`/smart-folders/${folder.id}`}
                    onClick={onNavigate}
                    className={({ isActive }) => `nav-item ${isActive ? 'nav-item--active' : ''}`}
                  >
                    <span className="sf-row__name">
                      <span className={`org-dot ${colorDotClass(folder.color)}`.trim()} aria-hidden />
                      <Icon name={iconOrDefault(folder.icon, 'sparkle-folder')} size={16} />
                      <span className="sf-row__label">{folder.name}</span>
                      {broken ? <Badge tone="warning">Check rules</Badge> : null}
                    </span>
                    {count !== null ? (
                      <span
                        className="nav-item__count"
                        title={folder.note_count_is_capped ? 'More than 500 notes match' : undefined}
                      >
                        {count}
                      </span>
                    ) : null}
                  </NavLink>

                  <span className="org-row__menu">
                    <RowMenu
                      label={`Actions for ${folder.name}`}
                      busy={duplicate.isPending}
                      items={[
                        {
                          key: 'edit',
                          label: 'Edit rules…',
                          icon: 'settings',
                          onSelect: () => setDialog({ kind: 'edit', folder }),
                        },
                        {
                          key: 'duplicate',
                          label: 'Duplicate',
                          icon: 'copy',
                          disabledReason: full ? 'At the limit' : undefined,
                          onSelect: () => {
                            setActionError(null)
                            duplicate
                              .mutateAsync({
                                name: `${folder.name} (copy)`,
                                rules: folder.rules,
                                icon: folder.icon,
                                color: folder.color,
                              })
                              .then((made) => setStatus(`${made.name} created`))
                              .catch(setActionError)
                          },
                        },
                        {
                          key: 'delete',
                          label: 'Delete folder…',
                          icon: 'trash',
                          danger: true,
                          separated: true,
                          onSelect: () => setDialog({ kind: 'delete', folder }),
                        },
                      ]}
                    />
                  </span>
                </div>
              </li>
            )
          })}
        </ul>
      )}

      {dialog?.kind === 'create' || dialog?.kind === 'edit' ? (
        <SmartFolderDialog
          open
          folder={dialog.kind === 'edit' ? dialog.folder : null}
          onClose={() => setDialog(null)}
          onSaved={(saved, wasCreated) => setStatus(wasCreated ? `${saved.name} created` : `${saved.name} saved`)}
        />
      ) : null}

      {dialog?.kind === 'delete' ? (
        <DeleteSmartFolderDialog
          folder={dialog.folder}
          onClose={() => setDialog(null)}
          onDeleted={(name) => {
            setStatus(`${name} deleted`)
            setDialog(null)
          }}
        />
      ) : null}

      <LiveStatus>{status}</LiveStatus>
    </section>
  )
}

function DeleteSmartFolderDialog({
  folder,
  onClose,
  onDeleted,
}: {
  folder: SmartFolderNode
  onClose: () => void
  onDeleted: (name: string) => void
}) {
  const remove = useDeleteSmartFolder()
  const [error, setError] = useState<unknown>(null)

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Delete “${folder.name}”?`}
      // Worth saying plainly: the thing that looks most like a folder full of
      // notes is the one thing here that holds none.
      description="A smart folder is a saved search. Deleting it does not delete any notes."
      footer={
        <>
          <Button onClick={onClose} disabled={remove.isPending}>
            Cancel
          </Button>
          <Button
            variant="danger"
            icon="trash"
            loading={remove.isPending}
            onClick={() => {
              setError(null)
              remove
                .mutateAsync(folder.id)
                .then(() => onDeleted(folder.name))
                .catch(setError)
            }}
          >
            Delete folder
          </Button>
        </>
      }
    >
      {error ? <ErrorNotice error={error} /> : <p className="org-empty">The rules go; the notes stay where they are.</p>}
    </Dialog>
  )
}
