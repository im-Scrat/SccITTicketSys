import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PreferenceMatrix } from './PreferenceMatrix'
import type { NotificationPreference } from '../types'

const fetchNotificationPreferences = vi.hoisted(() => vi.fn())
const updateNotificationPreferences = vi.hoisted(() => vi.fn())

vi.mock('../api/notificationsApi', () => ({
  fetchNotificationPreferences,
  updateNotificationPreferences,
  fetchNotifications: vi.fn(),
  fetchUnreadCount: vi.fn(),
  markNotificationRead: vi.fn(),
  markNotificationUnread: vi.fn(),
  markAllNotificationsRead: vi.fn(),
}))

/**
 * The preference matrix (SRS FR-NOT-002).
 *
 * Four properties, each of which a plausible implementation gets wrong:
 *
 *  1. **The grid is generated from the server's vocabulary.** A hard-coded 2×9
 *     would look identical today and would silently omit the announcement row
 *     the day D5 ships.
 *  2. **An absent row reads as enabled.** The table is opt-out; treating a gap
 *     as "off" would render every never-visited user's preferences as a grid of
 *     switches in the wrong position.
 *  3. **One save, carrying only what changed.** Eighteen requests would make a
 *     partial save the normal outcome of a flaky connection.
 *  4. **The forced cell is disabled and explained.** Accepting a click the
 *     server then ignores is worse than refusing it.
 */
const CHANNELS = [
  { value: 'in_app', label: 'In App' },
  { value: 'email', label: 'Email' },
]

const TYPES = [
  { value: 'info', label: 'Info' },
  { value: 'success', label: 'Success' },
  { value: 'warning', label: 'Warning' },
  { value: 'error', label: 'Error' },
  { value: 'ticket_update', label: 'Ticket Update' },
  { value: 'assignment', label: 'Assignment' },
  { value: 'announcement', label: 'Announcement' },
  { value: 'maintenance', label: 'Maintenance' },
  { value: 'system', label: 'System' },
]

/** The complete matrix, exactly as the endpoint fills it in. */
function fullMatrix(overrides: NotificationPreference[] = []): NotificationPreference[] {
  const rows: NotificationPreference[] = []
  for (const channel of CHANNELS) {
    for (const type of TYPES) {
      const override = overrides.find(
        (row) => row.channel === channel.value && row.notification_type === type.value,
      )
      rows.push(
        override ?? { channel: channel.value, notification_type: type.value, is_enabled: true },
      )
    }
  }
  return rows
}

function renderMatrix() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )

  return render(<PreferenceMatrix />, { wrapper })
}

beforeEach(() => {
  vi.clearAllMocks()
  fetchNotificationPreferences.mockResolvedValue({
    data: fullMatrix(),
    meta: { channels: CHANNELS, types: TYPES, default_enabled: true },
  })
})

