/**
 * The tag rail in the sidebar.
 *
 * Tags are a wrapping rail of chips rather than a list of rows, because a
 * label is one short word, there are usually many, and a column of forty
 * one-word rows pushes the rest of the navigation off the screen. Only the
 * first handful are shown until asked; a sidebar that scrolls for tags is a
 * sidebar nobody scrolls.
 *
 * Managing tags happens in one dialog rather than a menu per chip: renaming,
 * recolouring and deleting are things you do to several tags in one sitting,
 * usually after importing a mess of them.
 */

import { useId, useState } from 'react'
import { NavLink } from 'react-router-dom'

import { Icon } from '../../../shared/ui/Icon'
import { Button, Dialog, EmptyState, LiveStatus, Skeleton } from '../../../shared/ui/primitives'
import type { Tag } from '../../../shared/api/types'
import { ErrorNotice } from '../../organise/ErrorNotice'
import { ColorField, colorDotClass } from '../../organise/appearance'
import { useCreateTag, useDeleteTag, useTags, useUpdateTag } from '../hooks/useTags'
import '../tags.css'

/** How many chips fit before the rail starts costing more than it gives. */
const COLLAPSED_LIMIT = 12

export function TagRail({ onNavigate }: { onNavigate?: () => void }) {
  const tags = useTags()
  const headingId = useId()

  const [expanded, setExpanded] = useState(false)
  const [managing, setManaging] = useState(false)
  const [status, setStatus] = useState<string | null>(null)

  const all = tags.data ?? []
  const shown = expanded ? all : all.slice(0, COLLAPSED_LIMIT)
  const hidden = all.length - shown.length

  return (
    <section className="nav-section" aria-labelledby={headingId}>
      <div className="nav-section__header">
        <h2 className="nav-section__title" id={headingId}>
          Tags
        </h2>
        {all.length > 0 ? (
          <Button
            icon="settings"
            iconOnly
            size="sm"
            variant="ghost"
            aria-label="Manage tags"
            title="Manage tags"
            onClick={() => setManaging(true)}
          />
        ) : null}
      </div>

      {tags.isPending ? (
        <div className="tag-rail" aria-hidden>
          {[64, 48, 72, 40].map((width, index) => (
            <Skeleton key={index} width={width} height={20} radius={999} />
          ))}
        </div>
      ) : tags.isError ? (
        <ErrorNotice error={tags.error} onRetry={() => void tags.refetch()} />
      ) : all.length === 0 ? (
        <EmptyState
          icon="tag"
          title="No tags yet"
          description="Tags cut across notebooks — #gst, #urgent, #q3."
          action={
            <Button icon="plus" variant="primary" size="sm" onClick={() => setManaging(true)}>
              New tag
            </Button>
          }
        />
      ) : (
        <div className="tag-rail">
          {shown.map((tag) => (
            <NavLink
              key={tag.id}
              to={`/tags/${tag.slug}`}
              onClick={onNavigate}
              className={({ isActive }) =>
                `tag-rail__chip ${isActive ? 'tag-rail__chip--active' : ''}`.trim()
              }
            >
              <span className={`org-dot ${colorDotClass(tag.color)}`.trim()} aria-hidden />
              <span className="tag-rail__name">{tag.name}</span>
              {tag.note_count !== undefined ? <span className="tag-rail__count">{tag.note_count}</span> : null}
            </NavLink>
          ))}

          {hidden > 0 || expanded ? (
            <button type="button" className="tag-rail__more" onClick={() => setExpanded((current) => !current)}>
              {expanded ? 'Show fewer' : `Show all ${all.length}`}
            </button>
          ) : null}
        </div>
      )}

      {managing ? (
        <TagManagerDialog
          tags={all}
          onClose={() => setManaging(false)}
          onStatus={setStatus}
          loading={tags.isPending}
          error={tags.isError ? tags.error : null}
        />
      ) : null}

      <LiveStatus>{status}</LiveStatus>
    </section>
  )
}

// ---------------------------------------------------------------------------
// Managing
// ---------------------------------------------------------------------------

