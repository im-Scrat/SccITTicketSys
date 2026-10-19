import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { MailConfiguration } from '../types'
import OutgoingMailPage from './OutgoingMailPage'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

const CONFIG: MailConfiguration = {
  mailer: 'smtp',
  transport: 'smtp',
  host: 'mail.smtp2go.com',
  port: 2525,
  scheme: 'smtp',
  require_tls: true,
  timeout_seconds: 10,
  ehlo_domain: 'sccit-pilot.onrender.com',
  username_set: true,
  password_set: true,
  from_address: 'noreply@example.org',
  from_name: 'SccIT',
  redirect: 'none',
  redirect_to: null,
  frontend_url: 'https://sccit-pilot.onrender.com',
  reports_deliver_externally: false,
  warnings: [],
}

function renderPage(config: Partial<MailConfiguration> = {}) {
  get.mockResolvedValue({ data: { data: { ...CONFIG, ...config } } })
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <OutgoingMailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
})

describe('OutgoingMailPage', () => {
  it('shows the relay and its safeguards, never a credential', async () => {
    renderPage()

    expect(await screen.findByText('mail.smtp2go.com:2525')).toBeInTheDocument()
    expect(screen.getByText('Encryption required')).toBeInTheDocument()
    expect(screen.getByText('Credentials present on the server')).toBeInTheDocument()
    expect(get).toHaveBeenCalledWith('/admin/system/mail')
  })

  it('tests the connection without sending, and explains the outcome', async () => {
    const user = userEvent.setup()
    post.mockResolvedValue({
      data: { data: { ok: false, outcome: 'smtp_auth_failed', smtp_code: 535 } },
    })
    renderPage()

    await user.click(await screen.findByRole('button', { name: /test the connection/i }))

    expect(post).toHaveBeenCalledWith('/admin/system/mail/check')
    expect(
      await screen.findByText('The relay refused the username or password. (SMTP 535)'),
    ).toBeInTheDocument()
  })

  it('reports a successful connection in the server’s own, configuration-aware words', async () => {
    const user = userEvent.setup()
    post.mockResolvedValue({
      data: {
        data: {
          ok: true,
          outcome: 'ok',
          smtp_code: null,
          summary:
            'Connected, encryption not required by this configuration, no credentials configured. No message was sent.',
        },
      },
    })
    renderPage({ require_tls: false, username_set: false, password_set: false })

    await user.click(await screen.findByRole('button', { name: /test the connection/i }))

    expect(
      await screen.findByText(/encryption not required by this configuration/),
    ).toBeInTheDocument()
    expect(screen.queryByText(/encrypted and signed in/)).not.toBeInTheDocument()
  })

  it('sends the test email with no recipient of its own, and says accepted is not delivered', async () => {
    const user = userEvent.setup()
    post.mockResolvedValue({
      data: {
        data: {
          ok: true,
          outcome: 'accepted',
          smtp_code: null,
          message_id: 'abc@relay',
          sent_to: 'owner@example.org',
        },
      },
    })
    renderPage()

    await user.click(await screen.findByRole('button', { name: /send a test email to me/i }))

    expect(post).toHaveBeenCalledWith('/admin/system/mail/test')
    expect(post.mock.calls[0]).toHaveLength(1)
    expect(await screen.findByText('Test email to owner@example.org')).toBeInTheDocument()
    expect(screen.getByText(/accepted is not delivered/i)).toBeInTheDocument()
  })

  it('warns when the test redirect is on, and when production is ignoring it', async () => {
    renderPage({ redirect: 'active', redirect_to: 'o****@example.org' })
    expect(await screen.findByText('Test redirect is on')).toBeInTheDocument()
    expect(screen.getByText(/o\*\*\*\*@example.org/)).toBeInTheDocument()
  })

  it('lists configuration warnings from the server', async () => {
    renderPage({ warnings: ['FRONTEND_URL points at localhost: links will not work.'] })
    expect(await screen.findByText('Check the configuration')).toBeInTheDocument()
    expect(screen.getByText(/FRONTEND_URL points at localhost/)).toBeInTheDocument()
  })
})
