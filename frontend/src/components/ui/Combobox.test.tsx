import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { Combobox, type ComboboxOption } from './Combobox'
import { Modal } from './Modal'

const ROOMS: ComboboxOption[] = [
  { value: 'r1', label: 'Computer Lab 1', description: 'LAB-101', group: 'Main Building' },
  { value: 'r2', label: 'Computer Lab 2', description: 'LAB-102', group: 'Main Building' },
  { value: 'r3', label: 'Registrar Office', description: 'OFF-201', group: 'Annex' },
  { value: 'r4', label: 'Old Storage', description: 'STO-001', group: 'Annex', disabled: true },
]

function Harness({ onChange = () => {} }: { onChange?: (value: string | null) => void }) {
  const [value, setValue] = useState<string | null>(null)
  return (
    <Combobox
      label="Room"
      options={ROOMS}
      value={value}
      onChange={(next) => {
        setValue(next)
        onChange(next)
      }}
    />
  )
}

describe('Combobox', () => {
  it('filters by label, description and group as you type', async () => {
    render(<Harness />)
    const input = screen.getByRole('combobox', { name: 'Room' })

    await userEvent.type(input, 'off-2')
    const options = screen.getAllByRole('option')
    expect(options).toHaveLength(1)
    expect(options[0]).toHaveTextContent('Registrar Office')

    await userEvent.clear(input)
    await userEvent.type(input, 'annex')
    expect(screen.getAllByRole('option')).toHaveLength(2)
  })

  it('chooses with the arrow keys and Enter, skipping disabled options', async () => {
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)
    const input = screen.getByRole('combobox', { name: 'Room' })

    await userEvent.click(input)
    expect(input).toHaveAttribute('aria-expanded', 'true')
    expect(input).toHaveAttribute('aria-activedescendant', screen.getAllByRole('option')[0].id)

    await userEvent.keyboard('{ArrowDown}{ArrowDown}{ArrowDown}')
    // r4 is disabled, so the highlight stops on r3.
    expect(document.getElementById(input.getAttribute('aria-activedescendant')!)).toHaveTextContent(
      'Registrar Office',
    )

    await userEvent.keyboard('{Enter}')
    expect(onChange).toHaveBeenCalledWith('r3')
    expect(input).toHaveValue('Registrar Office')
    expect(input).toHaveAttribute('aria-expanded', 'false')
  })

  it('never selects just by typing, and restores the label on leaving', async () => {
    const onChange = vi.fn()
    render(
      <>
        <Harness onChange={onChange} />
        <button type="button">After</button>
      </>,
    )
    const input = screen.getByRole('combobox', { name: 'Room' })

    await userEvent.click(input)
    await userEvent.click(screen.getByRole('option', { name: /Computer Lab 2/ }))
    expect(input).toHaveValue('Computer Lab 2')

    await userEvent.type(input, 'xyz')
    expect(screen.getByText('No matches')).toBeInTheDocument()
    await userEvent.tab()
    await userEvent.tab()
    expect(input).toHaveValue('Computer Lab 2')
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('marks the chosen option as selected and can clear it', async () => {
    render(<Harness />)
    const input = screen.getByRole('combobox', { name: 'Room' })

    await userEvent.click(input)
    await userEvent.click(screen.getByRole('option', { name: /Computer Lab 1/ }))
    await userEvent.click(input)
    expect(screen.getByRole('option', { name: /Computer Lab 1/ })).toHaveAttribute(
      'aria-selected',
      'true',
    )

    await userEvent.keyboard('{Escape}')
    await userEvent.click(screen.getByRole('button', { name: 'Clear Room' }))
    expect(input).toHaveValue('')
  })

  it('takes Escape for the list before the dialog it sits in', async () => {
    const onClose = vi.fn()
    render(
      <Modal open onClose={onClose} title="Choose a room">
        <Harness />
      </Modal>,
    )
    const input = screen.getByRole('combobox', { name: 'Room' })
    await userEvent.click(input)
    // Portalled into the dialog's overlay root, not behind it in <body>.
    expect(screen.getByRole('listbox').closest('[inert]')).toBeNull()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    expect(onClose).not.toHaveBeenCalled()

    await userEvent.keyboard('{Escape}')
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('caps the rendered list and says how to reach the rest', async () => {
    const many = Array.from({ length: 80 }, (_, index) => ({
      value: `room-${index}`,
      label: `Room ${index}`,
    }))
    render(
      <Combobox label="Room" options={many} value={null} onChange={() => {}} maxResults={20} />,
    )

    await userEvent.click(screen.getByRole('combobox', { name: 'Room' }))
    expect(screen.getAllByRole('option')).toHaveLength(20)
    expect(screen.getByText('Showing 20 of 80. Type to narrow the list.')).toBeInTheDocument()
  })
})
