import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { AnnouncementForm } from './AnnouncementForm'
import type { Announcement } from '../types'

/**
 * The announcement composer (SRS FR-NOT-010, UC-13).
 *
 * Two properties matter more than the field wiring, and both are decisions
 * rather than conveniences:
 *
 *  1. **Saving never publishes.** Publishing is its own, explicitly named
 *     button (owner request 2026-10-19); "Save draft"/"Save changes" and the
 *     Enter key never notify an audience, and a published announcement has no
 *     Publish here at all (decision D7). There is still no "Active" switch.
 *  2. **The window is validated before it is sent.** The database enforces
 *     `ends_at > starts_at` and the API repeats it, but an administrator should
 *     be told in the form rather than by a 422.
 */
function renderForm(announcement?: Announcement) {
  const onSubmit = vi.fn().mockResolvedValue(undefined)
  const onCancel = vi.fn()

  render(<AnnouncementForm announcement={announcement} onSubmit={onSubmit} onCancel={onCancel} />)

  return { onSubmit, onCancel }
}

const existing: Announcement = {
  id: 'a-1',
  title: 'Network maintenance',
  content: 'The staff network is unavailable on Saturday morning.',
  audience: 'technicians',
  is_pinned: true,
  starts_at: null,
  ends_at: null,
  created_at: '2026-09-06T08:00:00+00:00',
  is_active: true,
}

const draft: Announcement = { ...existing, id: 'a-2', is_active: false }

describe('what the form offers', () => {
  it('collects the fields FR-NOT-010 names', () => {
    renderForm()

    expect(screen.getByLabelText(/^Title/)).toBeInTheDocument()
    expect(screen.getByLabelText(/^Announcement/)).toBeInTheDocument()
    expect(screen.getByLabelText(/^Audience/)).toBeInTheDocument()
    expect(screen.getByLabelText(/show from/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/show until/i)).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: /pin to the top/i })).toBeInTheDocument()
  })

  it('offers the four audiences the schema allows', () => {
    renderForm()

    const select = screen.getByLabelText(/^Audience/)
    expect(select).toHaveValue('all')
    expect(screen.getByRole('option', { name: 'Teachers' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Technicians' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Administrators' })).toBeInTheDocument()
  })

  it('has no "Active" switch — publication is a button, never a field', () => {
    renderForm()

    expect(screen.queryByRole('checkbox', { name: /active|publish/i })).not.toBeInTheDocument()
  })

  it('offers Publish, Save draft and Cancel on a new announcement', () => {
    renderForm()

    expect(screen.getByRole('button', { name: /^publish$/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^save draft$/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^cancel$/i })).toBeInTheDocument()
    expect(screen.getByText(/notifies no one/i)).toBeInTheDocument()
  })

  it('offers Save and publish on an unpublished draft', () => {
    renderForm(draft)

    expect(screen.getByRole('button', { name: /^save and publish$/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^save changes$/i })).toBeInTheDocument()
  })

  it('offers no Publish on a published announcement — editing it notifies nobody', () => {
    renderForm(existing)

    expect(screen.queryByRole('button', { name: /publish/i })).not.toBeInTheDocument()
    expect(screen.getByText(/leaves the audience undisturbed/i)).toBeInTheDocument()
  })

  it('loads an existing announcement for editing', () => {
    renderForm(existing)

    expect(screen.getByLabelText(/^Title/)).toHaveValue('Network maintenance')
    expect(screen.getByLabelText(/^Audience/)).toHaveValue('technicians')
    expect(screen.getByRole('checkbox', { name: /pin to the top/i })).toBeChecked()
    expect(screen.getByRole('button', { name: /save changes/i })).toBeInTheDocument()
  })
})

describe('which intent each control sends', () => {
  async function fillIn(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText(/^Title/), 'Lab closed')
    await user.type(screen.getByLabelText(/^Announcement/), 'Lab 2 is closed today.')
  }

  it('publishes from Publish', async () => {
    const user = userEvent.setup()
    const { onSubmit } = renderForm()

    await fillIn(user)
    await user.click(screen.getByRole('button', { name: /^publish$/i }))

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1))
    expect(onSubmit.mock.calls[0][1]).toBe('publish')
  })

  it('only saves from Save draft', async () => {
    const user = userEvent.setup()
    const { onSubmit } = renderForm()

    await fillIn(user)
    await user.click(screen.getByRole('button', { name: /^save draft$/i }))

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1))
    expect(onSubmit.mock.calls[0][1]).toBe('save')
  })

  it('only saves on Enter — a stray keypress never notifies anyone', async () => {
    const user = userEvent.setup()
    const { onSubmit } = renderForm()

    await fillIn(user)
    await user.type(screen.getByLabelText(/^Title/), '{Enter}')

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1))
    expect(onSubmit.mock.calls[0][1]).toBe('save')
  })

  it('does not publish an invalid announcement', async () => {
    const user = userEvent.setup()
    const { onSubmit } = renderForm()

    await user.click(screen.getByRole('button', { name: /^publish$/i }))

    expect(await screen.findByText(/give the announcement a title/i)).toBeInTheDocument()
    expect(onSubmit).not.toHaveBeenCalled()
  })
})

describe('validation', () => {
  it('refuses an empty announcement', async () => {
    const user = userEvent.setup()
    const { onSubmit } = renderForm()

    await user.click(screen.getByRole('button', { name: /^save draft$/i }))

    expect(await screen.findByText(/give the announcement a title/i)).toBeInTheDocument()
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('refuses a window that ends before it starts', async () => {
    const user = userEvent.setup()
    const { onSubmit } = renderForm()

    await user.type(screen.getByLabelText(/^Title/), 'Backwards window')
    await user.type(screen.getByLabelText(/^Announcement/), 'This should not save.')
    await user.type(screen.getByLabelText(/show from/i), '2026-09-10T09:00')
    await user.type(screen.getByLabelText(/show until/i), '2026-09-08T09:00')
    await user.click(screen.getByRole('button', { name: /^save draft$/i }))

    expect(await screen.findByText(/must come after its start/i)).toBeInTheDocument()
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('accepts an open-ended window — both bounds are optional', async () => {
    const user = userEvent.setup()
    const { onSubmit } = renderForm()

    await user.type(screen.getByLabelText(/^Title/), 'Open ended')
    await user.type(screen.getByLabelText(/^Announcement/), 'No dates at all.')
    await user.click(screen.getByRole('button', { name: /^save draft$/i }))

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1))
    expect(onSubmit.mock.calls[0][0]).toMatchObject({
      title: 'Open ended',
      audience: 'all',
    })
  })
})

describe('cancelling', () => {
  it('hands control back without submitting', async () => {
    const user = userEvent.setup()
    const { onSubmit, onCancel } = renderForm()

    await user.click(screen.getByRole('button', { name: /cancel/i }))

    expect(onCancel).toHaveBeenCalled()
    expect(onSubmit).not.toHaveBeenCalled()
  })
})
