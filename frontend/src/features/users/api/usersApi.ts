import { api } from '@/services/api'
import type {
  ActivityEntry,
  BulkAction,
  DirectoryParams,
  Paginated,
  PermissionMatrix,
  RoleOption,
  UserDetail,
  UserListItem,
  UserMetrics,
} from '../types'

function cleanParams(params: DirectoryParams): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') out[key] = value as string | number
  }
  return out
}

export async function listUsers(params: DirectoryParams): Promise<Paginated<UserListItem>> {
  const { data } = await api.get<Paginated<UserListItem>>('/admin/users', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchUser(id: string): Promise<UserDetail> {
  const { data } = await api.get<{ data: UserDetail }>(`/admin/users/${id}`)
  return data.data
}

export async function fetchDashboard(): Promise<UserMetrics> {
  const { data } = await api.get<{ data: UserMetrics }>('/admin/users/dashboard')
  return data.data
}

export async function fetchUserAudit(id: string, page = 1): Promise<Paginated<ActivityEntry>> {
  const { data } = await api.get<Paginated<ActivityEntry>>(`/admin/users/${id}/audit`, {
    params: { page },
  })
  return data
}

export async function fetchRoles(): Promise<RoleOption[]> {
  const { data } = await api.get<{ data: RoleOption[] }>('/admin/roles')
  return data.data
}

export async function fetchPermissionMatrix(id: string): Promise<PermissionMatrix> {
  const { data } = await api.get<{ data: PermissionMatrix }>(`/admin/users/${id}/permissions`)
  return data.data
}

export interface CreateUserPayload {
  role: string
  first_name: string
  middle_name?: string
  last_name: string
  email: string
  employee_number?: string
  contact_number?: string
  status?: string
  password: string
  password_confirmation: string
  force_password_reset?: boolean
}

export async function createUser(payload: CreateUserPayload): Promise<UserDetail> {
  const { data } = await api.post<{ data: UserDetail }>('/admin/users', payload)
  return data.data
}

export interface UpdateUserPayload {
  first_name: string
  middle_name?: string
  last_name: string
  email: string
  employee_number?: string
  contact_number?: string
}

export async function updateUser(id: string, payload: UpdateUserPayload): Promise<UserDetail> {
  const { data } = await api.put<{ data: UserDetail }>(`/admin/users/${id}`, payload)
  return data.data
}

export async function archiveUser(id: string): Promise<void> {
  await api.delete(`/admin/users/${id}`)
}

export async function restoreUser(id: string): Promise<UserDetail> {
  const { data } = await api.post<{ data: UserDetail }>(`/admin/users/${id}/restore`)
  return data.data
}

export type LifecycleAction = 'activate' | 'suspend' | 'reactivate' | 'deactivate'

export async function lifecycleAction(
  id: string,
  action: LifecycleAction,
  reason?: string,
): Promise<UserDetail> {
  const { data } = await api.post<{ data: UserDetail }>(`/admin/users/${id}/${action}`, { reason })
  return data.data
}

export async function changeUserRole(id: string, role: string): Promise<UserDetail> {
  const { data } = await api.put<{ data: UserDetail }>(`/admin/users/${id}/role`, { role })
  return data.data
}

export async function setUserPermissions(
  id: string,
  grants: string[],
  denies: string[],
): Promise<UserDetail> {
  const { data } = await api.put<{ data: UserDetail }>(`/admin/users/${id}/permissions`, {
    grants,
    denies,
  })
  return data.data
}

export async function forcePasswordReset(id: string, required = true): Promise<UserDetail> {
  const { data } = await api.post<{ data: UserDetail }>(`/admin/users/${id}/force-password-reset`, {
    required,
  })
  return data.data
}

export async function unlockAccount(id: string): Promise<{ message: string }> {
  const { data } = await api.post<{ message: string }>(`/admin/users/${id}/unlock`)
  return data
}

export async function sendPasswordReset(id: string): Promise<{ message: string }> {
  const { data } = await api.post<{ message: string }>(`/admin/users/${id}/send-password-reset`)
  return data
}

export async function resendApproval(id: string): Promise<{ message: string }> {
  const { data } = await api.post<{ message: string }>(`/admin/users/${id}/resend-approval`)
  return data
}

export async function resendRejection(id: string): Promise<{ message: string }> {
  const { data } = await api.post<{ message: string }>(`/admin/users/${id}/resend-rejection`)
  return data
}

export interface BulkPayload {
  action: BulkAction
  ids: string[]
  role?: string
  reason?: string
  subject?: string
  message?: string
  format?: 'csv' | 'xlsx'
  columns?: string[]
}

export async function bulkUserAction(
  payload: BulkPayload,
): Promise<{ message: string; count: number }> {
  const { data } = await api.post<{ message: string; count: number }>('/admin/users/bulk', payload)
  return data
}

/** Turn a blob response into a browser download. */
function triggerDownload(blob: Blob, disposition: string, fallback: string): void {
  const match = disposition.match(/filename="?([^"]+)"?/)
  const filename = match?.[1] ?? fallback
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

/** Download the (filtered) directory export. */
export async function downloadExport(
  params: DirectoryParams,
  format: 'csv' | 'xlsx',
): Promise<void> {
  const response = await api.get('/admin/users/export', {
    params: { ...cleanParams(params), format },
    responseType: 'blob',
  })
  triggerDownload(
    response.data as Blob,
    String(response.headers['content-disposition'] ?? ''),
    `users.${format}`,
  )
}

/** Download an export of the specifically selected users (bulk "export"). */
export async function downloadSelected(ids: string[], format: 'csv' | 'xlsx'): Promise<void> {
  const response = await api.post(
    '/admin/users/bulk',
    { action: 'export', ids, format },
    { responseType: 'blob' },
  )
  triggerDownload(
    response.data as Blob,
    String(response.headers['content-disposition'] ?? ''),
    `users.${format}`,
  )
}
