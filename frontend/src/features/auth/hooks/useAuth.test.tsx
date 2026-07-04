import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'
import type { AuthUser } from '../types'
import { AUTH_USER_KEY, useAuth } from './useAuth'

function makeWrapper(user: AuthUser | null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  client.setQueryData(AUTH_USER_KEY, user)
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

const technician: AuthUser = {
  id: 'uuid-1',
  first_name: 'Tay',
  middle_name: null,
  last_name: 'Ng',
  name: 'Tay Ng',
  email: 'tay@example.com',
  employee_number: null,
  contact_number: null,
  status: 'active',
  role: { slug: 'technician', name: 'Technician' },
  permissions: ['tickets.view', 'maintenance.create'],
  last_login_at: null,
  email_verified_at: null,
}

describe('useAuth', () => {
  it('reflects the authenticated principal and its permissions', () => {
    const { result } = renderHook(() => useAuth(), { wrapper: makeWrapper(technician) })

    expect(result.current.isAuthenticated).toBe(true)
    expect(result.current.hasRole('technician')).toBe(true)
    expect(result.current.hasPermission('maintenance.create')).toBe(true)
    expect(result.current.hasPermission('users.update')).toBe(false)
  })

  it('reports an unauthenticated state when there is no user', () => {
    const { result } = renderHook(() => useAuth(), { wrapper: makeWrapper(null) })

    expect(result.current.isAuthenticated).toBe(false)
    expect(result.current.hasPermission('tickets.view')).toBe(false)
  })
})
