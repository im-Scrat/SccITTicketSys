import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { roomPlan, unplacedPc } from '../test/fixtures'
import type { RoomPlan } from '../types'
import { floorPlanKeys } from './queries'
import { useFloorPlanChannel } from './useFloorPlanChannel'

/**
 * WP-E — the client half of real-time sync: subscription, cache
 * reconciliation, own-echo handling, layout-version filtering, and teardown.
 *
 * `@/services/echo` is mocked at the module boundary (not `laravel-echo`
 * itself, which `src/services/echo.test.ts` already covers) with a channel
 * stub whose `listen` calls are captured, so a test triggers a "broadcast" by
 * invoking the captured callback directly — the same shape Echo would call it
 * with.
 */
const { getEcho, leave, channelsByRoom } = vi.hoisted(() => {
  const channelsByRoom = new Map<string, Map<string, (payload: unknown) => void>>()
  const leave = vi.fn()

  function channelFor(name: string) {
    if (!channelsByRoom.has(name)) channelsByRoom.set(name, new Map())
    const listeners = channelsByRoom.get(name)!
    return {
      listen: (event: string, cb: (payload: unknown) => void) => {
        listeners.set(event, cb)
        return channelFor(name)
      },
    }
  }

  return {
    getEcho: vi.fn(() => Promise.resolve({ private: channelFor, leave })),
    leave,
    channelsByRoom,
  }
})

vi.mock('@/services/echo', () => ({ getEcho }))

function emit(roomId: string, event: string, payload: unknown) {
  channelsByRoom.get(`floor-plan.room.${roomId}`)?.get(event)?.(payload)
}

function setup(plan: RoomPlan) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  queryClient.setQueryData(floorPlanKeys.room(plan.room.id), plan)

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  )

  return { queryClient, wrapper }
}

beforeEach(() => {
  channelsByRoom.clear()
  leave.mockClear()
  getEcho.mockClear()
})

it('subscribes to the room’s private channel by uuid', async () => {
  const plan = roomPlan()
  const { wrapper } = setup(plan)

  renderHook(
    () => useFloorPlanChannel(plan.room.id, { layoutVersion: 1, isOwnPendingMove: () => false }),
    { wrapper },
  )

  await waitFor(() => expect(channelsByRoom.has(`floor-plan.room.${plan.room.id}`)).toBe(true))
})

it('does nothing when no room id is given yet', () => {
  const { wrapper } = setup(roomPlan())

  renderHook(
    () => useFloorPlanChannel(undefined, { layoutVersion: 1, isOwnPendingMove: () => false }),
    {
      wrapper,
    },
  )

  expect(getEcho).not.toHaveBeenCalled()
})

describe('PositionUpdated', () => {
  it('reconciles the cached plan to the server-stored point', async () => {
    const plan = roomPlan()
    const { queryClient, wrapper } = setup(plan)
    const moved = { ...plan.pcs[0], x: 340, y: 220 }

    renderHook(
      () => useFloorPlanChannel(plan.room.id, { layoutVersion: 1, isOwnPendingMove: () => false }),
      { wrapper },
    )
    await waitFor(() => expect(channelsByRoom.size).toBeGreaterThan(0))

    emit(plan.room.id, '.floor-plan.position-updated', { layout_version: 1, pc: moved })

    const cached = queryClient.getQueryData<RoomPlan>(floorPlanKeys.room(plan.room.id))
    expect(cached?.pcs.find((pc) => pc.id === moved.id)).toEqual(moved)
  })

  it('adds a unit that was previously unplaced, and removes it from that list', async () => {
    const plan = roomPlan({ unplaced: [unplacedPc(9, 'offline')], unplaced_count: 1 })
    const { queryClient, wrapper } = setup(plan)
    const placed = { ...unplacedPc(9, 'offline'), x: 60, y: 60, rotation: 0, z_index: 0 }

    renderHook(
      () => useFloorPlanChannel(plan.room.id, { layoutVersion: 1, isOwnPendingMove: () => false }),
      { wrapper },
    )
    await waitFor(() => expect(channelsByRoom.size).toBeGreaterThan(0))

    emit(plan.room.id, '.floor-plan.position-updated', { layout_version: 1, pc: placed })

    const cached = queryClient.getQueryData<RoomPlan>(floorPlanKeys.room(plan.room.id))
    expect(cached?.unplaced).toHaveLength(0)
    expect(cached?.pcs.some((pc) => pc.id === placed.id)).toBe(true)
  })

  it('ignores a broadcast for a layout version other than the one on screen', async () => {
    const plan = roomPlan()
    const { queryClient, wrapper } = setup(plan)
    const moved = { ...plan.pcs[0], x: 900, y: 500 }

    renderHook(
      () => useFloorPlanChannel(plan.room.id, { layoutVersion: 2, isOwnPendingMove: () => false }),
      { wrapper },
    )
    await waitFor(() => expect(channelsByRoom.size).toBeGreaterThan(0))

    emit(plan.room.id, '.floor-plan.position-updated', { layout_version: 1, pc: moved })

    const cached = queryClient.getQueryData<RoomPlan>(floorPlanKeys.room(plan.room.id))
    expect(cached?.pcs.find((pc) => pc.id === moved.id)).toEqual(plan.pcs[0])
  })

  it('skips a unit this browser is itself mid-move on', async () => {
    const plan = roomPlan()
    const { queryClient, wrapper } = setup(plan)
    const pendingId = plan.pcs[0].id
    const moved = { ...plan.pcs[0], x: 900, y: 500 }

    renderHook(
      () =>
        useFloorPlanChannel(plan.room.id, {
          layoutVersion: 1,
          isOwnPendingMove: (pcId) => pcId === pendingId,
        }),
      { wrapper },
    )
    await waitFor(() => expect(channelsByRoom.size).toBeGreaterThan(0))

    emit(plan.room.id, '.floor-plan.position-updated', { layout_version: 1, pc: moved })

    const cached = queryClient.getQueryData<RoomPlan>(floorPlanKeys.room(plan.room.id))
    expect(cached?.pcs.find((pc) => pc.id === pendingId)).toEqual(plan.pcs[0])
  })

  it('announces a remote move, distinctly from a local one', async () => {
    const plan = roomPlan()
    const { wrapper } = setup(plan)
    const announce = vi.fn()
    const moved = { ...plan.pcs[0], x: 340, y: 220 }

    renderHook(
      () =>
        useFloorPlanChannel(plan.room.id, {
          layoutVersion: 1,
          isOwnPendingMove: () => false,
          announce,
        }),
      { wrapper },
    )
    await waitFor(() => expect(channelsByRoom.size).toBeGreaterThan(0))

    emit(plan.room.id, '.floor-plan.position-updated', { layout_version: 1, pc: moved })

    expect(announce).toHaveBeenCalledWith(expect.stringContaining(moved.name))
    expect(announce).toHaveBeenCalledWith(expect.stringContaining('x 340, y 220'))
  })
})

