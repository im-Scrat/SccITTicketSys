import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { importFixture } from '../__fixtures__/importFixture'
import { MappingStep } from './MappingStep'

const mutations = vi.hoisted(() => ({
  useUpdateMapping: vi.fn(),
  useUpdateSource: vi.fn(),
}))
vi.mock('../hooks/mutations', () => mutations)

const mutate = vi.fn()

beforeEach(() => {
  vi.clearAllMocks()
  mutations.useUpdateMapping.mockReturnValue({ mutate, isPending: false, error: null })
  mutations.useUpdateSource.mockReturnValue({ mutate: vi.fn(), isPending: false, error: null })
})

describe('MappingStep', () => {
  it('keeps "Check rows" closed, saying why, until every required destination has a column', async () => {
    render(<MappingStep item={importFixture()} />)

    const check = screen.getByRole('button', { name: 'Check rows' })
    expect(check).toBeDisabled()
    expect(screen.getByRole('status')).toHaveTextContent('Choose a column for Name.')

    await userEvent.selectOptions(screen.getByLabelText('Import column B, Description, as'), 'name')
    expect(check).toBeEnabled()
  })

  it('blocks an ambiguous column until it is decided, even as Not imported', async () => {
    const item = importFixture({
      columns: importFixture().columns.map((c) =>
        c.index === 2
          ? { ...c, header: 'Vendor', confidence: 'ambiguous' as const, alternatives: ['notes'] }
          : c,
      ),
    })
    render(<MappingStep item={item} />)
    await userEvent.selectOptions(screen.getByLabelText('Import column B, Description, as'), 'name')

    expect(screen.getByRole('status')).toHaveTextContent('Choose what column C, “Vendor”, holds')
    await userEvent.selectOptions(screen.getByLabelText('Import column C, Vendor, as'), '__none__')
    expect(screen.getByRole('button', { name: 'Check rows' })).toBeEnabled()
  })

  it('marks matches, choices and the likely ones to check', async () => {
    render(<MappingStep item={importFixture()} />)

    expect(screen.getByText('Matched')).toBeInTheDocument()
    expect(screen.getByText('Choose one')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Import column A, PC No., as'), 'hostname')
    expect(screen.getByText('Your choice')).toBeInTheDocument()
  })

  it('sends Not imported columns as null and the chosen targets by column', async () => {
    render(<MappingStep item={importFixture()} />)
    await userEvent.selectOptions(screen.getByLabelText('Import column B, Description, as'), 'name')
    await userEvent.click(screen.getByRole('button', { name: 'Check rows' }))

    expect(mutate).toHaveBeenCalledWith(
      expect.objectContaining({
        mapping: { '0': 'unit_code', '1': 'name', '2': null },
        date_order: null,
      }),
    )
  })

  it('asks how dates are written when a date column holds ambiguous dates', async () => {
    const base = importFixture()
    const item = importFixture({
      columns: [
        ...base.columns,
        {
          ...base.columns[2],
          index: 3,
          letter: 'D',
          header: 'Purchase date',
          target: 'purchase_date',
          confidence: 'exact',
        },
      ],
      mapping: { '0': 'unit_code', '3': 'purchase_date' },
      targets: [
        ...base.targets,
        {
          key: 'purchase_date',
          label: 'Purchase date',
          group: 'Purchase',
          destination: 'PC unit › Purchase date',
          hint: null,
          required: false,
          payload: { pc_unit: 'purchase_date', asset: 'purchase_date' },
        },
      ],
      date_summary: {
        required: true,
        ambiguous: 4,
        evidence: null,
        samples: [{ raw: '05/03/2024', dmy: '2024-03-05', mdy: '2024-05-03' }],
      },
    })
    render(<MappingStep item={item} />)
    await userEvent.selectOptions(screen.getByLabelText('Import column B, Description, as'), 'name')

    expect(screen.getByRole('status')).toHaveTextContent('Choose how this file writes dates.')
    await userEvent.click(screen.getByRole('radio', { name: /Day first/ }))
    expect(screen.getByRole('button', { name: 'Check rows' })).toBeEnabled()
  })
})
