import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { LiveRegion } from './LiveRegion'
import { ProgressBar } from './ProgressBar'
import { Stepper } from './Stepper'

const STEPS = [
  { id: 'upload', label: 'Upload' },
  { id: 'mapping', label: 'Map columns' },
  { id: 'fix', label: 'Review and fix' },
  { id: 'confirm', label: 'Confirm' },
]

describe('Stepper', () => {
  it('marks the current step and offers only earlier steps', async () => {
    const onStepSelect = vi.fn()
    render(<Stepper label="Import steps" steps={STEPS} current="fix" onStepSelect={onStepSelect} />)

    expect(screen.getByText('Step 3 of 4')).toBeInTheDocument()
    expect(
      screen.getByText('Review and fix', { selector: 'li span span' }).closest('[aria-current]'),
    ).toHaveAttribute('aria-current', 'step')
    expect(screen.queryByRole('button', { name: /Confirm/ })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Map columns, completed' }))
    expect(onStepSelect).toHaveBeenCalledWith('mapping')
  })

  it('does not offer locked steps', () => {
    render(
      <Stepper
        label="Import steps"
        steps={STEPS}
        current="confirm"
        onStepSelect={() => {}}
        lockedSteps={['upload']}
      />,
    )
    expect(screen.queryByRole('button', { name: /Upload/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Review and fix, completed' })).toBeInTheDocument()
  })
})

describe('ProgressBar', () => {
  it('exposes its value and the visible count', () => {
    render(<ProgressBar label="Importing records" value={48} max={120} valueText="48 of 120" />)
    const bar = screen.getByRole('progressbar', { name: 'Importing records' })
    expect(bar).toHaveAttribute('aria-valuenow', '48')
    expect(bar).toHaveAttribute('aria-valuemax', '120')
    expect(bar).toHaveAttribute('aria-valuetext', '48 of 120')
    expect(screen.getByText('48 of 120')).toBeInTheDocument()
  })

  it('has no value while indeterminate', () => {
    render(<ProgressBar label="Preparing" />)
    expect(screen.getByRole('progressbar', { name: 'Preparing' })).not.toHaveAttribute(
      'aria-valuenow',
    )
  })
})

describe('LiveRegion', () => {
  it('is mounted empty, then announces, and re-announces on a new key', () => {
    vi.useFakeTimers()
    const { rerender } = render(<LiveRegion message="" />)
    const region = screen.getByRole('status')
    expect(region).toHaveTextContent('')

    rerender(<LiveRegion message="Saved" announceKey={1} />)
    act(() => {
      vi.advanceTimersByTime(100)
    })
    expect(region).toHaveTextContent('Saved')

    rerender(<LiveRegion message="Saved" announceKey={2} />)
    expect(region).toHaveTextContent('')
    act(() => {
      vi.advanceTimersByTime(100)
    })
    expect(region).toHaveTextContent('Saved')
    vi.useRealTimers()
  })
})
