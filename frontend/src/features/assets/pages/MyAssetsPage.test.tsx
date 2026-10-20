import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AssetListItem } from '../types'
import MyAssetsPage from './MyAssetsPage'

const get = vi.fn()
const post = vi.fn()
vi.mock('@/services/api', () => ({
  api: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}))

function asset(id: string, overrides: Partial<AssetListItem> = {}): AssetListItem {
  return {
    id,
    asset_tag: `AST-${id}`,
    name: `Asset ${id}`,
    serial_number: null,
    barcode: null,
    status: 'deployed',
    status_label: 'In Service',
    tone: 'success',
    condition: 'working',
    condition_label: 'Working',
    category: 'printer',
    category_label: 'Printer',
    manufacturer: null,
    model: null,
    room: { id: 'room-1', name: 'Lab 2', code: 'L2' },
    building: { id: 'b-1', name: 'Main Hall', code: 'MH' },
    technician: null,
    warranty_expiration: null,
    warranty_days_remaining: null,
    placement_confirmed_at: null,
    ...overrides,
  } as AssetListItem
}

function renderPage(assets: AssetListItem[]) {
  get.mockResolvedValue({
    data: {
      data: assets,
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: assets.length,
        from: 1,
        to: assets.length,
      },
    },
  })
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <MyAssetsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
})

/** Owner request 2026-10-20 (DD-115): confirm assets are in their rooms. */
describe('MyAssetsPage — confirming placement', () => {
  it('confirms an asset when its panel is clicked', async () => {
    post.mockResolvedValue({
      data: { data: asset('a1', { placement_confirmed_at: '2026-10-20T08:00:00Z' }) },
    })
    renderPage([asset('a1')])

    await userEvent.click(
      await screen.findByRole('button', { name: 'Confirm Asset a1 is in Lab 2, Main Hall' }),
    )

    expect(post).toHaveBeenCalledWith('/my/assets/a1/confirm-placement')
    expect(await screen.findByText(/Asset a1 confirmed in Lab 2/)).toBeInTheDocument()
  })

  it('shows a confirmed asset as confirmed, not as a button', async () => {
    renderPage([asset('a1', { placement_confirmed_at: '2026-10-20T08:00:00Z' })])

    const list = await screen.findByRole('list', { name: 'Assets in your care' })
    expect(within(list).getByText('Confirmed in room')).toBeInTheDocument()
    expect(within(list).queryByRole('button')).not.toBeInTheDocument()
  })

  it('cannot confirm an asset with no room, and says why', async () => {
    renderPage([asset('a1', { room: null, building: null })])

    const list = await screen.findByRole('list', { name: 'Assets in your care' })
    expect(within(list).getByText('An administrator records its room first')).toBeInTheDocument()
    expect(within(list).queryByRole('button')).not.toBeInTheDocument()
  })

  it('confirms everything after asking once, and reports what it did', async () => {
    post.mockResolvedValue({ data: { message: 'ok', meta: { confirmed: 2, without_room: 1 } } })
    renderPage([asset('a1'), asset('a2'), asset('a3', { room: null, building: null })])

    await userEvent.click(await screen.findByRole('button', { name: 'Confirm all' }))
    const dialog = screen.getByRole('dialog', { name: /Confirm all your assets/ })
    expect(post).not.toHaveBeenCalled()

    await userEvent.click(within(dialog).getByRole('button', { name: 'Confirm all' }))

    await waitFor(() => expect(post).toHaveBeenCalledWith('/my/assets/confirm-placement'))
    expect(
      await screen.findByText(/2 assets confirmed in their rooms\. 1 with no room yet was left/),
    ).toBeInTheDocument()
  })
})
