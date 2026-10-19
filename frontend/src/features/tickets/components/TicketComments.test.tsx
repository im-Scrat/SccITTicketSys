import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TicketComment } from '../types'
import { TicketComments } from './TicketComments'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
  },
}))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({ hasPermission: () => true, hasRole: () => false }),
}))

function comment(overrides: Partial<TicketComment>): TicketComment {
  return {
    id: 'c-x',
    body: 'A comment',
    removed: false,
    is_internal: false,
    is_edited: false,
    edited_at: null,
    author: { id: null, name: 'Maria Santos', role: 'teacher' },
    is_mine: false,
    can_edit: false,
    can_remove: false,
    parent_id: null,
    moderated_by: null,
    created_at: '2026-10-13T08:00:00Z',
    ...overrides,
  }
}

const REPLY = comment({
  id: 'c-2',
  body: 'Lab 4 too.',
  author: { id: null, name: 'Jo Reyes', role: 'teacher' },
  parent_id: 'c-1',
})

const ROOT = comment({ id: 'c-1', body: 'Started after the update.', replies: [REPLY] })

function serve(rows: TicketComment[]) {
  get.mockResolvedValue({
    data: {
      data: rows,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length, from: 1, to: 1 },
    },
  })
}

function renderComments(props: Partial<Parameters<typeof TicketComments>[0]> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <TicketComments ticketId="t-1" canComment canCommentInternal={false} {...props} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  post.mockResolvedValue({ data: { data: comment({ id: 'c-new' }) } })
  serve([ROOT])
})

describe('TicketComments threads', () => {
  it('shows replies inside their thread', async () => {
    renderComments()

    const replies = await screen.findByRole('list', { name: 'Replies to Maria Santos' })
    expect(within(replies).getByText('Lab 4 too.')).toBeInTheDocument()
    // A reply is not repeated as a thread of its own.
    expect(screen.getAllByText('Lab 4 too.')).toHaveLength(1)
  })

  it('posts a reply into the thread it answers', async () => {
    renderComments()

    await userEvent.click(await screen.findByRole('button', { name: 'Reply to Maria Santos' }))

    const field = screen.getByRole('textbox', { name: 'Your reply to Maria Santos' })
    expect(field).toHaveFocus()
    expect(field).toHaveValue('')

    await userEvent.type(field, 'Same in Lab 2.')
    await userEvent.click(screen.getByRole('button', { name: 'Post reply' }))

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith('/tickets/t-1/comments', {
        body: 'Same in Lab 2.',
        parent: 'c-1',
      }),
    )
  })

  it('answers a reply in the same thread, mentioning its author', async () => {
    renderComments()

    await userEvent.click(await screen.findByRole('button', { name: 'Reply to Jo Reyes' }))

    // Labelled with the person answered; posted to the thread's top comment.
    const field = screen.getByRole('textbox', { name: 'Your reply to Jo Reyes' })
    expect(field).toHaveValue('@Jo Reyes ')
    // The mention alone is not a reply yet.
    expect(screen.getByRole('button', { name: 'Post reply' })).toBeDisabled()

    await userEvent.type(field, 'thanks, noted')
    await userEvent.click(screen.getByRole('button', { name: 'Post reply' }))

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith('/tickets/t-1/comments', {
        body: '@Jo Reyes thanks, noted',
        parent: 'c-1',
      }),
    )
  })

  it('does not mention yourself when answering your own reply', async () => {
    serve([{ ...ROOT, replies: [{ ...REPLY, is_mine: true }] }])
    renderComments()

    await userEvent.click(await screen.findByRole('button', { name: 'Reply to Jo Reyes' }))

    expect(screen.getByRole('textbox', { name: 'Your reply to Jo Reyes' })).toHaveValue('')
  })

  it('offers no reply on a removed comment or its thread', async () => {
    serve([{ ...ROOT, removed: true, body: null }])
    renderComments()

    await screen.findByText(/this comment was removed/i)
    expect(screen.queryByRole('button', { name: /^reply to/i })).not.toBeInTheDocument()
  })

  it('closes the reply with Escape, keeping what was there before', async () => {
    renderComments()

    const opener = await screen.findByRole('button', { name: 'Reply to Maria Santos' })
    await userEvent.click(opener)
    await userEvent.keyboard('{Escape}')

    expect(
      screen.queryByRole('textbox', { name: 'Your reply to Maria Santos' }),
    ).not.toBeInTheDocument()
    await waitFor(() => expect(opener).toHaveFocus())
  })

  it('offers no writing at all when read-only', async () => {
    renderComments({ readOnly: true })

    await screen.findByText('Started after the update.')
    expect(screen.queryByRole('button', { name: /^reply to/i })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Add a comment')).not.toBeInTheDocument()
  })
})

describe('TicketComments edit and remove', () => {
  it("offers neither Edit nor Remove on someone else's comment", async () => {
    renderComments()

    await screen.findByText('Started after the update.')
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument()
  })

  it('follows the server: the author inside the window may edit and remove', async () => {
    serve([comment({ id: 'c-9', body: 'Mine.', is_mine: true, can_edit: true, can_remove: true })])
    renderComments()

    await screen.findByText('Mine.')
    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remove' })).toBeInTheDocument()
  })

  it('hides Edit on your own comment once the server closes the window', async () => {
    serve([
      comment({ id: 'c-9', body: 'Mine.', is_mine: true, can_edit: false, can_remove: false }),
    ])
    renderComments()

    await screen.findByText('Mine.')
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument()
  })

  it('gives a moderating administrator Remove but never Edit', async () => {
    serve([comment({ id: 'c-9', body: 'Theirs.', can_edit: false, can_remove: true })])
    renderComments()

    await screen.findByText('Theirs.')
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remove' })).toBeInTheDocument()
  })
})
