/**
 * Photographing a document, a page at a time.
 *
 * What this does is deliberately smaller than what a scanner app does, and the
 * difference is the point: there is **no automatic edge detection**. Rotation
 * and cropping are real — every operation here redraws the actual bitmap
 * through a canvas and hands back new bytes — and the framing is the person's,
 * because a stub that draws a quadrilateral and pretends to have found the page
 * is worse than a crop handle that works.
 *
 * Pages are held in memory until they are attached. Each one owns an object
 * URL, so every path that replaces or removes a page revokes the old one.
 */

import { useCallback, useEffect, useId, useRef, useState } from 'react'
import type { PointerEvent as ReactPointerEvent } from 'react'

import { useFeature } from '../../../app/AppConfigProvider'
import { Icon } from '../../../shared/ui/Icon'
import { Button, EmptyState, LiveStatus } from '../../../shared/ui/primitives'
import { CameraCapture, isCameraSupported } from './CameraCapture'
import type { CapturedImage } from './CameraCapture'
import '../capture.css'

const PAGE_QUALITY = 0.92
const PAGE_TYPE = 'image/jpeg'

export interface ScanPage {
  id: string
  blob: Blob
  /** Object URL for the current bytes. Revoked whenever the page changes. */
  url: string
  width: number
  height: number
}

/** A rectangle in fractions of the page, so it survives a resize of the preview. */
export interface CropRect {
  x: number
  y: number
  width: number
  height: number
}

const FULL_PAGE: CropRect = { x: 0, y: 0, width: 1, height: 1 }

function newId(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function clamp(value: number, low: number, high: number): number {
  return Math.min(high, Math.max(low, value))
}

// ---------------------------------------------------------------------------
// Canvas work
// ---------------------------------------------------------------------------

function loadImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image()
    image.onload = () => resolve(image)
    image.onerror = () => reject(new Error('That page could not be read.'))
    image.src = url
  })
}

function toBlob(canvas: HTMLCanvasElement): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error('That page could not be saved.'))),
      PAGE_TYPE,
      PAGE_QUALITY,
    )
  })
}

/**
 * Turn a page a quarter turn, for real.
 *
 * A CSS transform would rotate what is on screen and upload the original
 * sideways; the canvas is redrawn so the bytes that leave here are the bytes
 * the user framed.
 */
export async function rotatePage(page: ScanPage, quarterTurns: 1 | 3): Promise<Omit<ScanPage, 'id'>> {
  const image = await loadImage(page.url)
  const canvas = document.createElement('canvas')
  const sideways = quarterTurns % 2 === 1
  canvas.width = sideways ? image.naturalHeight : image.naturalWidth
  canvas.height = sideways ? image.naturalWidth : image.naturalHeight

  const context = canvas.getContext('2d')
  if (!context) throw new Error('This browser could not rotate the page.')

  context.translate(canvas.width / 2, canvas.height / 2)
  context.rotate((quarterTurns * Math.PI) / 2)
  context.drawImage(image, -image.naturalWidth / 2, -image.naturalHeight / 2)

  const blob = await toBlob(canvas)
  return { blob, url: URL.createObjectURL(blob), width: canvas.width, height: canvas.height }
}

/** Keep only the chosen rectangle. `rect` is in fractions of the current page. */
export async function cropPage(page: ScanPage, rect: CropRect): Promise<Omit<ScanPage, 'id'>> {
  const image = await loadImage(page.url)

  const sx = Math.round(clamp(rect.x, 0, 1) * image.naturalWidth)
  const sy = Math.round(clamp(rect.y, 0, 1) * image.naturalHeight)
  const sw = Math.max(1, Math.round(clamp(rect.width, 0, 1) * image.naturalWidth))
  const sh = Math.max(1, Math.round(clamp(rect.height, 0, 1) * image.naturalHeight))

  const canvas = document.createElement('canvas')
  canvas.width = Math.min(sw, image.naturalWidth - sx)
  canvas.height = Math.min(sh, image.naturalHeight - sy)

  const context = canvas.getContext('2d')
  if (!context) throw new Error('This browser could not crop the page.')
  context.drawImage(image, sx, sy, canvas.width, canvas.height, 0, 0, canvas.width, canvas.height)

  const blob = await toBlob(canvas)
  return { blob, url: URL.createObjectURL(blob), width: canvas.width, height: canvas.height }
}

