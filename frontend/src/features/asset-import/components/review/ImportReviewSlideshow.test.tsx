import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { usePreviewStore } from '@/stores/usePreviewStore'
import { importFixture } from '../../__fixtures__/importFixture'
import type { ImportReview, ImportResult } from '../../types'
import { ImportReturnBanner } from './ImportReturnBanner'
import { ImportReviewSlideshow } from './ImportReviewSlideshow'

const queries = vi.hoisted(() => ({ useImportReview: vi.fn() }))
vi.mock('../../hooks/queries', () => queries)
const mutations = vi.hoisted(() => ({ useAcknowledgeReview: vi.fn() }))
vi.mock('../../hooks/mutations', () => mutations)

const RESULT = {
  outcome: 'complete',
  tone: 'success',
  headline: 'Import complete — 3 records added',
  detail: '3 PC units created in 2 rooms of 1 building.',
  created: 3,
  created_pc_units: 3,
  created_assets: 0,
  skipped_duplicates: 0,
  duplicates_at_save: 0,
  failed: 0,
  not_attempted: 0,
  excluded: 0,
  rooms: 2,
  buildings: 1,
  without_room: 0,
  catalog_created: 0,
} as ImportResult

function review(overrides: Partial<ImportReview> = {}): ImportReview {
  return {
    import: { id: 'imp-1', file_name: 'lab.xlsx', outcome: 'complete', result: RESULT },
    reviewable: true,
    receipt: { reviewed_at: null, covers_all: false },
    highlight_values: ['PC-1'],
    slides: [
      {
        kind: 'pc_unit_directory',
        title: 'PC unit directory',
        page_label: 'Assets › PC units',
        explanation: 'The 3 PC units this import created, shown on their own.',
        path: '/app/assets/list?tab=pc-units&import=imp-1',
        views: [],
        instances: [],
        instance_total: 0,
      },
      {
        kind: 'pc_unit_detail',
        title: 'PC unit details',
        page_label: 'Assets › PC unit',
        explanation: 'Each PC unit’s own page.',
        path: '/app/assets/pc-units/u1',
        views: [
          { key: 'general', label: 'General' },
          { key: 'specifications', label: 'Specifications (2)' },
        ],
        instances: [
          {
            id: 'u1',
            label: 'PC-1 · One',
            path: '/app/assets/pc-units/u1',
            hint: 'Lab 1',
            archived: false,
            edited: false,
          },
          {
            id: 'u2',
            label: 'PC-2 · Two',
            path: '/app/assets/pc-units/u2',
            hint: 'Lab 2',
            archived: false,
            edited: true,
          },
        ],
        instance_total: 2,
      },
    ],
    exclusions: [{ kind: 'role_dashboard', label: 'Your dashboard', reason: 'It shows tickets.' }],
    selectable_elsewhere: [],
    ...overrides,
  }
}

const mutate = vi.fn()

function Where() {
  const location = useLocation()
  return <output data-testid="where">{location.pathname + location.search}</output>
}

function renderAt(url: string) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route
          path="*"
          element={
            <>
              <ImportReviewSlideshow
                item={importFixture({ status: 'finished', outcome: 'complete', result: RESULT })}
              />
              <Where />
            </>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  usePreviewStore.getState().clear()
  queries.useImportReview.mockReturnValue({ data: review(), isLoading: false, isError: false })
  mutations.useAcknowledgeReview.mockReturnValue({ mutate, isPending: false })
})

describe('ImportReviewSlideshow', () => {
  it('opens on ?review=1 with the outcome as its heading, and previews the slide’s page', () => {
    renderAt('/app/assets/imports/imp-1?review=1')

    expect(
      screen.getByRole('dialog', { name: 'Import complete — 3 records added' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'PC unit directory' })).toBeInTheDocument()
    expect(usePreviewStore.getState().path).toBe('/app/assets/list?tab=pc-units&import=imp-1')
  })

  it('keeps its place in the URL, and previews the chosen view and instance', async () => {
    renderAt('/app/assets/imports/imp-1?review=1&slide=pc_unit_detail')

    await userEvent.click(screen.getByRole('button', { name: 'Specifications (2)' }))
    await userEvent.selectOptions(screen.getByLabelText('Which one'), 'u2')

    expect(screen.getByTestId('where')).toHaveTextContent('slide=pc_unit_detail')
    expect(screen.getByTestId('where')).toHaveTextContent('view=specifications')
    expect(screen.getByText(/edited since the import/)).toBeInTheDocument()
    expect(usePreviewStore.getState().path).toBe('/app/assets/pc-units/u2?tab=specifications')
  })

  it('acknowledges with “I’ve reviewed”, and only then', async () => {
    renderAt('/app/assets/imports/imp-1?review=1')

    await userEvent.keyboard('{Escape}')
    expect(mutate).not.toHaveBeenCalled()
  })

  it('writes the receipt from the primary action', async () => {
    renderAt('/app/assets/imports/imp-1?review=1')
    await userEvent.click(screen.getByRole('button', { name: 'I’ve reviewed – Close' }))

    expect(mutate).toHaveBeenCalledTimes(1)
  })

  it('just closes once a receipt covers every record', () => {
    queries.useImportReview.mockReturnValue({
      data: review({ receipt: { reviewed_at: '2026-10-18T02:00:00Z', covers_all: true } }),
      isLoading: false,
      isError: false,
    })
    renderAt('/app/assets/imports/imp-1?review=1')

    expect(screen.getByRole('button', { name: 'Close' })).toBeInTheDocument()
  })
})

describe('ImportReturnBanner', () => {
  it('appears only on a page opened from the review, and leads back to its slide', () => {
    render(
      <MemoryRouter
        initialEntries={[
          {
            pathname: '/app/assets/pc-units/u1',
            state: { importReturn: { id: 'imp-1', file: 'lab.xlsx', slide: 'pc_unit_detail' } },
          },
        ]}
      >
        <ImportReturnBanner />
      </MemoryRouter>,
    )

    expect(screen.getByRole('link', { name: 'Back to import review' })).toHaveAttribute(
      'href',
      '/app/assets/imports/imp-1?review=1&slide=pc_unit_detail',
    )
  })

  it('is absent on an ordinary visit', () => {
    render(
      <MemoryRouter initialEntries={['/app/assets/pc-units/u1']}>
        <ImportReturnBanner />
      </MemoryRouter>,
    )

    expect(screen.queryByRole('link', { name: 'Back to import review' })).not.toBeInTheDocument()
  })
})
