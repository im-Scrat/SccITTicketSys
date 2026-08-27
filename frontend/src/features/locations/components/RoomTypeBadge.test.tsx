import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { RoomType } from '../types'
import { RoomTypeBadge } from './RoomTypeBadge'

describe('RoomTypeBadge', () => {
  it.each<[RoomType, string]>([
    ['laboratory', 'Laboratory'],
    ['office', 'Office'],
    ['storage', 'Storage'],
    ['server_room', 'Server room'],
    ['faculty_room', 'Faculty room'],
    ['library', 'Library'],
    ['other', 'Other'],
  ])('labels %s in sentence case (never colour-only)', (type, label) => {
    render(<RoomTypeBadge type={type} />)
    expect(screen.getByText(label)).toBeInTheDocument()
  })

  it('prefers the label the server sent', () => {
    render(<RoomTypeBadge type="server_room" label="Server room (secure)" />)
    expect(screen.getByText('Server room (secure)')).toBeInTheDocument()
  })
})
