import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  fetchDashboard,
  fetchPermissionMatrix,
  fetchRoles,
  fetchUser,
  fetchUserAudit,
  listUsers,
} from '../api/usersApi'
import type { DirectoryParams } from '../types'

export const usersKeys = {
  all: ['users'] as const,
  list: (params: DirectoryParams) => ['users', 'list', params] as const,
  detail: (id: string) => ['users', 'detail', id] as const,
  audit: (id: string, page: number) => ['users', 'audit', id, page] as const,
  metrics: () => ['users', 'metrics'] as const,
  roles: () => ['users', 'roles'] as const,
  permissions: (id: string) => ['users', 'permissions', id] as const,
}

export function useUsersList(params: DirectoryParams) {
  return useQuery({
    queryKey: usersKeys.list(params),
    queryFn: () => listUsers(params),
    placeholderData: keepPreviousData, // smooth page/filter transitions
  })
}

export function useUserDetail(id: string | undefined) {
  return useQuery({
    queryKey: usersKeys.detail(id ?? ''),
    queryFn: () => fetchUser(id as string),
    enabled: Boolean(id),
  })
}

export function useUserAudit(id: string | undefined, page = 1) {
  return useQuery({
    queryKey: usersKeys.audit(id ?? '', page),
    queryFn: () => fetchUserAudit(id as string, page),
    enabled: Boolean(id),
    placeholderData: keepPreviousData,
  })
}

export function useUserMetrics() {
  return useQuery({ queryKey: usersKeys.metrics(), queryFn: fetchDashboard })
}

export function useRoles() {
  return useQuery({ queryKey: usersKeys.roles(), queryFn: fetchRoles, staleTime: 5 * 60_000 })
}

export function usePermissionMatrix(id: string | undefined) {
  return useQuery({
    queryKey: usersKeys.permissions(id ?? ''),
    queryFn: () => fetchPermissionMatrix(id as string),
    enabled: Boolean(id),
  })
}
