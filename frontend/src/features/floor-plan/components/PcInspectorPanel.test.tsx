import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PcUnitDetail } from '@/features/assets/types'
import type { MaintenanceDetail } from '@/features/maintenance/types'
import { PcInspectorPanel } from './PcInspectorPanel'

const get = vi.fn()

vi.mock('@/services/api', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))

/** A fully-populated unit, matching what `/admin/pc-units/{uuid}` actually returns. */
function pcUnitDetail(overrides: Partial<PcUnitDetail> = {}): PcUnitDetail {
  return {
    id: 'pc-uuid-1',
    unit_code: 'LAB-1',
    pc_name: 'PC-01',
    asset_tag: 'AST-001',
    hostname: 'lab1-pc01',
    serial_number: 'SN-001',
    brand: 'Dell',
    model: 'OptiPlex 7010',
    status: 'online',
    status_label: 'Online',
    condition: 'working',
    condition_label: 'Working',
    room: { id: 'room-uuid-1', name: 'Computer Lab 2', code: 'LAB-2' },
    building: { id: 'bld-1', name: 'Main Building', code: 'MAIN' },
    warranty_expiration: null,
    warranty_days_remaining: null,
    qr_identifier: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    archived: false,
    notes: null,
    network: { ip_address: '10.0.0.5', mac_address: 'AA:BB:CC:00:11:22' },
    purchase: { date: null },
    warranty: {
      expiration: null,
      days_remaining: null,
      under_warranty: false,
      purchase_date: null,
    },
    floor: { id: 'floor-1', name: 'Ground floor', floor_number: 1 },
    specification: {
      cpu: 'Intel i5-12400',
      motherboard: null,
      ram: '16GB',
      gpu: 'Integrated',
      storage_primary: '512GB SSD',
      storage_secondary: null,
      power_supply: null,
      monitor: 'Dell 24"',
      keyboard: null,
      mouse: null,
      operating_system: 'Windows 11',
      bios_version: null,
    },
    active_tickets: [],
    created_by: null,
    updated_by: null,
    archived_at: null,
    ...overrides,
  } as PcUnitDetail
}

/** A minimal, fully-populated maintenance visit, matching `MaintenanceDetail`. */
function maintenanceDetail(overrides: Partial<MaintenanceDetail> = {}): MaintenanceDetail {
  return {
    id: 'mnt-uuid-1',
    title: 'Quarterly cleaning',
    status: { value: 'completed', label: 'Completed', tone: 'success', is_open: false },
    type: { slug: 'preventive', label: 'Preventive', is_preventive: true },
    technician: { id: 'tech-1', name: 'Jordan Cruz' },
    created_by: { id: 'admin-1', name: 'Admin User' },
    target: { kind: 'pc_unit', id: 'pc-uuid-1', label: 'PC-01', identifier: 'LAB-1' },
    location: { room: 'Computer Lab 2', building: 'Main Building' },
    ticket: null,
    checklist: { total: 0, completed: 0 },
    evidence_count: 0,
    note_count: 0,
    downtime_minutes: 15,
    labor_hours: '1.5',
    cost: '250.00',
    scheduled_for: null,
    overdue: false,
    started_at: '2026-02-01T09:00:00Z',
    completed_at: '2026-02-01T10:30:00Z',
    maintenance_date: '2026-02-01',
    created_at: '2026-02-01T09:00:00Z',
    updated_at: '2026-02-01T10:30:00Z',
    archived: false,
    diagnosis: 'Dust buildup causing thermal throttling.',
    root_cause: 'No prior cleaning schedule.',
    resolution: 'Cleaned internals, reapplied thermal paste.',
    preventive_recommendation: 'Clean every quarter.',
    pc_state_before: {
      status: 'under_maintenance',
      status_label: 'Under Maintenance',
      condition: 'working',
      condition_label: 'Working',
    },
    checklist_items: [],
    evidence: [],
    notes: [],
    hardware_replacements: [],
    available_transitions: [],
    abilities: {
      update: false,
      complete: false,
      reassign: false,
      manage_evidence: false,
      record_replacement: false,
      archive: false,
    },
    ...overrides,
  } as MaintenanceDetail
}

function renderPanel(pcId = 'pc-uuid-1') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <PcInspectorPanel pcId={pcId} onClose={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  get.mockReset()
})

