import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { LocationMetrics } from '../types'
import { LocationMetricsCards } from './LocationMetricsCards'

const METRICS = {
  summary: {
    buildings: 2,
    buildings_active: 2,
    buildings_inactive: 0,
    buildings_archived: 0,
    floors: 2,
    floors_archived: 0,
    rooms: 6,
    rooms_active: 6,
    rooms_inactive: 0,
    rooms_archived: 0,
    total_capacity: 0,
  },
  by_room_type: [],
  occupancy: {
    pc_units_placed: 4,
    pc_units_unplaced: 0,
    rooms_with_pc_units: 1,
    empty_rooms: 5,
    buildings_without_rooms: 0,
  },
  recent_activity: [],
} as unknown as LocationMetrics

function renderCards(props: Partial<Parameters<typeof LocationMetricsCards>[0]> = {}) {
  const handlers = { onShowBuildings: vi.fn(), onShowRooms: vi.fn() }
  render(
    <MemoryRouter>
      <LocationMetricsCards metrics={METRICS} {...handlers} {...props} />
    </MemoryRouter>,
  )
  return handlers
}

describe('LocationMetricsCards', () => {
  it('opens the buildings list from Buildings', async () => {
    const { onShowBuildings } = renderCards()

    await userEvent.click(screen.getByRole('button', { name: /buildings/i }))

    expect(onShowBuildings).toHaveBeenCalledTimes(1)
  })

  it('opens the rooms list from Rooms (the card carrying Seats)', async () => {
    const { onShowRooms } = renderCards()

    await userEvent.click(screen.getByRole('button', { name: /rooms.*seats 0/i }))

    expect(onShowRooms).toHaveBeenCalledTimes(1)
  })

  it('links Floors to the floor plan for someone who may open it', () => {
    renderCards({ floorsTo: '/app/floor-plan' })

    expect(screen.getByRole('link', { name: /^floors: 2/i })).toHaveAttribute(
      'href',
      '/app/floor-plan',
    )
  })

  it('opens the buildings list from Floors for anyone else', async () => {
    const { onShowBuildings } = renderCards()

    await userEvent.click(screen.getByRole('button', { name: /floors/i }))

    expect(onShowBuildings).toHaveBeenCalledTimes(1)
  })

  it('links Unassigned PCs to the PC list filtered to PCs with no room', () => {
    renderCards({ unassignedTo: '/app/assets/list?tab=pc-units&unassigned=1' })

    expect(screen.getByRole('link', { name: /unassigned pcs: 0/i })).toHaveAttribute(
      'href',
      '/app/assets/list?tab=pc-units&unassigned=1',
    )
  })
})