// ---------------------------------------------------------------------------
// Crop editor
// ---------------------------------------------------------------------------

/**
 * Framing a page.
 *
 * Pointer dragging is the fast path and the number fields are the real one:
 * a crop that can only be set by dragging cannot be set at all without a
 * mouse, and "drag the corners" is not an instruction a keyboard user can
 * follow.
 */
function CropEditor({
  page,
  pageNumber,
  onApply,
  onCancel,
  busy,
}: {
  page: ScanPage
  pageNumber: number
  onApply: (rect: CropRect) => void
  onCancel: () => void
  busy: boolean
}) {
  const [rect, setRect] = useState<CropRect>(FULL_PAGE)
  const surfaceRef = useRef<HTMLDivElement>(null)
  const anchor = useRef<{ x: number; y: number } | null>(null)
  const fieldIds = { left: useId(), top: useId(), width: useId(), height: useId() }

  const pointFrom = (event: { clientX: number; clientY: number }): { x: number; y: number } | null => {
    const bounds = surfaceRef.current?.getBoundingClientRect()
    if (!bounds || bounds.width === 0 || bounds.height === 0) return null
    return {
      x: clamp((event.clientX - bounds.left) / bounds.width, 0, 1),
      y: clamp((event.clientY - bounds.top) / bounds.height, 0, 1),
    }
  }

  const onPointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
    const point = pointFrom(event)
    if (!point) return
    anchor.current = point
    event.currentTarget.setPointerCapture(event.pointerId)
    setRect({ x: point.x, y: point.y, width: 0, height: 0 })
  }

  const onPointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (!anchor.current) return
    const point = pointFrom(event)
    if (!point) return
    setRect({
      x: Math.min(anchor.current.x, point.x),
      y: Math.min(anchor.current.y, point.y),
      width: Math.abs(point.x - anchor.current.x),
      height: Math.abs(point.y - anchor.current.y),
    })
  }

  const endDrag = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (!anchor.current) return
    anchor.current = null
    event.currentTarget.releasePointerCapture(event.pointerId)
    // A tap rather than a drag: treat it as "no selection" instead of leaving
    // a zero-size crop that would produce a one-pixel page.
    setRect((current) => (current.width < 0.02 || current.height < 0.02 ? FULL_PAGE : current))
  }

  const percent = (value: number) => Math.round(value * 100)

  const setEdge = (key: keyof CropRect, nextPercent: number) => {
    const value = clamp(nextPercent / 100, 0, 1)
    setRect((current) => {
      const next = { ...current, [key]: value }
      // Keep the rectangle inside the page whichever field was edited.
      next.width = clamp(next.width, 0.02, 1 - next.x)
      next.height = clamp(next.height, 0.02, 1 - next.y)
      next.x = clamp(next.x, 0, 1 - next.width)
      next.y = clamp(next.y, 0, 1 - next.height)
      return next
    })
  }

  const untouched = rect.width >= 0.999 && rect.height >= 0.999

  return (
    <div className="scan-crop">
      <p className="scan-crop__hint">
        Drag across page {pageNumber} to choose what to keep, or set the edges below. Page edges are
        not detected automatically.
      </p>

      <div
        ref={surfaceRef}
        className="scan-crop__surface"
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={endDrag}
        onPointerCancel={endDrag}
      >
        <img className="scan-crop__image" src={page.url} alt={`Page ${pageNumber}`} draggable={false} />
        <div
          className="scan-crop__rect"
          style={{
            left: `${rect.x * 100}%`,
            top: `${rect.y * 100}%`,
            width: `${rect.width * 100}%`,
            height: `${rect.height * 100}%`,
          }}
        />
      </div>

      <div className="scan-crop__fields">
        {([
          ['left', 'Left', 'x'],
          ['top', 'Top', 'y'],
          ['width', 'Width', 'width'],
          ['height', 'Height', 'height'],
        ] as const).map(([key, label, field]) => (
          <div className="scan-crop__field" key={key}>
            <label htmlFor={fieldIds[key]}>{label} (%)</label>
            <input
              id={fieldIds[key]}
              type="number"
              min={0}
              max={100}
              step={1}
              value={percent(rect[field])}
              onChange={(event) => setEdge(field, Number(event.target.value))}
            />
          </div>
        ))}
      </div>

      <div className="scan-crop__actions">
        <Button
          variant="primary"
          icon="check"
          loading={busy}
          disabled={untouched}
          onClick={() => onApply(rect)}
        >
          {untouched ? 'Choose an area first' : 'Apply crop'}
        </Button>
        <Button variant="ghost" onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// The scanner
// ---------------------------------------------------------------------------

export interface DocumentScannerProps {
  /** Stores the pages. Rejecting with a message leaves them in the tray. */
  onSave: (files: File[]) => Promise<void>
  onCancel: () => void
}

export function DocumentScanner({ onSave, onCancel }: DocumentScannerProps) {
  const ocrEnabled = useFeature('ocr')
  const [pages, setPages] = useState<ScanPage[]>([])
  const [editingId, setEditingId] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [announcement, setAnnouncement] = useState('')
  const fileInputRef = useRef<HTMLInputElement>(null)

  // Pages outlive individual renders but not this component.
  const pagesRef = useRef<ScanPage[]>([])
  pagesRef.current = pages
  useEffect(
    () => () => {
      pagesRef.current.forEach((page) => URL.revokeObjectURL(page.url))
    },
    [],
  )

  const addPage = useCallback((blob: Blob, width: number, height: number) => {
    setPages((current) => [
      ...current,
      { id: newId(), blob, url: URL.createObjectURL(blob), width, height },
    ])
    setError(null)
  }, [])

  const onCapture = useCallback(
    (image: CapturedImage) => {
      addPage(image.blob, image.width, image.height)
      setAnnouncement('Page added')
    },
    [addPage],
  )

  const onPickFiles = async (files: FileList | null) => {
    if (!files || files.length === 0) return
    for (const file of Array.from(files)) {
      if (!file.type.startsWith('image/')) continue
      const url = URL.createObjectURL(file)
      try {
        const image = await loadImage(url)
        addPage(file, image.naturalWidth, image.naturalHeight)
      } catch {
        setError(`${file.name} could not be read as an image.`)
      } finally {
        URL.revokeObjectURL(url)
      }
    }
    setAnnouncement('Pages added')
  }

  /**
   * Replace a page's bytes and let go of the ones it had.
   *
   * The revoke happens outside the updater on purpose: React may run an
   * updater more than once for a single commit, and one that has a side effect
   * in it can end up revoking the URL it just installed.
   */
  const replacePage = (id: string, next: Omit<ScanPage, 'id'>) => {
    const previous = pagesRef.current.find((page) => page.id === id)
    setPages((current) => current.map((page) => (page.id === id ? { id, ...next } : page)))
    if (previous && previous.url !== next.url) URL.revokeObjectURL(previous.url)
  }

  const rotate = async (page: ScanPage, quarterTurns: 1 | 3) => {
    setWorking(true)
    setError(null)
    try {
      replacePage(page.id, await rotatePage(page, quarterTurns))
      setAnnouncement('Page rotated')
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'That page could not be rotated.')
    } finally {
      setWorking(false)
    }
  }

  const applyCrop = async (page: ScanPage, rect: CropRect) => {
    setWorking(true)
    setError(null)
    try {
      replacePage(page.id, await cropPage(page, rect))
      setEditingId(null)
      setAnnouncement('Page cropped')
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'That page could not be cropped.')
    } finally {
      setWorking(false)
    }
  }

  const removePage = (page: ScanPage) => {
    URL.revokeObjectURL(page.url)
    setPages((current) => current.filter((row) => row.id !== page.id))
    if (editingId === page.id) setEditingId(null)
    setAnnouncement('Page removed')
  }

  const save = async () => {
    if (pages.length === 0) return
    setWorking(true)
    setError(null)

    const stamp = new Date()
    const pad = (value: number) => String(value).padStart(2, '0')
    const prefix = `Scan ${stamp.getFullYear()}-${pad(stamp.getMonth() + 1)}-${pad(stamp.getDate())}`

    try {
      await onSave(
        pages.map(
          (page, index) =>
            new File([page.blob], `${prefix} page ${index + 1}.jpg`, { type: PAGE_TYPE }),
        ),
      )
      pages.forEach((page) => URL.revokeObjectURL(page.url))
      setPages([])
      setAnnouncement('Pages attached')
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Those pages could not be attached.')
    } finally {
      setWorking(false)
    }
  }

  const editing = pages.find((page) => page.id === editingId) ?? null
  const editingNumber = editing ? pages.findIndex((page) => page.id === editing.id) + 1 : 0

  const filePicker = (
    <>
      <Button icon="image" onClick={() => fileInputRef.current?.click()}>
        {isCameraSupported() ? 'Choose images instead' : 'Choose images'}
      </Button>
      <input
        ref={fileInputRef}
        type="file"
        accept="image/*"
        multiple
        className="sr-only"
        tabIndex={-1}
        aria-hidden
        onChange={(event) => {
          void onPickFiles(event.target.files)
          event.target.value = ''
        }}
      />
    </>
  )

  return (
    <div className="scan">
      {editing ? (
        <CropEditor
          page={editing}
          pageNumber={editingNumber}
          busy={working}
          onApply={(rect) => void applyCrop(editing, rect)}
          onCancel={() => setEditingId(null)}
        />
      ) : (
        <CameraCapture onCapture={onCapture} fallback={filePicker} shutterLabel="Capture page" />
      )}

      {!ocrEnabled ? (
        <p className="scan__notice scan__notice--info">
          <Icon name="info" size={15} />
          <span>
            Text extraction is not enabled on this deployment. The pages are attached as images and
            no text is read from them.
          </span>
        </p>
      ) : null}

      {error ? (
        <p className="scan__notice scan__notice--danger" role="alert">
          <Icon name="alert" size={15} />
          <span>{error}</span>
        </p>
      ) : null}

      <section className="scan__tray" aria-label="Captured pages">
        {pages.length === 0 ? (
          <EmptyState
            icon="scan"
            title="No pages yet"
            description="Capture a page with the camera, or choose images already on this device."
          />
        ) : (
          <ol className="scan__pages">
            {pages.map((page, index) => (
              <li className="scan-page" key={page.id}>
                <img className="scan-page__thumb" src={page.url} alt={`Page ${index + 1}`} />
                <div className="scan-page__body">
                  <p className="scan-page__title">Page {index + 1}</p>
                  <p className="scan-page__meta">
                    {page.width} × {page.height}
                  </p>
                  <div className="scan-page__actions">
                    <Button
                      size="sm"
                      variant="ghost"
                      icon="undo"
                      iconOnly
                      disabled={working}
                      aria-label={`Rotate page ${index + 1} left`}
                      onClick={() => void rotate(page, 3)}
                    />
                    <Button
                      size="sm"
                      variant="ghost"
                      icon="refresh"
                      iconOnly
                      disabled={working}
                      aria-label={`Rotate page ${index + 1} right`}
                      onClick={() => void rotate(page, 1)}
                    />
                    <Button
                      size="sm"
                      variant="ghost"
                      icon="scan"
                      disabled={working}
                      onClick={() => setEditingId(page.id)}
                    >
                      Crop
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      icon="trash"
                      iconOnly
                      disabled={working}
                      aria-label={`Remove page ${index + 1}`}
                      onClick={() => removePage(page)}
                    />
                  </div>
                </div>
              </li>
            ))}
          </ol>
        )}
      </section>

      <div className="scan__bar">
        <Button
          variant="primary"
          icon="check"
          loading={working}
          disabled={pages.length === 0}
          onClick={() => void save()}
        >
          {pages.length <= 1 ? 'Attach page' : `Attach ${pages.length} pages`}
        </Button>
        <span className="scan__spacer" />
        <Button variant="ghost" onClick={onCancel} disabled={working}>
          Close
        </Button>
      </div>

      <LiveStatus>{announcement}</LiveStatus>
    </div>
  )
}
