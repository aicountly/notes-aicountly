/**
 * The two small forms the editor needs: a link, and an image.
 *
 * Both are real dialogs rather than `window.prompt` — a prompt cannot be
 * styled, cannot be cancelled with the mouse on every platform, and cannot say
 * what went wrong. {@link Dialog} already traps focus, closes on Escape and
 * returns focus to whatever opened it.
 */

import { useEffect, useId, useState } from 'react'

import { Button, Dialog } from '../../shared/ui/primitives'
import type { ImageUploader } from './extensions/pasteHandling'
import './editor.css'

/** Everything the server's `safeUrl()` will accept in an href. */
const SAFE_SCHEME = /^(https?:\/\/|mailto:|tel:|\/|#)/i

function normaliseHref(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '') return null
  // A bare domain is what people type; assuming https is friendlier than an
  // error, and the server rejects anything that is not a real scheme anyway.
  const candidate = SAFE_SCHEME.test(trimmed) ? trimmed : `https://${trimmed}`
  return SAFE_SCHEME.test(candidate) ? candidate : null
}

export function LinkDialog({
  open,
  initialHref,
  onClose,
  onSubmit,
  onRemove,
}: {
  open: boolean
  initialHref: string
  onClose: () => void
  onSubmit: (href: string) => void
  onRemove: () => void
}) {
  const fieldId = useId()
  const [value, setValue] = useState(initialHref)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (open) {
      setValue(initialHref)
      setError(null)
    }
  }, [open, initialHref])

  return (
    <Dialog open={open} onClose={onClose} title={initialHref ? 'Edit link' : 'Add link'} width={420}>
      <form
        className="editor-form"
        onSubmit={(event) => {
          event.preventDefault()
          const href = normaliseHref(value)
          if (!href) {
            setError('Enter a web address, an email address or a phone number.')
            return
          }
          onSubmit(href)
        }}
      >
        <div className="editor-field">
          <label className="editor-field__label" htmlFor={fieldId}>
            Link address
          </label>
          <input
            id={fieldId}
            className="editor-field__input"
            type="text"
            inputMode="url"
            autoComplete="off"
            placeholder="example.com"
            value={value}
            aria-describedby={error ? `${fieldId}-error` : undefined}
            aria-invalid={error ? true : undefined}
            onChange={(event) => {
              setValue(event.target.value)
              setError(null)
            }}
          />
          {error ? (
            <p id={`${fieldId}-error`} className="editor-field__error" role="alert">
              {error}
            </p>
          ) : null}
        </div>

        <div className="editor-form__actions">
          {initialHref ? (
            <Button variant="danger" onClick={onRemove}>
              Remove link
            </Button>
          ) : null}
          <span className="editor-form__spacer" />
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" type="submit">
            {initialHref ? 'Update' : 'Add link'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

export function ImageDialog({
  open,
  uploader,
  onClose,
  onInsert,
}: {
  open: boolean
  /** Null when nothing in this deployment can accept an upload. */
  uploader: ImageUploader | null
  onClose: () => void
  onInsert: (image: { src: string; alt: string; attachmentId?: string }) => void
}) {
  const urlId = useId()
  const altId = useId()
  const fileId = useId()
  const [url, setUrl] = useState('')
  const [alt, setAlt] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)

  useEffect(() => {
    if (open) {
      setUrl('')
      setAlt('')
      setError(null)
      setUploading(false)
    }
  }, [open])

  const upload = async (file: File) => {
    if (!uploader) return
    setUploading(true)
    setError(null)
    try {
      const uploaded = await uploader(file)
      onInsert({ src: uploaded.src, alt: alt || uploaded.alt || file.name, attachmentId: uploaded.attachmentId })
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'That image could not be uploaded.')
    } finally {
      setUploading(false)
    }
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Insert image"
      description="Describe the image so it is useful to someone using a screen reader."
      width={460}
    >
      <form
        className="editor-form"
        onSubmit={(event) => {
          event.preventDefault()
          const href = normaliseHref(url)
          if (!href) {
            setError('Enter the address of an image.')
            return
          }
          onInsert({ src: href, alt })
        }}
      >
        <div className="editor-field">
          <label className="editor-field__label" htmlFor={altId}>
            Description
          </label>
          <input
            id={altId}
            className="editor-field__input"
            type="text"
            value={alt}
            placeholder="Whiteboard from the Tuesday planning session"
            onChange={(event) => setAlt(event.target.value)}
          />
        </div>

        <div className="editor-field">
          <label className="editor-field__label" htmlFor={urlId}>
            Image address
          </label>
          <input
            id={urlId}
            className="editor-field__input"
            type="text"
            inputMode="url"
            autoComplete="off"
            placeholder="https://…"
            value={url}
            onChange={(event) => {
              setUrl(event.target.value)
              setError(null)
            }}
          />
        </div>

        {/* Only offered when something can actually store the file. */}
        {uploader ? (
          <div className="editor-field">
            <label className="editor-field__label" htmlFor={fileId}>
              Or upload a file
            </label>
            <input
              id={fileId}
              className="editor-field__input"
              type="file"
              accept="image/*"
              disabled={uploading}
              onChange={(event) => {
                const file = event.target.files?.[0]
                // Cleared so that choosing the *same* file again after a failed
                // upload still fires a change event; without this the retry is
                // a file picker that does nothing.
                event.target.value = ''
                if (file) void upload(file)
              }}
            />
          </div>
        ) : null}

        {error ? (
          <p className="editor-field__error" role="alert">
            {error}
          </p>
        ) : null}

        <div className="editor-form__actions">
          <span className="editor-form__spacer" />
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" type="submit" loading={uploading}>
            Insert
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
