/**
 * The discussion beside the note.
 *
 * Two kinds of thread live in one panel: comments about a particular block of
 * text, and comments about the note as a whole. They are separated rather than
 * interleaved, because "is this about the paragraph I am reading?" is the first
 * question a reader has and sorting by time does not answer it.
 *
 * The case worth designing for is the awkward one. When the block a comment was
 * anchored to has been edited away, the comment is still shown — flagged, with
 * the text it was written against — because dropping it would delete one
 * person's words on account of another person rewriting a paragraph.
 *
 * A viewer sees all of this and can add none of it. Nothing is hidden from
 * them; the controls they cannot use are simply absent, with the reason said
 * once at the bottom instead of thirty times as a disabled button.
 */

import { useId, useRef, useState } from 'react'
import type { RefObject } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import { Badge, Button, Dialog, EmptyState, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { formatAbsoluteTime, formatRelativeTime } from '../../notes/components/NoteCard'
import { useMemberNames } from '../hooks/useMembers'
import {
  useCreateComment,
  useDeleteComment,
  useNoteComments,
  useResolveComment,
} from '../hooks/useComments'
import type { CommentThread } from '../hooks/useComments'
import type { Note } from '../../../shared/api/types'
import '../../organise/organise.css'
import '../collaboration.css'

type Filter = 'open' | 'resolved' | 'all'

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'open', label: 'Open' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'all', label: 'All' },
]

export interface CommentSidebarProps {
  note: Note
  onClose: () => void
  /** The block the caret is in, if the editor says. Its thread is marked. */
  activeBlockId?: string | null
  /** When given, an anchored thread offers to take the reader to its text. */
  onSelectBlock?: (blockId: string) => void
}