describe('PcStatusChanged', () => {
  it('merges the status into a placed unit, leaving its position untouched', async () => {
    const plan = roomPlan()
    const { queryClient, wrapper } = setup(plan)
    const target = plan.pcs[1]
    const changed = {
      id: target.id,
      name: target.name,
      unit_code: target.unit_code,
      status: { value: 'offline' as const, label: 'Offline', tone: 'danger' as const },
    }

    renderHook(
      () => useFloorPlanChannel(plan.room.id, { layoutVersion: 1, isOwnPendingMove: () => false }),
      { wrapper },
    )
    await waitFor(() => expect(channelsByRoom.size).toBeGreaterThan(0))

    emit(plan.room.id, '.floor-plan.pc-status-changed', { pc: changed })

    const cached = queryClient.getQueryData<RoomPlan>(floorPlanKeys.room(plan.room.id))
    const updated = cached?.pcs.find((pc) => pc.id === target.id)
    expect(updated?.status).toEqual(changed.status)
    expect(updated?.x).toBe(target.x)
    expect(updated?.y).toBe(target.y)
  })

  it('merges the status into an unplaced unit', async () => {
    const plan = roomPlan({ unplaced: [unplacedPc(9, 'available')], unplaced_count: 1 })
    const { queryClient, wrapper } = setup(plan)
    const changed = {
      id: 'unplaced-uuid-9',
      name: 'PC-U9',
      unit_code: 'LAB-U9',
      status: { value: 'offline' as const, label: 'Offline', tone: 'danger' as const },
    }

    renderHook(
      () => useFloorPlanChannel(plan.room.id, { layoutVersion: 1, isOwnPendingMove: () => false }),
      { wrapper },
    )
    await waitFor(() => expect(channelsByRoom.size).toBeGreaterThan(0))

    emit(plan.room.id, '.floor-plan.pc-status-changed', { pc: changed })

    const cached = queryClient.getQueryData<RoomPlan>(floorPlanKeys.room(plan.room.id))
    expect(cached?.unplaced[0]?.status).toEqual(changed.status)
  })

  it('is a no-op when the unit belongs to no room this viewer has open', async () => {
    const plan = roomPlan()
    const { queryClient, wrapper } = setup(plan)
    const before = queryClient.getQueryData<RoomPlan>(floorPlanKeys.room(plan.room.id))

    renderHook(
      () => useFloorPlanChannel(plan.room.id, { layoutVersion: 1, isOwnPendingMove: () => false }),
      { wrapper },
    )
    await waitFor(() => expect(channelsByRoom.size).toBeGreaterThan(0))

    emit(plan.room.id, '.floor-plan.pc-status-changed', {
      pc: {
        id: 'stranger-uuid',
        name: 'Stranger',
        unit_code: 'X-1',
        status: { value: 'offline', label: 'Offline', tone: 'danger' },
      },
    })

    expect(queryClient.getQueryData<RoomPlan>(floorPlanKeys.room(plan.room.id))).toEqual(before)
  })
})

describe('teardown', () => {
  it('leaves the channel when the room changes or the component unmounts', async () => {
    const plan = roomPlan()
    const { wrapper } = setup(plan)

    const { unmount } = renderHook(
      () => useFloorPlanChannel(plan.room.id, { layoutVersion: 1, isOwnPendingMove: () => false }),
      { wrapper },
    )
    await waitFor(() => expect(channelsByRoom.size).toBeGreaterThan(0))

    unmount()

    await waitFor(() => expect(leave).toHaveBeenCalledWith(`floor-plan.room.${plan.room.id}`))
  })

  it('does not leave a channel that was never subscribed (no room id yet)', () => {
    const { wrapper } = setup(roomPlan())

    const { unmount } = renderHook(
      () => useFloorPlanChannel(undefined, { layoutVersion: 1, isOwnPendingMove: () => false }),
      { wrapper },
    )
    unmount()

    expect(leave).not.toHaveBeenCalled()
  })
})
