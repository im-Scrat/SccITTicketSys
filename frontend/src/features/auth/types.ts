export type AccountStatus = 'pending' | 'active' | 'rejected' | 'suspended' | 'inactive'

export interface AuthRole {
  slug: string
  name: string
}

export interface AuthUser {
  id: string
  first_name: string
  middle_name: string | null
  last_name: string
  name: string
  email: string
  employee_number: string | null
  contact_number: string | null
  status: AccountStatus
  role: AuthRole
  permissions: string[]
  force_password_reset: boolean
  last_login_at: string | null
  email_verified_at: string | null
}

export interface LoginPayload {
  email: string
  password: string
  remember: boolean
}

export interface RegisterPayload {
  first_name: string
  middle_name?: string
  last_name: string
  email: string
  employee_number?: string
  contact_number?: string
  role: 'teacher' | 'technician'
  password: string
  password_confirmation: string
}

export interface RegistrationSummary {
  id: string
  name: string
  first_name: string
  last_name: string
  email: string
  employee_number: string | null
  contact_number: string | null
  role: AuthRole
  status: AccountStatus
  rejection_reason: string | null
  submitted_at: string | null
  rejected_at: string | null
}

/** Shape of the JSON body returned by an account-status (403) login response. */
export interface AccountStatusError {
  message: string
  code: Exclude<AccountStatus, 'active'>
  status: AccountStatus
  reason: string | null
}
