import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AssetFormDrawer } from './AssetFormDrawer'

const create = vi.fn()

vi.mock('../hooks/queries', () => ({
  useAssetCatalog: () => ({
    data: {
      statuses: [{ value: 'new', label: 'New' }],
      conditions: [{ value: 'working', label: 'Working' }],
      categories: [{ value: 'printer', label: 'Printer' }],
      manufacturers: [],
      suppliers: [],
      models: [{ value: 7, label: 'LaserJet 4200', manufacturer: 'HP', category: 'printer' }],
      technicians: [],
      pc_statuses: [],
    },
  }),
}))
vi.mock('@/hooks/useLocationLookup', () => ({ useRoomLookup: () => ({ data: [] }) }))
vi.mock('../hooks/mutations', () => ({
  useCreateAsset: () => ({ mutateAsync: create }),
  useUpdateAsset: () => ({ mutateAsync: vi.fn() }),
}))

function renderForm() {
  render(<AssetFormDrawer open onClose={vi.fn()} />)
}

beforeEach(() => {
  create.mockReset()
  create.mockResolvedValue({ id: 'asset-1' })
})

describe('AssetFormDrawer — adding an asset', () => {
  it('has no asset tag field, and marks nothing as required or "(optional)"', () => {
    renderForm()

    expect(screen.queryByLabelText(/asset tag/i)).not.toBeInTheDocument()
    expect(screen.getByLabelText(/^asset name/i)).toBeInTheDocument()
    expect(screen.queryByText(/\(optional\)/i)).not.toBeInTheDocument()
    expect(screen.getByLabelText(/^catalog model/i)).not.toHaveAttribute('aria-required')
  })

  it('adds an asset with every field left empty', async () => {
    renderForm()

    await userEvent.click(screen.getByRole('button', { name: 'Add asset' }))

    await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
    const sent = create.mock.calls[0][0]
    expect(sent).not.toHaveProperty('asset_tag')
    expect(sent).toMatchObject({ name: null, hardware_model: null, hardware_model_other: null })
  })

  it('offers "Other" under the catalog model, revealing a text box just below it', async () => {
    renderForm()

    expect(screen.queryByLabelText(/describe the catalog model/i)).not.toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText(/^catalog model/i), 'Other — describe it')

    const other = screen.getByLabelText(/describe the catalog model/i)
    await userEvent.type(other, 'Epson EB-X51')
    await userEvent.click(screen.getByRole('button', { name: 'Add asset' }))

    await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
    expect(create.mock.calls[0][0]).toMatchObject({
      hardware_model: null,
      hardware_model_other: 'Epson EB-X51',
    })
  })

  it('sends a chosen catalog model by id, and no described one', async () => {
    renderForm()

    await userEvent.selectOptions(screen.getByLabelText(/^catalog model/i), 'HP — LaserJet 4200')
    await userEvent.click(screen.getByRole('button', { name: 'Add asset' }))

    await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
    expect(create.mock.calls[0][0]).toMatchObject({ hardware_model: 7, hardware_model_other: null })
  })
})
