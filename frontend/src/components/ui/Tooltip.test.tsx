import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Modal } from './Modal'
import { Tooltip } from './Tooltip'
import { TruncatedText } from './TruncatedText'

describe('Tooltip', () => {
  it('describes its trigger before it is ever shown', () => {
    render(
      <Tooltip content="Counted from the status history">
        <button type="button">Resolved in period</button>
      </Tooltip>,
    )
    expect(screen.getByRole('button', { name: 'Resolved in period' })).toHaveAccessibleDescription(
      'Counted from the status history',
    )
  })

  it('shows on keyboard focus and hides on Escape without closing the dialog', async () => {
    const onClose = vi.fn()
    render(
      <Modal open onClose={onClose} title="Definitions">
        <Tooltip content="Median minutes to first staff reply">
          <button type="button">First response</button>
        </Tooltip>
      </Modal>,
    )

    await userEvent.tab()
    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'First response' })).toHaveFocus()
    expect(await screen.findByRole('tooltip', { hidden: true })).toHaveTextContent(
      'Median minutes to first staff reply',
    )

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('tooltip', { hidden: true })).not.toBeInTheDocument()
    expect(onClose).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'First response' })).toHaveFocus()
  })

  it('keeps the trigger’s own handlers and ref', async () => {
    const onFocus = vi.fn()
    let element: HTMLElement | null = null
    render(
      <Tooltip content="More">
        <button
          type="button"
          onFocus={onFocus}
          ref={(node) => {
            element = node
          }}
        >
          Info
        </button>
      </Tooltip>,
    )
    await userEvent.tab()
    expect(onFocus).toHaveBeenCalledTimes(1)
    expect(element).toBe(screen.getByRole('button', { name: 'Info' }))
  })
})

describe('TruncatedText', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('adds no tab stop or tooltip when the text fits', () => {
    render(<TruncatedText text="Computer Lab 1" />)
    expect(screen.getByText('Computer Lab 1')).not.toHaveAttribute('tabindex')
  })

  it('becomes focusable and offers the full text when cut off', async () => {
    vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(400)
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(120)

    const text = 'Science and Technology Building, Computer Laboratory 3 (east wing)'
    render(<TruncatedText text={text} />)
    const span = screen.getByText(text)
    expect(span).toHaveAttribute('tabindex', '0')

    await userEvent.tab()
    expect(await screen.findByRole('tooltip', { hidden: true })).toHaveTextContent(text)
    // The full text is already in the DOM, so it is not repeated as a description.
    expect(span).not.toHaveAttribute('aria-describedby')
  })
})
