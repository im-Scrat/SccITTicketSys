import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { Modal } from './Modal'

describe('Modal', () => {
  it('closes only the topmost modal on Escape', async () => {
    // e.g. a "Remove this comment?" confirmation raised inside the comments dialog.
    const closeOuter = vi.fn()
    const closeInner = vi.fn()

    render(
      <>
        <Modal open onClose={closeOuter} title="Outer">
          outer
        </Modal>
        <Modal open onClose={closeInner} title="Inner">
          inner
        </Modal>
      </>,
    )

    await userEvent.keyboard('{Escape}')

    expect(closeInner).toHaveBeenCalledTimes(1)
    expect(closeOuter).not.toHaveBeenCalled()
  })

  it('moves focus into the dialog and returns it to the opener', async () => {
    function Harness() {
      const [open, setOpen] = useState(false)
      return (
        <>
          <button type="button" onClick={() => setOpen(true)}>
            Open
          </button>
          <Modal open={open} onClose={() => setOpen(false)} title="Comments">
            body
          </Modal>
        </>
      )
    }

    render(<Harness />)
    const opener = screen.getByRole('button', { name: 'Open' })

    await userEvent.click(opener)
    expect(screen.getByRole('dialog', { name: 'Comments' })).toHaveFocus()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(opener).toHaveFocus()
  })
})
