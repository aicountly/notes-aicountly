/**
 * Drop a file onto a note to attach it.
 *
 * The interesting part is what this does *not* claim. A drag inside the editor
 * — a selected paragraph being moved, a link being dragged from the address
 * bar — carries no `Files` entry in `dataTransfer.types`, so those drags are
 * left entirely alone: no `preventDefault`, no overlay, no interference with
 * ProseMirror's own drop handling. Only a drag that actually carries files is
 * taken over.
 *
 * `dragenter`/`dragleave` fire once per element the pointer crosses, so a
 * naive implementation flickers the overlay off every time the cursor passes
 * over a child. The depth counter is what stops that.
 */

import { useCallback, useRef, useState } from 'react'
import type { DragEvent, ReactNode } from 'react'

import { Icon } from '../../../shared/ui/Icon'
import '../attachments.css'

/** True when this drag is carrying files rather than text or a selection. */
export function isFileDrag(transfer: DataTransfer | null): boolean {
  if (!transfer) return false
  // `types` is a DOMStringList in older engines, so it is read positionally
  // rather than with Array.prototype.includes.
  for (let index = 0; index < transfer.types.length; index += 1) {
    if (transfer.types[index] === 'Files') return true
  }
  return false
}

export interface FileDropZoneProps {
  onFiles: (files: File[]) => void
  /** Read-only notes, and deployments where uploads are refused, take no drops. */
  disabled?: boolean
  /** Shown on the overlay. Name the destination — "Drop files onto this note". */
  label?: string
  /** Explains why a drop will not be accepted, when `disabled`. */
  disabledReason?: string
  children: ReactNode
}

export function FileDropZone({
  onFiles,
  disabled = false,
  label = 'Drop files to attach them',
  disabledReason,
  children,
}: FileDropZoneProps) {
  const depth = useRef(0)
  const [over, setOver] = useState(false)

  const reset = useCallback(() => {
    depth.current = 0
    setOver(false)
  }, [])

  const onDragEnter = (event: DragEvent<HTMLDivElement>) => {
    if (!isFileDrag(event.dataTransfer)) return
    event.preventDefault()
    depth.current += 1
    setOver(true)
  }

  const onDragOver = (event: DragEvent<HTMLDivElement>) => {
    if (!isFileDrag(event.dataTransfer)) return
    // Without this the browser navigates to the dropped file and the note is
    // replaced by a JPEG. `dropEffect` is what makes the cursor say "copy".
    event.preventDefault()
    event.dataTransfer.dropEffect = disabled ? 'none' : 'copy'
  }

  const onDragLeave = (event: DragEvent<HTMLDivElement>) => {
    if (!isFileDrag(event.dataTransfer)) return
    depth.current = Math.max(0, depth.current - 1)
    if (depth.current === 0) setOver(false)
  }

  const onDrop = (event: DragEvent<HTMLDivElement>) => {
    if (!isFileDrag(event.dataTransfer)) return
    event.preventDefault()
    reset()
    if (disabled) return

    const files = Array.from(event.dataTransfer.files)
    if (files.length > 0) onFiles(files)
  }

  return (
    <div
      className="att-dropzone"
      onDragEnter={onDragEnter}
      onDragOver={onDragOver}
      onDragLeave={onDragLeave}
      onDrop={onDrop}
    >
      {children}

      {over ? (
        <div className={`att-dropzone__overlay ${disabled ? 'att-dropzone__overlay--blocked' : ''}`.trim()}>
          <span className="att-dropzone__badge">
            <Icon name={disabled ? 'lock' : 'upload'} size={20} />
            {disabled ? disabledReason ?? 'Files cannot be added to this note.' : label}
          </span>
        </div>
      ) : null}
    </div>
  )
}