describe('the grid', () => {
  it('renders every channel by every type, whatever the server sends', async () => {
    renderMatrix()

    const boxes = await screen.findAllByRole('checkbox')
    expect(boxes).toHaveLength(CHANNELS.length * TYPES.length)
    // The fixtures above are 2 x 9; the literal guards the derived
    // assertion against an empty fixture passing vacuously.
    expect(boxes).toHaveLength(18)
  })

  it('is built from the server vocabulary, so a new type appears on its own', async () => {
    // The day D5's announcements or WP-2.4b's procurement type ships, the grid
    // must widen without a frontend change.
    fetchNotificationPreferences.mockResolvedValue({
      data: [],
      meta: {
        channels: CHANNELS,
        types: [...TYPES, { value: 'procurement', label: 'Procurement' }],
        default_enabled: true,
      },
    })
    renderMatrix()

    expect(await screen.findByRole('rowheader', { name: 'Procurement' })).toBeInTheDocument()
    expect(screen.getAllByRole('checkbox')).toHaveLength(20)
  })

  it('names each cell by the pair it sits in, not by a bare checkbox', async () => {
    renderMatrix()

    expect(
      await screen.findByRole('checkbox', { name: 'Email notifications for Ticket Update' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('checkbox', { name: 'In App notifications for Assignment' }),
    ).toBeInTheDocument()
  })

  it('gives the columns and rows real header scope', async () => {
    renderMatrix()

    expect(await screen.findByRole('columnheader', { name: 'Email' })).toBeInTheDocument()
    expect(screen.getByRole('rowheader', { name: 'Maintenance' })).toBeInTheDocument()
  })

  it('states the opt-out default so the ticks are not ambiguous', async () => {
    renderMatrix()

    expect(await screen.findByText(/everything is on until you turn it off/i)).toBeInTheDocument()
  })
})

describe('reading the stored state', () => {
  it('shows a stored "off" as unchecked', async () => {
    fetchNotificationPreferences.mockResolvedValue({
      data: fullMatrix([{ channel: 'email', notification_type: 'assignment', is_enabled: false }]),
      meta: { channels: CHANNELS, types: TYPES, default_enabled: true },
    })
    renderMatrix()

    expect(
      await screen.findByRole('checkbox', { name: 'Email notifications for Assignment' }),
    ).not.toBeChecked()
  })

  it('treats a cell the server did not mention as enabled', async () => {
    // Opt-out: an absent row is "enabled", not "unknown".
    fetchNotificationPreferences.mockResolvedValue({
      data: [],
      meta: { channels: CHANNELS, types: TYPES, default_enabled: true },
    })
    renderMatrix()

    const boxes = await screen.findAllByRole('checkbox')
    expect(boxes.every((box) => (box as HTMLInputElement).checked)).toBe(true)
  })
})

describe('the forced cell', () => {
  it('disables the lockout email and explains why', async () => {
    renderMatrix()

    const forced = await screen.findByRole('checkbox', {
      name: /Email notifications for System — always on/,
    })
    expect(forced).toBeDisabled()
    expect(forced).toBeChecked()
    expect(screen.getByText(/security notices.*always emailed/i)).toBeInTheDocument()
  })

  it('leaves the in-app half of the same row switchable', async () => {
    // Only the email channel is forced; the in-app row is an ordinary choice.
    renderMatrix()

    expect(
      await screen.findByRole('checkbox', { name: 'In App notifications for System' }),
    ).toBeEnabled()
  })
})

describe('saving', () => {
  it('sends every change in one request, and only what changed', async () => {
    const user = userEvent.setup()
    updateNotificationPreferences.mockResolvedValue([])
    renderMatrix()

    await user.click(
      await screen.findByRole('checkbox', { name: 'Email notifications for Assignment' }),
    )
    await user.click(screen.getByRole('checkbox', { name: 'In App notifications for Info' }))
    await user.click(screen.getByRole('button', { name: /save preferences/i }))

    await waitFor(() => expect(updateNotificationPreferences).toHaveBeenCalledTimes(1))
    expect(updateNotificationPreferences).toHaveBeenCalledWith([
      { channel: 'email', notification_type: 'assignment', is_enabled: false },
      { channel: 'in_app', notification_type: 'info', is_enabled: false },
    ])
  })

  it('offers nothing to save until something changes', async () => {
    renderMatrix()

    expect(await screen.findByRole('button', { name: /save preferences/i })).toBeDisabled()
  })

  it('counts the unsaved changes so the save says what it will do', async () => {
    const user = userEvent.setup()
    renderMatrix()

    await user.click(
      await screen.findByRole('checkbox', { name: 'Email notifications for Warning' }),
    )

    expect(screen.getByText('1 unsaved change')).toBeInTheDocument()
  })

  it('drops a cell toggled back to where it started', async () => {
    const user = userEvent.setup()
    renderMatrix()

    const box = await screen.findByRole('checkbox', { name: 'Email notifications for Warning' })
    await user.click(box)
    await user.click(box)

    // Nothing changed, so there is nothing to send.
    expect(screen.getByRole('button', { name: /save preferences/i })).toBeDisabled()
    expect(screen.queryByText(/unsaved change/)).not.toBeInTheDocument()
  })

  it('confirms the save and clears the pending changes', async () => {
    const user = userEvent.setup()
    updateNotificationPreferences.mockResolvedValue([])
    renderMatrix()

    await user.click(await screen.findByRole('checkbox', { name: 'Email notifications for Info' }))
    await user.click(screen.getByRole('button', { name: /save preferences/i }))

    expect(await screen.findByText(/preferences have been saved/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /save preferences/i })).toBeDisabled()
  })

  it('says nothing was changed when the save fails', async () => {
    const user = userEvent.setup()
    updateNotificationPreferences.mockRejectedValue(new Error('500'))
    renderMatrix()

    await user.click(await screen.findByRole('checkbox', { name: 'Email notifications for Info' }))
    await user.click(screen.getByRole('button', { name: /save preferences/i }))

    const alert = await screen.findByRole('alert')
    expect(within(alert).getByText(/nothing was changed/i)).toBeInTheDocument()
  })
})

describe('while loading and when it fails', () => {
  it('shows placeholders rather than an empty grid', () => {
    fetchNotificationPreferences.mockReturnValue(new Promise(() => {}))
    renderMatrix()

    expect(
      screen.getByRole('status', { name: /loading notification preferences/i }),
    ).toBeInTheDocument()
  })

  it('says the stored settings are unchanged when the matrix cannot be read', async () => {
    fetchNotificationPreferences.mockRejectedValue(new Error('500'))
    renderMatrix()

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.getByText(/current settings are unchanged/i)).toBeInTheDocument()
  })
})
