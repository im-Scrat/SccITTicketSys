import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { importFixture, rowFixture } from '../__fixtures__/importFixture'
import type { ImportPlan, ImportResult } from '../types'
import { ConfirmStep } from './ConfirmStep'
import { ImportOutcomeSummary } from './ImportOutcomeSummary'
import { RowIssuePanel } from './RowIssuePanel'

const mutations = vi.hoisted(() => ({
  useConfirmImport: vi.fn(),
  useRetryImport: vi.fn(),
  useUpdateRow: vi.fn(),
}))
vi.mock('../hooks/mutations', () => mutations)
const queries = vi.hoisted(() => ({ useImportRows: vi.fn(), useRoomOptions: vi.fn() }))
vi.mock('../hooks/queries', () => queries)

const PLAN: ImportPlan = {
  pc_units: 3,
  assets: 0,
  total: 3,
  rooms: 2,
  without_room: 1,
  duplicates: 2,
  excluded: 0,
  blocking: 0,
  invalid: 0,
  needs_review: 0,
  catalog_entries: [],
  revision: 7,
}

const mutate = vi.fn()

beforeEach(() => {
  vi.clearAllMocks()
  for (const hook of Object.values(mutations))
    hook.mockReturnValue({ mutate, isPending: false, error: null })
  queries.useImportRows.mockReturnValue({ data: { data: [] }, isLoading: false })
  queries.useRoomOptions.mockReturnValue({ data: [], isFetching: false })
})

describe('ConfirmStep', () => {
  it('states the plan and confirms with the counts the administrator saw', async () => {
    render(<ConfirmStep item={importFixture({ status: 'review', plan: PLAN })} onBack={vi.fn()} />)

    expect(screen.getByText('PC units to create').nextElementSibling).toHaveTextContent('3')
    expect(screen.getByText('Without a room, by your choice').nextElementSibling).toHaveTextContent(
      '1 row',
    )
    expect(screen.getByText(/existing records stay exactly as they are/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Import 3 records' }))
    expect(mutate).toHaveBeenCalledWith({
      revision: 7,
      expected: { pc_units: 3, assets: 0, catalog_entries: 0 },
    })
  })
})

describe('ImportOutcomeSummary', () => {
  const result: ImportResult = {
    outcome: 'partial',
    tone: 'warning',
    headline: 'Partially imported — 117 of 120 created',
    detail: '117 PC units created in 4 rooms of 1 building.',
    created: 117,
    created_pc_units: 117,
    created_assets: 0,
    skipped_duplicates: 1,
    duplicates_at_save: 1,
    failed: 2,
    not_attempted: 0,
    excluded: 0,
    rooms: 4,
    buildings: 1,
    without_room: 0,
    catalog_created: 0,
  }

  it('shows the outcome in the server’s own words and the rows that did not save', () => {
    queries.useImportRows.mockImplementation((_id: string, query: { outcome?: string }) => ({
      isLoading: false,
      data: {
        data:
          query.outcome === 'failed'
            ? [
                rowFixture({
                  row_number: 9,
                  outcome: 'failed',
                  outcome_label: 'Failed',
                  failure_code: 'room_unavailable',
                }),
              ]
            : [],
      },
    }))
    render(
      <MemoryRouter>
        <ImportOutcomeSummary
          item={importFixture({
            status: 'finished',
            outcome: 'partial',
            result,
            can: { cancel: false, stop: false, retry: true, download_file: true },
          })}
        />
      </MemoryRouter>,
    )

    expect(screen.getByText('Partially imported — 117 of 120 created')).toBeInTheDocument()
    expect(
      screen.getByText('Its room was archived or taken out of use before the row was saved.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Fix and retry' })).toBeInTheDocument()
  })
})

describe('RowIssuePanel', () => {
  it('acknowledges a review issue and saves it for the server to recheck', async () => {
    const row = rowFixture({
      issues: [
        {
          code: 'possible_duplicate_serial',
          severity: 'review',
          field: 'serial_number',
          message: 'Serial number SN-1 is already recorded on a PC unit.',
          action: 'acknowledge',
          scope: 'row',
          related: {},
        },
      ],
    })
    render(
      <RowIssuePanel
        item={importFixture({ status: 'review' })}
        row={row}
        onClose={vi.fn()}
        onSaved={vi.fn()}
        onGoTo={vi.fn()}
      />,
    )

    expect(
      screen.getByText('Serial number SN-1 is already recorded on a PC unit.'),
    ).toBeInTheDocument()
    const save = screen.getByRole('button', { name: 'Save and recheck' })
    expect(save).toBeDisabled()

    await userEvent.click(screen.getByRole('checkbox', { name: /I’ve checked this/ }))
    await userEvent.click(save)
    expect(mutate).toHaveBeenCalledWith(
      { rowNumber: 2, change: { acknowledge: ['possible_duplicate_serial'] } },
      expect.anything(),
    )
  })

  it('shows each value beside the cell it came from, and sends a correction', async () => {
    render(
      <RowIssuePanel
        item={importFixture({ status: 'review' })}
        row={rowFixture()}
        onClose={vi.fn()}
        onSaved={vi.fn()}
        onGoTo={vi.fn()}
      />,
    )

    expect(screen.getByText('In your file: “pc-01”')).toBeInTheDocument()
    const name = screen.getByLabelText('Name')
    await userEvent.clear(name)
    await userEvent.type(name, 'Lab desk')
    await userEvent.click(screen.getByRole('button', { name: 'Save and recheck' }))

    expect(mutate).toHaveBeenCalledWith(
      { rowNumber: 2, change: { corrections: { name: 'Lab desk' } } },
      expect.anything(),
    )
  })
})
