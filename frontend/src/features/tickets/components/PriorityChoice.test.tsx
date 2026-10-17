import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { PriorityOption } from '../types'
import { PriorityChoice } from './PriorityChoice'

/** What a requester receives: most urgent first, and no SLA minutes. */
const PRIORITIES: PriorityOption[] = [
  { value: 'critical', label: 'Urgent', level: 4, color: '#ef4444' },
  { value: 'high', label: 'High', level: 3, color: '#f97316' },
  { value: 'medium', label: 'Medium', level: 2, color: '#3b82f6' },
  { value: 'low', label: 'Low', level: 1, color: '#64748b' },
]

function Harness({
  priorities = PRIORITIES,
  onChange = vi.fn(),
  staff = false,
  error,
}: {
  priorities?: PriorityOption[]
  onChange?: (value: string) => void
  staff?: boolean
  error?: string
}) {
  const [value, setValue] = useState('')
  return (
    <PriorityChoice
      priorities={priorities}
      value={value}
      onChange={(next) => {
        setValue(next)
        onChange(next)
      }}
      staff={staff}
      error={error}
    />
  )
}

const group = () => screen.getByRole('radiogroup', { name: 'How urgent is it?' })
const radios = () => within(group()).getAllByRole('radio')

describe('PriorityChoice', () => {
  it('is one radio group that asks the question', () => {
    render(<Harness />)
    expect(group()).toBeInTheDocument()
    expect(radios()).toHaveLength(5)
  })

  it('puts "let the IT team decide" first, chosen until the reporter picks something', () => {
    render(<Harness />)

    const notSure = radios()[0]
    expect(notSure).toHaveAccessibleName('Not sure — let the IT team decide')
    expect(notSure).toBeChecked()
  })

  it('runs least to most urgent, whatever order the server sent', () => {
    // Neither the server’s order nor its reverse.
    render(<Harness priorities={[PRIORITIES[2], PRIORITIES[0], PRIORITIES[3], PRIORITIES[1]]} />)

    expect(
      radios()
        .slice(1)
        .map((radio) => radio.getAttribute('value')),
    ).toEqual(['low', 'medium', 'high', 'critical'])
  })

  it('reports the slug, so "Urgent" sends critical', async () => {
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)

    await userEvent.click(screen.getByRole('radio', { name: 'Urgent' }))

    expect(onChange).toHaveBeenLastCalledWith('critical')
    expect(screen.getByRole('radio', { name: 'Urgent' })).toBeChecked()
  })

  it('describes each level in plain words', () => {
    render(<Harness />)

    expect(screen.getByRole('radio', { name: 'Low' })).toHaveAccessibleDescription(
      'Minor — work can carry on.',
    )
    expect(screen.getByRole('radio', { name: 'High' })).toHaveAccessibleDescription(
      'Stops work for a room or a group of people.',
    )
    expect(screen.getByRole('radio', { name: 'Urgent' })).toHaveAccessibleDescription(
      'A safety risk, or many people can’t work right now.',
    )
  })

  it('gives a priority an administrator added later no guessed guide', () => {
    render(
      <Harness
        priorities={[
          ...PRIORITIES,
          { value: 'planned', label: 'Planned', level: 0, color: '#999' },
        ]}
      />,
    )

    expect(screen.getByRole('radio', { name: 'Planned' })).not.toHaveAttribute('aria-describedby')
  })

  it('speaks to IT staff in their own terms', () => {
    render(<Harness staff />)

    expect(radios()[0]).toHaveAccessibleName(
      'Use the category default, raised to the room’s minimum',
    )
  })

  it('ties a refusal to the group', () => {
    render(<Harness error="The selected priority is not available." />)

    const message = screen.getByRole('alert')
    expect(message).toHaveTextContent('The selected priority is not available.')
    expect(group()).toHaveAttribute('aria-describedby', message.id)
  })
})
