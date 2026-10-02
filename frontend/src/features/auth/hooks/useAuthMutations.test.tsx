import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { login, logout } from '../api/authApi'
import type { AuthUser } from '../types'
import { AUTH_USER_KEY } from './useAuth'
import { useLogin, useLogout } from './useAuthMutations'

vi.mock('../api/authApi', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  register: vi.fn(),
  forgotPassword: vi.fn(),
  resetPassword: vi.fn(),
  changePassword: vi.fn(),
  updateProfile: vi.fn(),
}))

const teacher: AuthUser = {
  id: 'uuid-b',
  first_name: 'Bea',
  middle_name: null,
  last_name: 'Cruz',
  name: 'Bea Cruz',
  email: 'bea@example.com',
  employee_number: null,
  contact_number: null,
  status: 'active',
  role: { slug: 'teacher', name: 'Teacher' },
  permissions: ['tickets.view'],
  last_login_at: null,
  email_verified_at: null,
}

/** A client holding what a previous principal fetched: tickets, a dashboard, an AI analysis. */
function clientWithPrincipalCache(): QueryClient {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  client.setQueryData(AUTH_USER_KEY, { ...teacher, id: 'uuid-a', name: 'Previous User' })
  client.setQueryData(['tickets', 'mine'], [{ id: 't-1', title: 'Previous user’s ticket' }])
  client.setQueryData(['dashboard', 'widgets'], { role: 'teacher' })
  client.setQueryData(['tickets', 'detail', 't-1', 'ai-analysis'], { summary: 'private' })
  return client
}

function wrapperFor(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

/** Every cached key outside the auth namespace. */
function principalKeys(client: QueryClient): unknown[] {
  return client
    .getQueryCache()
    .getAll()
    .map((query) => query.queryKey)
    .filter((key) => key[0] !== AUTH_USER_KEY[0])
}

describe('auth mutations drop the previous principal’s cache', () => {
  beforeEach(() => vi.clearAllMocks())

  it('removes every per-user query on logout, not only notifications', async () => {
    vi.mocked(logout).mockResolvedValue(undefined as never)
    const client = clientWithPrincipalCache()
    const { result } = renderHook(() => useLogout(), { wrapper: wrapperFor(client) })

    await act(() => result.current.mutateAsync())

    expect(principalKeys(client)).toEqual([])
    expect(client.getQueryData(AUTH_USER_KEY)).toBeNull()
  })

  it('still clears the cache when the logout request fails', async () => {
    vi.mocked(logout).mockRejectedValue(new Error('network'))
    const client = clientWithPrincipalCache()
    const { result } = renderHook(() => useLogout(), { wrapper: wrapperFor(client) })

    await act(() => result.current.mutateAsync().catch(() => undefined))

    expect(principalKeys(client)).toEqual([])
  })

  it('removes a previous principal’s cache when someone signs in', async () => {
    vi.mocked(login).mockResolvedValue(teacher)
    const client = clientWithPrincipalCache()
    const { result } = renderHook(() => useLogin(), { wrapper: wrapperFor(client) })

    await act(() => result.current.mutateAsync({ email: teacher.email, password: 'x' } as never))

    expect(principalKeys(client)).toEqual([])
    expect(client.getQueryData(AUTH_USER_KEY)).toEqual(teacher)
  })
})
