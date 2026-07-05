import type { AccountStatus, AuthRole } from '@/features/auth/types'

export type { AccountStatus } from '@/features/auth/types'

export interface RoleRef {
  slug: string | null
  name: string | null
}

export interface UserListItem {
  id: string
  name: string
  first_name: string
  last_name: string
  email: string
  employee_number: string | null
  contact_number: string | null
  role: RoleRef
  status: AccountStatus
  force_password_reset: boolean
  last_login_at: string | null
  created_at: string | null
  archived: boolean
}

export interface UserDetail {
  id: string
  first_name: string
  middle_name: string | null
  last_name: string
  name: string
  email: string
  employee_number: string | null
  contact_number: string | null
  profile_picture: string | null
  organization: string
  role: RoleRef
  status: AccountStatus
  effective_permissions: string[]
  additional_permissions: { grants: string[]; denies: string[] }
  registration_source: string | null
  registered_at: string | null
  email_verified_at: string | null
  approved_at: string | null
  rejection: { reason: string | null; at: string | null; by?: string | null }
  last_login_at: string | null
  last_login_ip: string | null
  last_activity_at: string | null
  password_changed_at: string | null
  force_password_reset: boolean
  lockout: { locked: boolean; available_in_seconds: number | null }
  session: { active: boolean }
  created_by?: string | null
  updated_by?: string | null
  updated_at: string | null
  archived_at: string | null
  audit_count?: number
}

export type SortColumn = 'name' | 'email' | 'status' | 'role' | 'created_at' | 'last_login_at'

export interface DirectoryParams {
  search?: string
  status?: string
  role?: string
  trashed?: 'without' | 'with' | 'only'
  sort?: SortColumn
  direction?: 'asc' | 'desc'
  per_page?: number
  page?: number
}

export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export interface Paginated<T> {
  data: T[]
  meta: PageMeta
}

export interface UserMetrics {
  summary: {
    total: number
    active: number
    pending: number
    suspended: number
    rejected: number
    inactive: number
    archived: number
    administrators: number
    technicians: number
    teachers: number
  }
  recent_registrations: Array<{
    id: string
    name: string
    email: string
    role: RoleRef
    status: AccountStatus
    source: string | null
    submitted_at: string | null
  }>
  recent_logins: Array<{
    user: { id: string; name: string } | null
    ip_address: string | null
    browser: string | null
    platform: string | null
    at: string | null
  }>
  recent_actions: Array<{
    actor: { id: string; name: string } | null
    action: string
    label: string
    description: string | null
    at: string | null
  }>
}

export interface ActivityEntry {
  id: number
  action: string
  label: string
  description: string | null
  module: string | null
  actor?: { id: string; name: string } | null
  properties: Record<string, unknown> | null
  ip_address: string | null
  created_at: string | null
}

export interface RoleOption extends AuthRole {
  description: string | null
  is_system: boolean
  users_count?: number
}

export interface PermissionItem {
  name: string
  slug: string
  module: string
  description: string | null
}

export interface PermissionMatrix {
  effective: string[]
  role_permissions: string[]
  grants: string[]
  denies: string[]
  all_permissions: PermissionItem[]
}

export type BulkAction =
  'activate' | 'suspend' | 'reactivate' | 'deactivate' | 'reject' | 'role' | 'notify' | 'export'
