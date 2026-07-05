import { api } from '@/services/api'
import type { RegistrationSummary } from '@/features/auth/types'
import type { Paginated } from '../types'

export interface RegistrationParams {
  search?: string
  role?: string
  page?: number
  per_page?: number
}

export async function listRegistrations(
  params: RegistrationParams,
): Promise<Paginated<RegistrationSummary>> {
  const clean: Record<string, string | number> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') clean[key] = value as string | number
  }
  const { data } = await api.get<Paginated<RegistrationSummary>>('/admin/registrations', {
    params: clean,
  })
  return data
}

export async function approveRegistration(id: string): Promise<RegistrationSummary> {
  const { data } = await api.post<{ data: RegistrationSummary }>(
    `/admin/registrations/${id}/approve`,
  )
  return data.data
}

export async function rejectRegistration(
  id: string,
  reason?: string,
): Promise<RegistrationSummary> {
  const { data } = await api.post<{ data: RegistrationSummary }>(
    `/admin/registrations/${id}/reject`,
    { reason },
  )
  return data.data
}

export interface UpdateRegistrationPayload {
  first_name: string
  middle_name?: string
  last_name: string
  email: string
  employee_number?: string
  contact_number?: string
  role: 'teacher' | 'technician'
}

export async function updateRegistration(
  id: string,
  payload: UpdateRegistrationPayload,
): Promise<RegistrationSummary> {
  const { data } = await api.put<{ data: RegistrationSummary }>(
    `/admin/registrations/${id}`,
    payload,
  )
  return data.data
}
