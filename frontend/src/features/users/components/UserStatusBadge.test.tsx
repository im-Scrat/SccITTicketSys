import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { AccountStatus } from '../types'
import { UserStatusBadge } from './UserStatusBadge'

describe('UserStatusBadge', () => {
  it.each<[AccountStatus, string]>([
    ['active', 'Active'],
    ['pending', 'Pending'],
    ['suspended', 'Suspended'],
    ['rejected', 'Rejected'],
    ['inactive', 'Inactive'],
  ])('renders the %s label (never color-only)', (status, label) => {
    render(<UserStatusBadge status={status} />)
    expect(screen.getByText(label)).toBeInTheDocument()
  })
})
