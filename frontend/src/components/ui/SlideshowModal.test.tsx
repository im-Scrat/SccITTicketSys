import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { Button } from './Button'
import { SlideshowModal, type SlideshowSlide } from './SlideshowModal'

const SLIDES: SlideshowSlide[] = [
  { id: 'directory', title: 'PC unit directory' },
  { id: 'detail', title: 'PC unit details' },
  { id: 'room', title: 'Room details' },
]

function Harness({ onClose = () => {} }: { onClose?: () => void }) {
  const [index, setIndex] = useState(0)
  return (
    <SlideshowModal
      open
      onClose={onClose}
      title="Import complete — 35 records added"
      label="Pages updated by this import"
      slides={SLIDES}
      index={index}
      onIndexChange={setIndex}
      caption={<p>Caption for {SLIDES[index].title}</p>}
      primaryAction={<Button>I've reviewed – Close</Button>}
    >
      <input aria-label="Preview field" />
    </SlideshowModal>
  )
}

describe('SlideshowModal', () => {
  it('steps forward and back without wrapping', async () => {
    render(<Harness />)
    const previous = screen.getByRole('button', { name: 'Previous' })
    const next = screen.getByRole('button', { name: 'Next' })

    expect(previous).toHaveAttribute('aria-disabled', 'true')
    await userEvent.click(previous)
    expect(screen.getByText('Caption for PC unit directory')).toBeInTheDocument()

    await userEvent.click(next)
    await userEvent.click(next)
    expect(screen.getByText('Caption for Room details')).toBeInTheDocument()
    expect(next).toHaveAttribute('aria-disabled', 'true')

    await userEvent.click(next)
    expect(screen.getByText('Caption for Room details')).toBeInTheDocument()
  })

  it('keeps focus on a step button that reaches the end', async () => {
    render(<Harness />)
    const previous = screen.getByRole('button', { name: 'Previous' })

    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    await userEvent.click(previous)
    expect(previous).toHaveAttribute('aria-disabled', 'true')
    expect(previous).toHaveFocus()
  })

  it('answers arrow keys only on the slide controls', async () => {
    render(<Harness />)

    await userEvent.click(screen.getByRole('textbox', { name: 'Preview field' }))
    await userEvent.keyboard('{ArrowRight}')
    expect(screen.getByText('Caption for PC unit directory')).toBeInTheDocument()

    screen.getByRole('button', { name: 'Next' }).focus()
    await userEvent.keyboard('{ArrowRight}')
    expect(screen.getByText('Caption for PC unit details')).toBeInTheDocument()
    await userEvent.keyboard('{End}')
    expect(screen.getByText('Caption for Room details')).toBeInTheDocument()
    await userEvent.keyboard('{Home}')
    expect(screen.getByText('Caption for PC unit directory')).toBeInTheDocument()
  })

  it('jumps with the dots and marks the current one', async () => {
    render(<Harness />)
    const dot = screen.getByRole('button', { name: 'Slide 3: Room details' })
    await userEvent.click(dot)
    expect(dot).toHaveAttribute('aria-current', 'true')
    expect(screen.getByRole('group', { name: '3 of 3: Room details' })).toBeInTheDocument()
  })

  it('announces each slide change', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    render(<Harness />)
    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    act(() => {
      vi.advanceTimersByTime(100)
    })
    expect(screen.getByRole('status')).toHaveTextContent('2 of 3: PC unit details')
    vi.useRealTimers()
  })

  it('closes on Escape without any acknowledgement', async () => {
    const onClose = vi.fn()
    render(<Harness onClose={onClose} />)
    await userEvent.keyboard('{Escape}')
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})
