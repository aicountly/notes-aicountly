/**
 * The drop zone's job is as much about the drags it ignores as the ones it
 * takes: a paragraph being dragged inside the editor must reach ProseMirror
 * untouched, with no overlay and no `preventDefault`.
 */

import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { ComponentProps } from 'react'

import { FileDropZone, isFileDrag } from './FileDropZone'

const FILE_DRAG = { types: ['Files'], files: [] as File[] }
const TEXT_DRAG = { types: ['text/plain', 'text/html'], files: [] as File[] }

function zone(props: Partial<ComponentProps<typeof FileDropZone>> = {}) {
  const onFiles = vi.fn()
  render(
    <FileDropZone onFiles={onFiles} {...props}>
      <p>The note</p>
    </FileDropZone>,
  )
  return { onFiles, target: screen.getByText('The note') }
}

describe('isFileDrag', () => {
  it('recognises a drag carrying files', () => {
    expect(isFileDrag({ types: ['Files'] } as unknown as DataTransfer)).toBe(true)
  })

  it('leaves a text selection alone', () => {
    expect(isFileDrag({ types: ['text/plain'] } as unknown as DataTransfer)).toBe(false)
    expect(isFileDrag(null)).toBe(false)
  })
})

describe('<FileDropZone />', () => {
  it('shows the overlay only while files are over it', () => {
    const { target } = zone()

    fireEvent.dragEnter(target, { dataTransfer: FILE_DRAG })
    expect(screen.getByText('Drop files to attach them')).toBeInTheDocument()

    fireEvent.dragLeave(target, { dataTransfer: FILE_DRAG })
    expect(screen.queryByText('Drop files to attach them')).not.toBeInTheDocument()
  })

  it('ignores a drag that is not carrying files', () => {
    const { target } = zone()

    fireEvent.dragEnter(target, { dataTransfer: TEXT_DRAG })

    expect(screen.queryByText('Drop files to attach them')).not.toBeInTheDocument()
  })

  it('does not flicker when the pointer crosses a child element', () => {
    const { target } = zone()

    // enter the zone, then enter the paragraph inside it, then leave the
    // paragraph — the drag is still over the zone.
    fireEvent.dragEnter(target, { dataTransfer: FILE_DRAG })
    fireEvent.dragEnter(target, { dataTransfer: FILE_DRAG })
    fireEvent.dragLeave(target, { dataTransfer: FILE_DRAG })

    expect(screen.getByText('Drop files to attach them')).toBeInTheDocument()
  })

  it('hands the dropped files over', () => {
    const { onFiles, target } = zone()
    const dropped = new File(['x'], 'photo.png', { type: 'image/png' })

    fireEvent.drop(target, { dataTransfer: { types: ['Files'], files: [dropped] } })

    expect(onFiles).toHaveBeenCalledWith([dropped])
  })

  it('refuses a drop on a note the user cannot edit, and says why', () => {
    const { onFiles, target } = zone({
      disabled: true,
      disabledReason: 'You have view-only access to this note.',
    })
    const dropped = new File(['x'], 'photo.png', { type: 'image/png' })

    fireEvent.dragEnter(target, { dataTransfer: FILE_DRAG })
    expect(screen.getByText('You have view-only access to this note.')).toBeInTheDocument()

    fireEvent.drop(target, { dataTransfer: { types: ['Files'], files: [dropped] } })
    expect(onFiles).not.toHaveBeenCalled()
  })
})
