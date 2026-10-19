import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type ReactElement, useRef, useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { ConfirmDialog } from './ConfirmDialog'
import { Drawer } from './Drawer'
import { Modal } from './Modal'
import { Popover } from './Popover'

/** Render into an app root of our own, so there is a background to make inert. */
function renderInApp(ui: ReactElement) {
  const app = document.createElement('div')
  document.body.appendChild(app)
  return { app, ...render(ui, { container: app }) }
}

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

  it('keeps Tab inside the dialog, wrapping at both ends', async () => {
    render(
      <Modal open onClose={() => {}} title="Edit room" footer={<button type="button">Save</button>}>
        <input aria-label="Room name" />
      </Modal>,
    )
    const close = screen.getByRole('button', { name: 'Close dialog' })
    const field = screen.getByRole('textbox', { name: 'Room name' })
    const save = screen.getByRole('button', { name: 'Save' })

    await userEvent.tab()
    expect(close).toHaveFocus()
    await userEvent.tab()
    expect(field).toHaveFocus()
    await userEvent.tab()
    expect(save).toHaveFocus()
    await userEvent.tab()
    expect(close).toHaveFocus()
    await userEvent.tab({ shift: true })
    expect(save).toHaveFocus()
  })

  it('makes the page behind inert while open, and releases it on close', () => {
    const { app, rerender } = renderInApp(
      <Modal open onClose={() => {}} title="Details">
        body
      </Modal>,
    )
    expect(app).toHaveAttribute('inert')
    // The dialog's own portal root is not made inert.
    expect(screen.getByRole('dialog').closest('[inert]')).toBeNull()

    rerender(
      <Modal open={false} onClose={() => {}} title="Details">
        body
      </Modal>,
    )
    expect(app).not.toHaveAttribute('inert')
    app.remove()
  })

  it('keeps the page inert until the last overlay closes, whichever closes first', () => {
    function Stack({ outer, inner }: { outer: boolean; inner: boolean }) {
      return (
        <>
          <Drawer open={outer} onClose={() => {}} title="Edit PC">
            drawer
          </Drawer>
          <Modal open={inner} onClose={() => {}} title="Confirm">
            confirm
          </Modal>
        </>
      )
    }

    const { app, rerender } = renderInApp(<Stack outer inner />)
    expect(app).toHaveAttribute('inert')

    rerender(<Stack outer={false} inner />)
    expect(app).toHaveAttribute('inert')

    rerender(<Stack outer={false} inner={false} />)
    expect(app).not.toHaveAttribute('inert')
    app.remove()
  })

  it('leaves an element that was already inert for another reason alone', () => {
    const asleep = document.createElement('div')
    asleep.setAttribute('inert', '')
    document.body.appendChild(asleep)

    const { rerender } = render(
      <Modal open onClose={() => {}} title="Details">
        body
      </Modal>,
    )
    rerender(
      <Modal open={false} onClose={() => {}} title="Details">
        body
      </Modal>,
    )

    expect(asleep).toHaveAttribute('inert')
    asleep.remove()
  })

  it('honours initialFocusRef and returnFocusRef', async () => {
    function Harness() {
      const [open, setOpen] = useState(false)
      const name = useRef<HTMLInputElement>(null)
      const next = useRef<HTMLButtonElement>(null)
      return (
        <>
          <button type="button" onClick={() => setOpen(true)}>
            Open
          </button>
          <button type="button" ref={next}>
            Next row
          </button>
          <Modal
            open={open}
            onClose={() => setOpen(false)}
            title="Fix row"
            initialFocusRef={name}
            returnFocusRef={next}
          >
            <input aria-label="Serial" />
            <input aria-label="Name" ref={name} />
          </Modal>
        </>
      )
    }

    render(<Harness />)
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    expect(screen.getByRole('textbox', { name: 'Name' })).toHaveFocus()

    await userEvent.keyboard('{Escape}')
    expect(screen.getByRole('button', { name: 'Next row' })).toHaveFocus()
  })

  it('ignores backdrop clicks when dismissOnBackdrop is off', async () => {
    const onClose = vi.fn()
    render(
      <Modal open onClose={onClose} title="Review" dismissOnBackdrop={false}>
        body
      </Modal>,
    )
    const dialog = screen.getByRole('dialog')
    const backdrop = dialog.previousElementSibling as HTMLElement
    await userEvent.click(backdrop)
    expect(onClose).not.toHaveBeenCalled()
  })

  it('names a title-less dialog with ariaLabel and renders header extras', () => {
    render(
      <Modal open onClose={() => {}} ariaLabel="Import review" headerExtra={<span>2 of 5</span>}>
        body
      </Modal>,
    )
    expect(screen.getByRole('dialog', { name: 'Import review' })).toBeInTheDocument()
    expect(screen.getByText('2 of 5')).toBeInTheDocument()
  })
})

describe('Drawer', () => {
  it('moves focus in, keeps it in, and returns it to the opener', async () => {
    function Harness() {
      const [open, setOpen] = useState(false)
      return (
        <>
          <button type="button" onClick={() => setOpen(true)}>
            Edit
          </button>
          <Drawer open={open} onClose={() => setOpen(false)} title="Edit PC unit">
            <input aria-label="Hostname" />
          </Drawer>
        </>
      )
    }

    render(<Harness />)
    const opener = screen.getByRole('button', { name: 'Edit' })
    await userEvent.click(opener)
    expect(screen.getByRole('dialog', { name: 'Edit PC unit' })).toHaveFocus()

    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'Close panel' })).toHaveFocus()
    await userEvent.tab()
    expect(screen.getByRole('textbox', { name: 'Hostname' })).toHaveFocus()
    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'Close panel' })).toHaveFocus()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(opener).toHaveFocus()
  })

  it('cancels a confirmation raised from a drawer without closing the drawer', async () => {
    // What R2 fixed: the drawer had its own Escape handler outside the modal
    // stack, so one press closed both.
    const closeDrawer = vi.fn()

    function Harness() {
      const [confirming, setConfirming] = useState(false)
      return (
        <Drawer open onClose={closeDrawer} title="Edit PC unit">
          <button type="button" onClick={() => setConfirming(true)}>
            Retire
          </button>
          <ConfirmDialog
            open={confirming}
            onClose={() => setConfirming(false)}
            onConfirm={() => {}}
            title="Retire this PC?"
          />
        </Drawer>
      )
    }

    render(<Harness />)
    const retire = screen.getByRole('button', { name: 'Retire' })
    await userEvent.click(retire)
    expect(screen.getByRole('dialog', { name: 'Retire this PC?' })).toBeInTheDocument()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog', { name: 'Retire this PC?' })).not.toBeInTheDocument()
    expect(closeDrawer).not.toHaveBeenCalled()
    expect(retire).toHaveFocus()

    await userEvent.keyboard('{Escape}')
    expect(closeDrawer).toHaveBeenCalledTimes(1)
  })
})

describe('Popover inside a dialog', () => {
  it('closes on Escape without closing the dialog around it', async () => {
    const onClose = vi.fn()
    render(
      <Modal open onClose={onClose} title="Filters">
        <Popover label="Status" value="All statuses">
          <label>
            <input type="checkbox" /> Open
          </label>
        </Popover>
      </Modal>,
    )

    await userEvent.click(screen.getByRole('button', { name: /Status/ }))
    expect(screen.getByRole('checkbox', { name: 'Open' })).toHaveFocus()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(onClose).not.toHaveBeenCalled()
  })
})