describe('PcInspectorPanel', () => {
  it('asks the server for the unit and its full maintenance history', async () => {
    get.mockImplementation((url: string) =>
      url.endsWith('/maintenance')
        ? Promise.resolve({ data: { data: [] } })
        : Promise.resolve({ data: { data: pcUnitDetail() } }),
    )
    renderPanel()

    await screen.findByRole('heading', { name: 'PC-01' })

    expect(get).toHaveBeenCalledWith('/admin/pc-units/pc-uuid-1')
    expect(get).toHaveBeenCalledWith('/admin/pc-units/pc-uuid-1/maintenance')
  })

  it('shows identity, specification and network facts from the payload', async () => {
    get.mockImplementation((url: string) =>
      url.endsWith('/maintenance')
        ? Promise.resolve({ data: { data: [] } })
        : Promise.resolve({ data: { data: pcUnitDetail() } }),
    )
    renderPanel()

    await screen.findByRole('heading', { name: 'PC-01' })

    expect(screen.getByText('AST-001')).toBeInTheDocument()
    expect(screen.getByText('lab1-pc01')).toBeInTheDocument()
    expect(screen.getByText('10.0.0.5')).toBeInTheDocument()
    expect(screen.getByText('AA:BB:CC:00:11:22')).toBeInTheDocument()
    expect(screen.getByText('512GB SSD')).toBeInTheDocument()
    expect(screen.getByText('No open ticket against this machine.')).toBeInTheDocument()
  })

  it('lists an active ticket as a link to its manage page', async () => {
    get.mockImplementation((url: string) =>
      url.endsWith('/maintenance')
        ? Promise.resolve({ data: { data: [] } })
        : Promise.resolve({
            data: {
              data: pcUnitDetail({
                active_tickets: [
                  {
                    id: 'ticket-uuid-1',
                    number: 'TCK-1',
                    title: 'Monitor flickers',
                    status: 'open',
                    priority: 'high',
                  },
                ],
              }),
            },
          }),
    )
    renderPanel()

    const link = await screen.findByRole('link', { name: /Monitor flickers/ })
    expect(link).toHaveAttribute('href', '/app/tickets/manage/ticket-uuid-1')
  })

  it('renders the full maintenance history, collapsed to a summary by default', async () => {
    get.mockImplementation((url: string) =>
      url.endsWith('/maintenance')
        ? Promise.resolve({ data: { data: [maintenanceDetail()] } })
        : Promise.resolve({ data: { data: pcUnitDetail() } }),
    )
    renderPanel()

    await screen.findByText('Quarterly cleaning')

    expect(screen.getByText('Maintenance history')).toBeInTheDocument()
    expect(screen.getByText('(1)')).toBeInTheDocument()

    // The full narrative fields are in the DOM (inside a closed <details>),
    // not summarized away — this is the whole point of the inspector.
    expect(screen.getByText('Dust buildup causing thermal throttling.')).toBeInTheDocument()
    expect(screen.getByText('Cleaned internals, reapplied thermal paste.')).toBeInTheDocument()
  })

  it('says so when a machine has no maintenance recorded', async () => {
    get.mockImplementation((url: string) =>
      url.endsWith('/maintenance')
        ? Promise.resolve({ data: { data: [] } })
        : Promise.resolve({ data: { data: pcUnitDetail() } }),
    )
    renderPanel()

    expect(await screen.findByText('No maintenance recorded')).toBeInTheDocument()
  })

  it('closes on request', async () => {
    const onClose = vi.fn()
    get.mockImplementation((url: string) =>
      url.endsWith('/maintenance')
        ? Promise.resolve({ data: { data: [] } })
        : Promise.resolve({ data: { data: pcUnitDetail() } }),
    )
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <PcInspectorPanel pcId="pc-uuid-1" onClose={onClose} />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByRole('heading', { name: 'PC-01' })
    fireEvent.click(screen.getByRole('button', { name: 'Close unit details' }))
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('shows a refusal instead of a broken panel when the unit cannot be loaded', async () => {
    get.mockRejectedValue(new Error('refused'))
    renderPanel()

    expect(await screen.findByText('This unit could not be loaded')).toBeInTheDocument()
  })
})
