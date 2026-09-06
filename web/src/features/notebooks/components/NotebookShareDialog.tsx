/**
 * Who else can open this notebook.
 *
 * Sharing a notebook cascades to everything inside it — its sub-notebooks and
 * their notes — so the dialog says that outright rather than leaving it to be
 * discovered. Only the owner can change it, which is why the whole form is
 * absent for anyone else instead of failing on submit.
 *
 * People are identified by their AICOUNTLY account id. There is no directory
 * endpoint to search, so the field asks for the id and says so; inventing a
 * name box that cannot resolve a name would be worse than being plain.
 */

import { useId, useState } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, Dialog, Skeleton } from '../../../shared/ui/primitives'
import type { NoteRole } from '../../../shared/api/types'
import { ErrorNotice } from '../../organise/ErrorNotice'
import {
  notebookCapabilities,
  useAddNotebookMember,
  useNotebookMembers,
  useRemoveNotebookMember,
} from '../hooks/useNotebooks'
import type { NotebookNode } from '../hooks/useNotebooks'
import '../notebooks.css'

/** The roles a share may grant. `owner` is absent: sharing never hands it over. */
const GRANTABLE: { value: NoteRole; label: string }[] = [
  { value: 'viewer', label: 'Can view' },
  { value: 'commenter', label: 'Can comment' },
  { value: 'editor', label: 'Can edit' },
]

const ROLE_LABEL: Record<NoteRole, string> = {
  owner: 'Owner',
  editor: 'Can edit',
  commenter: 'Can comment',
  viewer: 'Can view',
}

export function NotebookShareDialog({
  notebook,
  open,
  onClose,
}: {
  notebook: NotebookNode
  open: boolean
  onClose: () => void
}) {
  const members = useNotebookMembers(open ? notebook.id : null)
  const addMember = useAddNotebookMember()
  const removeMember = useRemoveNotebookMember()
  const userId = useId()
  const roleId = useId()

  const [person, setPerson] = useState('')
  const [role, setRole] = useState<NoteRole>('viewer')
  const [error, setError] = useState<unknown>(null)

  const canManage = notebookCapabilities(notebook).manage_members
  const busy = addMember.isPending || removeMember.isPending

  const invite = () => {
    const trimmed = person.trim()
    if (trimmed === '' || busy) return

    setError(null)
    addMember
      .mutateAsync({ id: notebook.id, user_id: trimmed, role })
      .then(() => {
        setPerson('')
        void members.refetch()
      })
      .catch(setError)
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={`Share ${notebook.name}`}
      description="Everyone here can also open the notebooks and notes inside this one."
      width={520}
      footer={
        <Button onClick={onClose} disabled={busy}>
          Done
        </Button>
      }
    >
      <div className="org-form">
        {error ? <ErrorNotice error={error} /> : null}

        {canManage ? (
          <form
            className="nb-share-form"
            onSubmit={(event) => {
              event.preventDefault()
              invite()
            }}
          >
            <div className="org-field">
              <label className="org-label" htmlFor={userId}>
                AICOUNTLY account id
              </label>
              <input
                id={userId}
                className="org-input"
                value={person}
                autoComplete="off"
                disabled={busy}
                data-autofocus=""
                onChange={(event) => setPerson(event.target.value)}
              />
            </div>

            <div className="org-field">
              <label className="org-label" htmlFor={roleId}>
                Access
              </label>
              <select
                id={roleId}
                className="org-select"
                value={role}
                disabled={busy}
                onChange={(event) => setRole(event.target.value as NoteRole)}
              >
                {GRANTABLE.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </div>

            <Button
              type="submit"
              variant="primary"
              icon="plus"
              loading={addMember.isPending}
              disabled={person.trim() === ''}
            >
              Share
            </Button>
          </form>
        ) : (
          <p className="org-empty">Only the owner of this notebook can change who it is shared with.</p>
        )}

        {members.isPending ? (
          <div className="org-skeletons" aria-hidden>
            {[0, 1].map((row) => (
              <div className="org-skeleton-row" key={row}>
                <Skeleton width={18} height={18} radius={9} />
                <Skeleton width="55%" height={12} />
              </div>
            ))}
          </div>
        ) : members.isError ? (
          <ErrorNotice error={members.error} onRetry={() => void members.refetch()} />
        ) : (
          <ul className="nb-members">
            {(members.data ?? []).map((member) => (
              <li className="nb-member" key={member.user_id}>
                <Icon name="user" size={15} />
                <span className="nb-member__id">{member.display_name ?? member.email ?? member.user_id}</span>
                <Badge tone={member.role === 'owner' ? 'primary' : 'neutral'}>{ROLE_LABEL[member.role]}</Badge>
                {canManage && member.role !== 'owner' ? (
                  <Button
                    icon="close"
                    iconOnly
                    size="sm"
                    variant="ghost"
                    aria-label={`Remove ${member.user_id}`}
                    disabled={busy}
                    onClick={() => {
                      setError(null)
                      removeMember
                        .mutateAsync({ id: notebook.id, userId: member.user_id })
                        .then(() => void members.refetch())
                        .catch(setError)
                    }}
                  />
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </div>
    </Dialog>
  )
}
