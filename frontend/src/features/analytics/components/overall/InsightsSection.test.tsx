import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { NarrativeState } from '../../types'
import { InsightsSection } from './InsightsSection'

const hooks = vi.hoisted(() => ({ useNarrativeState: vi.fn(), useGenerateNarrative: vi.fn() }))
vi.mock('../../hooks/narratives', () => hooks)

const fact = (key: string, label: string, value: number, records = 3) => ({
  key,
  question: key.slice(0, 2),
  label,
  value,
  unit: 'replacements',
  basis: 'Parts replaced on corrective repairs',
  record_count: records,
})

function state(overrides: Partial<NarrativeState> = {}): NarrativeState {
  return {
    enabled: true,
    configured: true,
    current: true,
    changed_fact_keys: [],
    facts: {
      scope: 'Period: 2026-10-01 to 2026-10-18 (Asia/Manila)',
      questions: {
        q1: {
          question: 'Which PC units needed the most part replacements, and why?',
          sufficient: true,
          reason: null,
          facts: [
            fact(
              'q1.pc_1.corrective_replacement_events',
              'Corrective part replacements on PC-001',
              2,
              2,
            ),
          ],
        },
        q2: {
          question: 'Where do PC problems cluster?',
          sufficient: false,
          reason: '1 located PC problem in 1 room; at least 5 across 2 rooms are needed.',
          facts: [],
        },
        q3: {
          question: 'Which components fail most often?',
          sufficient: false,
          reason: 'Too few.',
          facts: [],
        },
        q4: {
          question: 'Are performance problems recurring?',
          sufficient: false,
          reason: 'Too few.',
          facts: [],
        },
        q5: {
          question: 'Which PC units should get preventive care first?',
          sufficient: false,
          reason: 'Too few.',
          facts: [],
        },
      },
      limitations: ['An absence of history is not evidence of low risk.'],
    },
    narrative: {
      id: 'n-1',
      status: 'completed',
      status_label: 'Up to date',
      model: 'gemini-1.5-flash',
      coverage: null,
      findings: [
        {
          question: 'q1',
          kind: 'observation',
          text: 'PC-001 had 2 corrective part replacements in the period.',
          cited_fact_keys: ['q1.pc_1.corrective_replacement_events'],
          confidence: 'low',
        },
      ],
      limitations: [],
      withheld_count: 2,
      error_code: null,
      generated_at: '2026-10-18T02:00:00Z',
      created_at: '2026-10-18T02:00:00Z',
    },
    ...overrides,
  }
}

const mutate = vi.fn()

beforeEach(() => {
  vi.clearAllMocks()
  hooks.useGenerateNarrative.mockReturnValue({ mutate, isPending: false, error: null })
})

describe('InsightsSection', () => {
  it('shows the recorded facts beside the advisory interpretation, with its citations', () => {
    hooks.useNarrativeState.mockReturnValue({ data: state(), isError: false })
    render(<InsightsSection params={{}} />)

    expect(
      screen.getByText(/The AI interpretation is advisory: it may be wrong/),
    ).toBeInTheDocument()
    expect(screen.getByText('Up to date')).toBeInTheDocument()
    const block = screen.getByRole('region', {
      name: 'Which PC units needed the most part replacements, and why?',
    })
    expect(within(block).getByText('Recorded facts')).toBeInTheDocument()
    expect(
      within(block).getByText('PC-001 had 2 corrective part replacements in the period.'),
    ).toBeInTheDocument()
    expect(
      within(block).getByRole('link', { name: 'Corrective part replacements on PC-001' }),
    ).toHaveAttribute('href', '#fact-q1.pc_1.corrective_replacement_events')
    expect(screen.getByText(/2 AI statements were withheld/)).toBeInTheDocument()
  })

  it('states why a question has no interpretation, in the application’s words', () => {
    hooks.useNarrativeState.mockReturnValue({ data: state(), isError: false })
    render(<InsightsSection params={{}} />)

    expect(
      screen.getByText('1 located PC problem in 1 room; at least 5 across 2 rooms are needed.'),
    ).toBeInTheDocument()
  })

  it('collapses to one list when no question has enough data', () => {
    const base = state({ narrative: null })
    base.facts.questions.q1 = {
      ...base.facts.questions.q1,
      sufficient: false,
      reason: 'No PC unit had two or more.',
    }
    hooks.useNarrativeState.mockReturnValue({ data: base, isError: false })
    render(<InsightsSection params={{}} />)

    expect(screen.getByText('Not enough recorded data to interpret')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Generate interpretation' })).toBeDisabled()
  })

  it('keeps the facts but disables generation when the switch is off, and says why', () => {
    hooks.useNarrativeState.mockReturnValue({
      data: state({ enabled: false, narrative: null }),
      isError: false,
    })
    render(<InsightsSection params={{}} />)

    expect(screen.getByText('Off')).toBeInTheDocument()
    expect(
      screen.getByText('AI interpretations are switched off in AI settings.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Generate interpretation' })).toBeDisabled()
    expect(screen.getByText('Corrective part replacements on PC-001')).toBeInTheDocument()
  })

  it('marks an interpretation of older figures as out of date', () => {
    hooks.useNarrativeState.mockReturnValue({
      data: state({ current: false, changed_fact_keys: ['q1.pc_1.corrective_replacement_events'] }),
      isError: false,
    })
    render(<InsightsSection params={{}} />)

    expect(screen.getByText('Data changed since generated')).toBeInTheDocument()
    expect(screen.getByText('The data has changed since this was generated')).toBeInTheDocument()
  })

  it('asks for an interpretation', async () => {
    hooks.useNarrativeState.mockReturnValue({ data: state({ narrative: null }), isError: false })
    render(<InsightsSection params={{}} />)

    await userEvent.click(screen.getByRole('button', { name: 'Generate interpretation' }))
    expect(mutate).toHaveBeenCalledTimes(1)
  })
})
