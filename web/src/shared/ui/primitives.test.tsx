/**
 * The Dialog's keyboard behaviour.
 *
 * A modal that traps focus badly is not a cosmetic problem: a keyboard user
 * tabs out of it into a page they cannot see, and there is no way back. These
 * are the four things a div-with-a-shadow is missing, tested because they are
 * invisible to anyone reviewing it with a mouse.
 */

import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { Button, Dialog, EmptyState } from './primitives'

function Harness({ onClose = vi.fn() }: { onClose?: () => void }) {
  return (
    <Dialog open onClose={onClose} title="Share note" description="Choose who can open this note.">
      <input aria-label="Person" />
      <Button>Invite</Button>
    </Dialog>
  )
}

describe('Dialog', () => {
  it('announces itself as a modal with a name and a description', () => {
    render(<Harness />)

    const dialog = screen.getByRole('dialog')
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    expect(dialog).toHaveAccessibleName('Share note')
    expect(dialog).toHaveAccessibleDescription('Choose who can open this note.')
  })

  it('moves focus inside when it opens', async () => {
    render(<Harness />)

    // Focus must not stay on the page behind, or the first Tab takes the user
    // somewhere they cannot see.
    await vi.waitFor(() => expect(screen.getByLabelText('Person')).toHaveFocus())
  })

  it('closes on Escape', async () => {
    const onClose = vi.fn()
    render(<Harness onClose={onClose} />)

    await userEvent.keyboard('{Escape}')
    expect(onClose).toHaveBeenCalled()
  })

  it('keeps Tab inside the dialog, forwards and backwards', async () => {
    render(<Harness />)
    const user = userEvent.setup()

    const field = screen.getByLabelText('Person')
    const invite = screen.getByRole('button', { name: 'Invite' })
    const close = screen.getByRole('button', { name: 'Close' })

    await vi.waitFor(() => expect(field).toHaveFocus())

    await user.tab()
    expect(invite).toHaveFocus()

    // Past the last control, focus wraps to the first rather than escaping.
    await user.tab()
    expect(close).toHaveFocus()

    await user.tab({ shift: true })
    expect(invite).toHaveFocus()
  })

  it('returns focus to whatever opened it', async () => {
    function Toggle() {
      const [open, setOpen] = useState(false)
      return (
        <>
          <Button onClick={() => setOpen(true)}>Open share</Button>
          <Dialog open={open} onClose={() => setOpen(false)} title="Share note">
            <Button>Invite</Button>
          </Dialog>
        </>
      )
    }

    render(<Toggle />)
    const user = userEvent.setup()
    const trigger = screen.getByRole('button', { name: 'Open share' })

    await user.click(trigger)
    await vi.waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument())

    await user.keyboard('{Escape}')
    // Otherwise the user is dumped at the top of the document and has to find
    // their place again.
    await vi.waitFor(() => expect(trigger).toHaveFocus())
  })

  it('renders nothing at all when closed', () => {
    render(
      <Dialog open={false} onClose={vi.fn()} title="Hidden">
        <p>secret</p>
      </Dialog>,
    )

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByText('secret')).not.toBeInTheDocument()
  })
})

describe('EmptyState', () => {
  it('offers a way forward rather than just saying there is nothing', async () => {
    const onCreate = vi.fn()
    render(
      <EmptyState
        icon="note"
        title="Capture your first thought"
        description="Notes you write will appear here."
        action={<Button variant="primary" onClick={onCreate}>New note</Button>}
      />,
    )

    expect(screen.getByText('Capture your first thought')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'New note' }))
    expect(onCreate).toHaveBeenCalled()
  })
})

describe('Button', () => {
  it('is disabled and marked busy while loading', () => {
    render(<Button loading>Save</Button>)

    const button = screen.getByRole('button')
    expect(button).toBeDisabled()
    // A spinner alone tells a screen reader nothing.
    expect(button).toHaveAttribute('aria-busy', 'true')
  })

  it('keeps its accessible name when it shows only an icon', () => {
    render(<Button icon="pin" iconOnly aria-label="Pin note" />)

    expect(screen.getByRole('button', { name: 'Pin note' })).toBeInTheDocument()
  })
})
