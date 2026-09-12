import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { PresenceAvatars } from './PresenceAvatars'

describe('PresenceAvatars', () => {
  it('renders nothing when no one else is here', () => {
    const { container } = render(<PresenceAvatars viewers={[]} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('shows each viewer by their initials, with the full name available to assistive tech', () => {
    render(
      <PresenceAvatars
        viewers={[
          { user_id: 'user-a', display_name: 'Alice Rao' },
          { user_id: 'user-b', display_name: 'Bob' },
        ]}
      />,
    )

    expect(screen.getByTitle('Alice Rao')).toHaveTextContent('AR')
    expect(screen.getByTitle('Bob')).toHaveTextContent('B')
    expect(
      screen.getByRole('group', { name: '2 other people are viewing this note' }),
    ).toBeInTheDocument()
  })

  it('collapses beyond the cap into a count rather than an ever-growing row', () => {
    const viewers = ['Alice', 'Bob', 'Carol', 'Dave', 'Eve'].map((name, index) => ({
      user_id: `user-${index}`,
      display_name: name,
    }))

    render(<PresenceAvatars viewers={viewers} />)

    expect(screen.getByTitle('Alice')).toBeInTheDocument()
    expect(screen.getByTitle('Bob')).toBeInTheDocument()
    expect(screen.getByTitle('Carol')).toBeInTheDocument()
    expect(screen.queryByTitle('Dave')).not.toBeInTheDocument()
    expect(screen.getByTitle('2 more')).toHaveTextContent('+2')
  })

  it('never renders empty initials for a viewer with no usable name', () => {
    render(<PresenceAvatars viewers={[{ user_id: 'user-a', display_name: '   ' }]} />)

    expect(screen.getByText('?')).toBeInTheDocument()
  })
})
