/**
 * Who else can open this note.
 *
 * The rules this dialog is built around are the server's, not the designer's:
 *
 *   - **Ownership is not on offer.** The picker holds three roles, and there is
 *     no fourth hiding behind a menu. Handing a note over is a different act
 *     with a different confirmation, and it is not this one.
 *   - **Only the owner changes sharing.** Everyone else gets the member list
 *     read-only — being able to see who else is in a note is what lets a
 *     collaborator judge what is safe to write in it.
 *   - **A private note cannot be shared at all.** The server holds ciphertext
 *     it cannot read, so a grant would put a name on screen that opens
 *     nothing. The form is replaced by the reason rather than failing on
 *     submit.
 *
 * People are identified by their AICOUNTLY account id or the address they sign
 * in with. There is no directory endpoint to search, so the field says what it
 * wants instead of pretending to autocomplete a name.
 */

import { useId, useState } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, Dialog, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { ApiError } from '../../../shared/api/client'
import { useAuth } from '../../../auth/AuthProvider'
import { ErrorNotice } from '../../organise/ErrorNotice'
import type { Note } from '../../../shared/api/types'
import {
  GRANTABLE_ROLES,
  ROLE_HINT,
  ROLE_LABEL,
  memberLabel,
  useAddNoteMember,
  useNoteMembers,
  useRemoveNoteMember,
  useUpdateNoteMemberRole,
} from '../hooks/useMembers'
import type { GrantableRole, NoteMemberRow } from '../hooks/useMembers'
import '../../organise/organise.css'
import '../collaboration.css'

export interface ShareDialogProps {
  note: Note
  open: boolean
  onClose: () => void
}

