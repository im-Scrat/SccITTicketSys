import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { fetchCurrentUser } from '../api/authApi'
import type { AuthUser } from '../types'

export const AUTH_USER_KEY = ['auth', 'user'] as const

/**
 * The current-principal query. A 401 resolves to `null` (unauthenticated)
 * rather than an error, so guards can branch cleanly. Cached indefinitely and
 * mutated directly by the login/logout hooks.
 */
export function useCurrentUser() {
  return useQuery<AuthUser | null>({
    queryKey: AUTH_USER_KEY,
    queryFn: async () => {
      try {
        return await fetchCurrentUser()
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 401) {
          return null
        }
        throw error
      }
    },
    staleTime: Infinity,
    retry: false,
  })
}

/**
 * Ergonomic auth accessor for components and guards. Permission/role checks here
 * are for UX only — the backend remains the source of truth.
 */
export function useAuth() {
  const { data, isLoading, isFetched } = useCurrentUser()
  const user = data ?? null
  const permissions = user?.permissions ?? []

  return {
    user,
    isAuthenticated: user !== null,
    isLoading,
    isReady: isFetched,
    permissions,
    hasPermission: (permission: string) => permissions.includes(permission),
    hasRole: (role: string) => user?.role.slug === role,
  }
}