function TagManagerDialog({
  tags,
  loading,
  error,
  onClose,
  onStatus,
}: {
  tags: Tag[]
  loading: boolean
  error: unknown
  onClose: () => void
  onStatus: (message: string) => void
}) {
  const create = useCreateTag()
  const [name, setName] = useState('')
  const [failure, setFailure] = useState<unknown>(null)
  const nameId = useId()

  const add = () => {
    if (name.trim() === '' || create.isPending) return
    setFailure(null)
    create
      .mutateAsync({ name })
      .then((tag) => {
        onStatus(`Tag ${tag.name} created`)
        setName('')
      })
      .catch(setFailure)
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Manage tags"
      description="Renaming a tag to the name of another one merges the two — the notes keep both labels."
      width={520}
      footer={<Button onClick={onClose}>Done</Button>}
    >
      <div className="org-form">
        {failure ? <ErrorNotice error={failure} /> : null}

        <form
          className="tag-create"
          onSubmit={(event) => {
            event.preventDefault()
            add()
          }}
        >
          <div className="org-field">
            <label className="org-label" htmlFor={nameId}>
              New tag
            </label>
            <input
              id={nameId}
              className="org-input"
              value={name}
              autoComplete="off"
              placeholder="gst"
              data-autofocus=""
              disabled={create.isPending}
              onChange={(event) => setName(event.target.value)}
            />
          </div>
          <Button type="submit" variant="primary" icon="plus" loading={create.isPending} disabled={name.trim() === ''}>
            Add
          </Button>
        </form>

        {loading ? (
          <div className="org-skeletons" aria-hidden>
            {[0, 1, 2].map((row) => (
              <div className="org-skeleton-row" key={row}>
                <Skeleton width={12} height={12} radius={6} />
                <Skeleton width={`${70 - row * 10}%`} height={12} />
              </div>
            ))}
          </div>
        ) : error ? (
          <ErrorNotice error={error} />
        ) : tags.length === 0 ? (
          <p className="org-empty">No tags yet. Add one above, or type one straight into a note.</p>
        ) : (
          <ul className="tag-manager">
            {tags.map((tag) => (
              <TagManagerRow key={tag.id} tag={tag} onStatus={onStatus} />
            ))}
          </ul>
        )}
      </div>
    </Dialog>
  )
}

type RowMode = 'idle' | 'editing' | 'confirming'

function TagManagerRow({ tag, onStatus }: { tag: Tag; onStatus: (message: string) => void }) {
  const update = useUpdateTag()
  const remove = useDeleteTag()
  const nameId = useId()

  const [mode, setMode] = useState<RowMode>('idle')
  const [name, setName] = useState(tag.name)
  const [color, setColor] = useState<string | null>(tag.color)
  const [failure, setFailure] = useState<unknown>(null)

  const busy = update.isPending || remove.isPending

  const save = () => {
    if (name.trim() === '' || busy) return
    setFailure(null)
    update
      .mutateAsync({ id: tag.id, name, color })
      .then((saved) => {
        onStatus(`Tag saved as ${saved.name}`)
        setMode('idle')
      })
      .catch(setFailure)
  }

  return (
    <li className={`tag-manager__row ${mode === 'editing' ? 'tag-manager__row--editing' : ''}`.trim()}>
      {mode === 'editing' ? (
        <div className="tag-manager__editor">
          <div className="org-field">
            <label className="org-label" htmlFor={nameId}>
              Name
            </label>
            <input
              id={nameId}
              className="org-input"
              value={name}
              autoComplete="off"
              disabled={busy}
              onChange={(event) => setName(event.target.value)}
            />
          </div>

          <ColorField value={color} disabled={busy} onChange={setColor} />

          {failure ? <ErrorNotice error={failure} /> : null}

          <div className="tag-manager__actions">
            <Button
              size="sm"
              onClick={() => {
                setName(tag.name)
                setColor(tag.color)
                setFailure(null)
                setMode('idle')
              }}
              disabled={busy}
            >
              Cancel
            </Button>
            <Button size="sm" variant="primary" icon="check" loading={update.isPending} onClick={save}>
              Save
            </Button>
          </div>
        </div>
      ) : (
        <>
          <span className={`org-dot ${colorDotClass(tag.color)}`.trim()} aria-hidden />
          <span className="tag-manager__name">{tag.name}</span>
          {tag.note_count !== undefined ? (
            <span className="tag-manager__count">
              {tag.note_count} {tag.note_count === 1 ? 'note' : 'notes'}
            </span>
          ) : null}

          <Button
            icon="palette"
            iconOnly
            size="sm"
            variant="ghost"
            aria-label={`Edit tag ${tag.name}`}
            disabled={busy}
            onClick={() => setMode('editing')}
          />
          <Button
            icon="trash"
            iconOnly
            size="sm"
            variant="ghost"
            aria-label={`Delete tag ${tag.name}`}
            disabled={busy}
            onClick={() => setMode('confirming')}
          />

          {mode === 'confirming' ? (
            <p className="tag-manager__confirm">
              <Icon name="alert" size={14} />{' '}
              {tag.note_count
                ? `Remove “${tag.name}” from ${tag.note_count} ${tag.note_count === 1 ? 'note' : 'notes'}? The notes are kept.`
                : `Delete “${tag.name}”?`}
              <span className="tag-manager__actions">
                <Button size="sm" onClick={() => setMode('idle')} disabled={busy}>
                  Cancel
                </Button>
                <Button
                  size="sm"
                  variant="danger"
                  icon="trash"
                  loading={remove.isPending}
                  onClick={() => {
                    setFailure(null)
                    remove
                      .mutateAsync(tag.id)
                      .then(() => onStatus(`Tag ${tag.name} deleted`))
                      .catch(setFailure)
                  }}
                >
                  Delete
                </Button>
              </span>
            </p>
          ) : null}

          {failure ? <ErrorNotice error={failure} /> : null}
        </>
      )}
    </li>
  )
}