export function ShareDialog({ note, open, onClose }: ShareDialogProps) {
  const { profile } = useAuth()
  const members = useNoteMembers(open ? note.id : null)
  const addMember = useAddNoteMember()
  const changeRole = useUpdateNoteMemberRole()
  const removeMember = useRemoveNoteMember()

  const personId = useId()
  const roleId = useId()
  const rolesId = useId()

  const [person, setPerson] = useState('')
  const [role, setRole] = useState<GrantableRole>('viewer')
  const [error, setError] = useState<unknown>(null)
  const [copyProblem, setCopyProblem] = useState<string | null>(null)
  const [status, setStatus] = useState('')

  const canManage = note.capabilities.manage_members
  const isPrivate = note.privacy_mode === 'private'
  const busy = addMember.isPending || changeRole.isPending || removeMember.isPending
  const fieldErrors = error instanceof ApiError ? error.fieldErrors : {}

  // The address of the note itself, not of whatever list it was opened from,
  // so a pasted link opens the note for everyone who has been given access.
  const url = `${window.location.origin}/notes/${note.id}`

  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(url)
      setCopyProblem(null)
      setStatus('Link copied')
    } catch {
      // Clipboard access is a permission, not a certainty. Saying so — and
      // leaving the address on screen to select — beats a button that looks
      // like it worked.
      setCopyProblem('This browser would not let the page copy. The address above can be selected and copied by hand.')
    }
  }

  const invite = () => {
    const trimmed = person.trim()
    if (trimmed === '' || busy) return

    setError(null)
    addMember
      .mutateAsync({ noteId: note.id, user_id: trimmed, role })
      .then((member) => {
        setPerson('')
        setStatus(`${memberLabel(member)} can now ${ROLE_LABEL[member.role].toLowerCase()}`)
      })
      .catch(setError)
  }

  const changeMemberRole = (member: NoteMemberRow, next: GrantableRole) => {
    setError(null)
    changeRole
      .mutateAsync({ noteId: note.id, userId: member.user_id, role: next })
      .then(() => setStatus(`${memberLabel(member)}: ${ROLE_LABEL[next]}`))
      .catch(setError)
  }

  const remove = (member: NoteMemberRow) => {
    setError(null)
    removeMember
      .mutateAsync({ noteId: note.id, userId: member.user_id })
      .then(() => setStatus(`${memberLabel(member)} no longer has access`))
      .catch(setError)
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={`Share “${note.display_title}”`}
      description="Everyone here sees the note as it changes, including anything you write in it later."
      width={560}
      footer={
        <Button variant="primary" onClick={onClose} disabled={busy}>
          Done
        </Button>
      }
    >
      <div className="org-form">
        {/* A failed write is dismissible: there is nothing to retry — the form
            below is the retry — and without this the sentence sits at the top
            of the dialog for as long as it stays open. */}
        {error ? <ErrorNotice error={error} onDismiss={() => setError(null)} /> : null}

        <div className="collab-link-row">
          <Icon name="link" size={15} />
          <span className="collab-link-row__url">{url}</span>
          <Button size="sm" icon="copy" onClick={() => void copyLink()}>
            Copy link
          </Button>
        </div>
        {copyProblem ? <p className="org-error">{copyProblem}</p> : null}
        <p className="collab-note collab-note--muted">
          The link only opens for people on this list. Sharing the address does not grant access.
        </p>

        {isPrivate ? (
          <p className="collab-note">
            This note is private. It is stored so that the server cannot read it, which means it can
            only ever be opened by you — sharing is not available for private notes.
          </p>
        ) : canManage ? (
          <form
            className="collab-share-form"
            onSubmit={(event) => {
              event.preventDefault()
              invite()
            }}
          >
            <div className="org-field">
              <label className="org-label" htmlFor={personId}>
                AICOUNTLY account id or email
              </label>
              <input
                id={personId}
                className="org-input"
                value={person}
                autoComplete="off"
                // Only the invite's own request disables the field. Disabling
                // it for a role change elsewhere in the dialog would blur it
                // mid-address, because a browser blurs what it disables.
                disabled={addMember.isPending}
                data-autofocus=""
                aria-invalid={fieldErrors.user_id ? true : undefined}
                aria-describedby={fieldErrors.user_id ? `${personId}-error` : undefined}
                onChange={(event) => setPerson(event.target.value)}
              />
              {fieldErrors.user_id ? (
                <p className="org-error" id={`${personId}-error`}>
                  {fieldErrors.user_id}
                </p>
              ) : null}
            </div>

            <div className="org-field">
              <label className="org-label" htmlFor={roleId}>
                Access
              </label>
              <select
                id={roleId}
                className="org-select"
                value={role}
                disabled={addMember.isPending}
                aria-describedby={rolesId}
                onChange={(event) => setRole(event.target.value as GrantableRole)}
              >
                {GRANTABLE_ROLES.map((value) => (
                  <option key={value} value={value}>
                    {ROLE_LABEL[value]}
                  </option>
                ))}
              </select>
              <ul className="collab-roles" id={rolesId}>
                {GRANTABLE_ROLES.map((value) => (
                  <li key={value}>
                    <b>{ROLE_LABEL[value]}</b> — {ROLE_HINT[value]}
                  </li>
                ))}
              </ul>
            </div>

            {/* `busy` as well as the field: invite() refuses to run while
                another sharing write is in flight, and a button that is
                clickable while the click does nothing is the same bug as one
                wired to nothing at all. */}
            <Button
              type="submit"
              variant="primary"
              icon="plus"
              loading={addMember.isPending}
              disabled={person.trim() === '' || busy}
            >
              Share
            </Button>
          </form>
        ) : (
          <p className="collab-note">
            Only the owner can change who this note is shared with. You can see who has access below.
          </p>
        )}

        <section className="collab-section">
          <h3 className="collab-section__title">
            People with access
            {members.data ? ` (${members.data.length})` : ''}
          </h3>

          {members.isPending ? (
            <div className="collab-skeletons" aria-hidden>
              {[0, 1].map((row) => (
                <div className="collab-skeleton-row" key={row}>
                  <Skeleton width="45%" height={13} />
                  <Skeleton width="30%" height={11} />
                </div>
              ))}
            </div>
          ) : members.isError ? (
            <ErrorNotice error={members.error} onRetry={() => void members.refetch()} />
          ) : (members.data?.length ?? 0) === 0 ? (
            <p className="org-empty">Only you can open this note.</p>
          ) : (
            <ul className="collab-members">
              {(members.data ?? []).map((member) => {
                const isOwner = member.role === 'owner'
                const isYou = profile !== null && profile.user_id === member.user_id
                const name = memberLabel(member)

                return (
                  <li className="collab-member" key={member.user_id}>
                    <Icon name="user" size={15} />
                    <span className="collab-member__body">
                      <span className="collab-member__name">
                        {name}
                        {isYou ? ' (you)' : ''}
                      </span>
                      <span className="collab-member__meta">
                        {isOwner ? 'Owns this note' : ROLE_HINT[member.role]}
                      </span>
                    </span>

                    {isOwner || !canManage ? (
                      <Badge tone={isOwner ? 'primary' : 'neutral'}>{ROLE_LABEL[member.role]}</Badge>
                    ) : (
                      <>
                        <select
                          className="org-select collab-member__role"
                          value={member.role}
                          disabled={busy}
                          aria-label={`Access for ${name}`}
                          onChange={(event) =>
                            changeMemberRole(member, event.target.value as GrantableRole)
                          }
                        >
                          {GRANTABLE_ROLES.map((value) => (
                            <option key={value} value={value}>
                              {ROLE_LABEL[value]}
                            </option>
                          ))}
                        </select>
                        <Button
                          icon="close"
                          iconOnly
                          size="sm"
                          variant="ghost"
                          aria-label={`Remove ${name}`}
                          disabled={busy}
                          onClick={() => remove(member)}
                        />
                      </>
                    )}
                  </li>
                )
              })}
            </ul>
          )}

          {canManage && !isPrivate ? (
            <p className="collab-note collab-note--muted">
              Removing someone takes effect immediately. Ownership stays with the owner — it cannot
              be passed on from here.
            </p>
          ) : null}
        </section>
      </div>

      <LiveStatus>{status}</LiveStatus>
    </Dialog>
  )
}
