import { api } from '@/services/api'
import type { TechnicianDetail, TechnicianSummary } from '../types'

export async function fetchTechnicianOverview(): Promise<TechnicianSummary[]> {
  const { data } = await api.get<{ data: TechnicianSummary[] }>('/admin/technicians/overview')
  return data.data
}

export async function fetchTechnician(id: string): Promise<TechnicianDetail> {
  const { data } = await api.get<{ data: TechnicianDetail }>(`/admin/technicians/${id}`)
  return data.data
}
