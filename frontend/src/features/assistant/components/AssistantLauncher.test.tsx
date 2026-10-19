import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import type { AuthUser } from '@/features/auth/types'
import { AssistantLauncher } from './AssistantLauncher'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    delete: vi.fn(),
  },
}))

function renderLauncher(permissions: string[] = ['ai.view']) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  queryClient.setQueryData(AUTH_USER_KEY, {
    id: 'uuid-1',
    name: 'Sam Reyes',
    status: 'active',
    role: { name: 'teacher', slug: 'teacher' },
    permissions,
  } as unknown as AuthUser)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AssistantLauncher />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function statusIs(available: boolean) {
  get.mockImplementation((url: string) =>
    url === '/ai/assistant/status'
      ? Promise.resolve({ data: { data: { available } } })
      : Promise.resolve({ data: { data: [] } }),
  )
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
})

describe('AssistantLauncher', () => {
  it('appears when the server says the assistant is available', async () => {
    statusIs(true)
    renderLauncher()

    expect(await screen.findByRole('button', { name: 'Ask the assistant' })).toBeInTheDocument()
  })

  it('is absent while the assistant is unavailable', async () => {
    statusIs(false)
    renderLauncher()

    await waitFor(() => expect(get).toHaveBeenCalledWith('/ai/assistant/status'))
    expect(screen.queryByRole('button', { name: 'Ask the assistant' })).not.toBeInTheDocument()
  })

  it('is absent without ai.view, and never asks the server', () => {
    statusIs(true)
    renderLauncher([])

    expect(screen.queryByRole('button', { name: 'Ask the assistant' })).not.toBeInTheDocument()
  })

  it('opens a non-modal panel and closes it with Escape, returning focus', async () => {
    statusIs(true)
    renderLauncher()

    const launcher = await screen.findByRole('button', { name: 'Ask the assistant' })
    fireEvent.click(launcher)

    const dialog = screen.getByRole('dialog', { name: 'AI assistant' })
    expect(dialog).toHaveAttribute('aria-modal', 'false')
    expect(screen.getByText(/It cannot change anything/)).toBeInTheDocument()

    fireEvent.keyDown(dialog, { key: 'Escape' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ask the assistant' })).toHaveFocus()
  })

  it('sends a question and shows the answer with its ticket source', async () => {
    statusIs(true)
    post.mockResolvedValue({
      data: {
        data: {
          conversation_id: 'conv-1',
          answer: 'Check the HDMI cable.',
          citations: [],
          ai_generated: true,
          advisory: true,
        },
      },
    })
    get.mockImplementation((url: string) => {
      if (url === '/ai/assistant/status')
        return Promise.resolve({ data: { data: { available: true } } })
      if (url === '/ai/assistant/conversations/conv-1') {
        return Promise.resolve({
          data: {
            data: {
              id: 'conv-1',
              messages: [
                { sender: 'user', text: 'No signal on the projector', citations: [], at: null },
                {
                  sender: 'assistant',
                  text: 'Check the HDMI cable.',
                  citations: [
                    {
                      ref: null,
                      type: 'ticket',
                      title: 'Projector dead',
                      label: 'TKT-1',
                      uuid: 't-1',
                      url: '/app/tickets/t-1',
                    },
                  ],
                  at: null,
                },
              ],
            },
          },
        })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    renderLauncher()

    fireEvent.click(await screen.findByRole('button', { name: 'Ask the assistant' }))
    fireEvent.change(screen.getByLabelText('Your question'), {
      target: { value: 'No signal on the projector' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))

    expect(await screen.findByText('Check the HDMI cable.')).toBeInTheDocument()
    expect(post).toHaveBeenCalledWith('/ai/assistant/messages', {
      message: 'No signal on the projector',
      conversation_id: null,
    })
    expect(screen.getByRole('link', { name: /TKT-1/ })).toHaveAttribute('href', '/app/tickets/t-1')
  })
})