export function CommentSidebar({ note, onClose, activeBlockId = null, onSelectBlock }: CommentSidebarProps) {
  const comments = useNoteComments(note.id)
  const nameFor = useMemberNames(note.id)
  const create = useCreateComment()
  const resolve = useResolveComment()
  const remove = useDeleteComment()

  const composerId = useId()
  const composerRef = useRef<HTMLTextAreaElement>(null)

  const [filter, setFilter] = useState<Filter>('open')
  const [draft, setDraft] = useState('')
  const [replyTo, setReplyTo] = useState<string | null>(null)
  const [deleting, setDeleting] = useState<CommentThread | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [status, setStatus] = useState('')

  const mayComment = note.capabilities.comment
  const busy = create.isPending || resolve.isPending || remove.isPending

  const threads = comments.data?.threads ?? []
  const visible = threads.filter((thread) =>
    filter === 'all' ? true : filter === 'open' ? !thread.is_resolved : thread.is_resolved,
  )
  const onNote = visible.filter((thread) => thread.block_id === null)
  const onText = visible.filter((thread) => thread.block_id !== null)

  const post = (body: string, parentId: string | null) => {
    const trimmed = body.trim()
    if (trimmed === '' || busy) return

    setError(null)
    create
      .mutateAsync({ noteId: note.id, body: trimmed, parentId })
      .then(() => {
        if (parentId === null) setDraft('')
        setReplyTo(null)
        setStatus(parentId === null ? 'Comment added' : 'Reply added')
      })
      .catch(setError)
  }

  const setResolved = (thread: CommentThread, resolved: boolean) => {
    setError(null)
    resolve
      .mutateAsync({ noteId: note.id, commentId: thread.id, resolved })
      .then(() => setStatus(resolved ? 'Thread resolved' : 'Thread reopened'))
      .catch(setError)
  }

  const confirmDelete = () => {
    if (!deleting) return
    const target = deleting

    setError(null)
    remove
      .mutateAsync({ noteId: note.id, commentId: target.id })
      .then(() => setStatus('Comment deleted'))
      .catch(setError)
      .finally(() => setDeleting(null))
  }

  const renderThread = (thread: CommentThread) => {
    const blockId = thread.block_id
    const orphaned = thread.orphaned === true
    const isActive = blockId !== null && blockId === activeBlockId

    return (
      <li
        key={thread.id}
        className={[
          'collab-thread',
          thread.is_resolved ? 'collab-thread--resolved' : '',
          isActive ? 'collab-thread--active' : '',
        ]
          .filter(Boolean)
          .join(' ')}
      >
        {orphaned ? (
          <p className="collab-thread__orphan">
            <Icon name="alert" size={13} />
            The original text is no longer in this note.
          </p>
        ) : null}

        {thread.anchor_text ? (
          <blockquote className="collab-thread__anchor">{thread.anchor_text}</blockquote>
        ) : null}

        <Comment comment={thread} nameFor={nameFor} />

        {thread.replies.length > 0 ? (
          <ul className="collab-replies">
            {thread.replies.map((reply) => (
              <li key={reply.id}>
                <Comment comment={reply} nameFor={nameFor} />
                {reply.capabilities.delete ? (
                  <div className="collab-actions">
                    <Button size="sm" variant="ghost" onClick={() => setDeleting(reply)} disabled={busy}>
                      Delete
                    </Button>
                  </div>
                ) : null}
              </li>
            ))}
          </ul>
        ) : null}

        <div className="collab-actions">
          {thread.capabilities.reply && replyTo !== thread.id ? (
            <Button size="sm" variant="ghost" icon="comment" onClick={() => setReplyTo(thread.id)}>
              Reply
            </Button>
          ) : null}

          {thread.capabilities.resolve ? (
            <Button
              size="sm"
              variant="ghost"
              icon={thread.is_resolved ? 'undo' : 'check'}
              disabled={busy}
              onClick={() => setResolved(thread, !thread.is_resolved)}
            >
              {thread.is_resolved ? 'Reopen' : 'Resolve'}
            </Button>
          ) : null}

          {/* Only offered when the editor gave us somewhere to go, and never
              for an anchor the document no longer contains. */}
          {onSelectBlock && blockId !== null && !orphaned ? (
            <Button
              size="sm"
              variant="ghost"
              icon="chevron-left"
              onClick={() => onSelectBlock(blockId)}
            >
              Show in note
            </Button>
          ) : null}

          {thread.capabilities.delete ? (
            <Button size="sm" variant="ghost" icon="trash" onClick={() => setDeleting(thread)} disabled={busy}>
              Delete
            </Button>
          ) : null}
        </div>

        {replyTo === thread.id ? (
          <Composer
            className="collab-composer collab-composer--reply"
            label={`Reply to ${nameFor(thread.author_user_id)}`}
            submitLabel="Reply"
            pending={create.isPending}
            onCancel={() => setReplyTo(null)}
            onSubmit={(body) => post(body, thread.id)}
          />
        ) : null}
      </li>
    )
  }

  return (
    <aside className="side-panel" aria-label="Comments">
      <header className="side-panel__header">
        <Icon name="comment" size={16} />
        <h2 className="side-panel__title">Comments</h2>
        {comments.data && comments.data.unresolved > 0 ? (
          <Badge tone="primary">{comments.data.unresolved} open</Badge>
        ) : null}
        <Button icon="close" iconOnly variant="ghost" size="sm" aria-label="Close comments" onClick={onClose} />
      </header>

      <div className="side-panel__body">
        {error ? <ErrorNotice error={error} /> : null}

        {comments.isPending ? (
          <div className="collab-skeletons" aria-hidden>
            {[0, 1, 2].map((row) => (
              <div className="collab-skeleton-row" key={row}>
                <Skeleton width="40%" height={12} />
                <Skeleton width="100%" height={32} radius={12} />
              </div>
            ))}
          </div>
        ) : comments.isError ? (
          <ErrorNotice error={comments.error} onRetry={() => void comments.refetch()} />
        ) : threads.length === 0 ? (
          <EmptyState
            icon="comment"
            title="No comments yet"
            description={
              mayComment
                ? 'Comment on the whole note here, or select text in the note and comment on that.'
                : 'Nobody has commented on this note.'
            }
            action={
              mayComment ? (
                <Button icon="plus" onClick={() => composerRef.current?.focus()}>
                  Add a comment
                </Button>
              ) : undefined
            }
          />
        ) : (
          <>
            <div className="collab-panel__head">
              <div className="collab-filter" role="group" aria-label="Which comments to show">
                {FILTERS.map((option) => (
                  <button
                    key={option.value}
                    type="button"
                    className="collab-filter__option"
                    aria-pressed={filter === option.value}
                    onClick={() => setFilter(option.value)}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            </div>

            {visible.length === 0 ? (
              <p className="org-empty">
                {filter === 'open'
                  ? 'Every thread on this note has been resolved.'
                  : 'No resolved threads yet.'}
              </p>
            ) : null}

            {onText.length > 0 ? (
              <section className="collab-section">
                <h3 className="collab-section__title">On the text</h3>
                <ul className="collab-threads">{onText.map(renderThread)}</ul>
              </section>
            ) : null}

            {onNote.length > 0 ? (
              <section className="collab-section">
                <h3 className="collab-section__title">On the whole note</h3>
                <ul className="collab-threads">{onNote.map(renderThread)}</ul>
              </section>
            ) : null}

            {comments.data?.hasMore ? (
              <p className="collab-note collab-note--muted">
                This note has more comments than one panel can hold. The oldest are shown.
              </p>
            ) : null}
          </>
        )}
      </div>

      <div className="collab-panel__footer">
        {mayComment ? (
          <Composer
            id={composerId}
            textareaRef={composerRef}
            className="collab-composer"
            label="Add a comment"
            submitLabel="Comment"
            value={draft}
            pending={create.isPending}
            onChange={setDraft}
            onSubmit={(body) => post(body, null)}
          />
        ) : (
          <p className="collab-note">
            You have view-only access to this note, so you can read the discussion but not add to it.
          </p>
        )}
      </div>

      <Dialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Delete this comment?"
        description={
          deleting && deleting.replies.length > 0
            ? `Its ${deleting.replies.length} ${deleting.replies.length === 1 ? 'reply goes' : 'replies go'} with it. This cannot be undone.`
            : 'This cannot be undone.'
        }
        width={420}
        footer={
          <>
            <Button onClick={() => setDeleting(null)}>Keep it</Button>
            <Button variant="danger" icon="trash" loading={remove.isPending} onClick={confirmDelete}>
              Delete
            </Button>
          </>
        }
      >
        <p className="collab-note">{deleting?.body}</p>
      </Dialog>

      <LiveStatus>{status}</LiveStatus>
    </aside>
  )
}

// ---------------------------------------------------------------------------

function Comment({
  comment,
  nameFor,
}: {
  comment: CommentThread
  nameFor: (userId: string) => string
}) {
  return (
    <article className="collab-comment">
      <header className="collab-comment__head">
        <span className="collab-comment__author">{nameFor(comment.author_user_id)}</span>
        {comment.edited ? <span className="collab-comment__edited">edited</span> : null}
        {comment.is_resolved ? <Badge>Resolved</Badge> : null}
        <time
          className="collab-comment__time"
          dateTime={comment.created_at}
          title={formatAbsoluteTime(comment.created_at)}
        >
          {formatRelativeTime(comment.created_at)}
        </time>
      </header>
      <p className="collab-comment__body">{comment.body}</p>
    </article>
  )
}

/**
 * The one text box.
 *
 * Controlled by the parent where the draft has to survive a re-render (the
 * main composer) and self-contained where it does not (a reply, which is
 * thrown away when the thread closes).
 */
function Composer({
  id,
  className,
  label,
  submitLabel,
  value,
  pending,
  textareaRef,
  onChange,
  onCancel,
  onSubmit,
}: {
  id?: string
  className: string
  label: string
  submitLabel: string
  value?: string
  pending: boolean
  textareaRef?: RefObject<HTMLTextAreaElement | null>
  onChange?: (value: string) => void
  onCancel?: () => void
  onSubmit: (value: string) => void
}) {
  const generatedId = useId()
  const fieldId = id ?? generatedId
  const [internal, setInternal] = useState('')

  const text = value ?? internal
  const setText = onChange ?? setInternal

  // Never cleared here. A failed post keeps what was typed; a successful one
  // is cleared by the caller, which is the only side that knows it succeeded.
  const submit = () => {
    if (text.trim() !== '' && !pending) onSubmit(text)
  }

  return (
    <form
      className={className}
      onSubmit={(event) => {
        event.preventDefault()
        submit()
      }}
    >
      <label className="org-label" htmlFor={fieldId}>
        {label}
      </label>
      <textarea
        id={fieldId}
        ref={textareaRef}
        className="org-textarea"
        rows={3}
        value={text}
        disabled={pending}
        autoFocus={onCancel !== undefined}
        onChange={(event) => setText(event.target.value)}
        onKeyDown={(event) => {
          // The shortcut people expect from every other comment box.
          if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault()
            submit()
          }
        }}
      />
      <div className="collab-actions collab-actions--end">
        {onCancel ? (
          <Button size="sm" onClick={onCancel} disabled={pending}>
            Cancel
          </Button>
        ) : null}
        <Button
          type="submit"
          size="sm"
          variant="primary"
          loading={pending}
          disabled={text.trim() === ''}
        >
          {submitLabel}
        </Button>
      </div>
    </form>
  )
}
