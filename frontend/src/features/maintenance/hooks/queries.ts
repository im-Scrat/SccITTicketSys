import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  fetchMaintenanceAudit,
  fetchMaintenanceDashboard,
  fetchMaintenanceDirectory,
  fetchMaintenanceHistory,
  fetchMaintenanceOptions,
  fetchMaintenanceQueue,
  fetchMaintenanceRecord,
  fetchScheduledMaintenance,
} from '../api/maintenanceApi'
import type { MaintenanceParams } from '../types'

export const maintenanceKeys = {
  all: ['maintenance'] as const,
  options: () => ['maintenance', 'options'] as const,
  queue: (params: MaintenanceParams) => ['maintenance', 'queue', params] as const,
  history: (params: MaintenanceParams) => ['maintenance', 'history', params] as const,
  scheduled: (params: MaintenanceParams) => ['maintenance', 'scheduled', params] as const,
  detail: (id: string) => ['maintenance', 'detail', id] as const,
  audit: (id: string, page: number) => ['maintenance', 'audit', id, page] as const,
  directory: (params: MaintenanceParams) => ['maintenance', 'directory', params] as const,
  dashboard: () => ['maintenance', 'dashboard'] as const,
}

/** Vocabularies change rarely; hold them for the session. */
export function useMaintenanceOptions(enabled = true) {
  return useQuery({
    queryKey: maintenanceKeys.options(),
    queryFn: fetchMaintenanceOptions,
    staleTime: 5 * 60 * 1000,
    enabled,
  })
}

/**
 * A technician's open work.
 *
 * `keepPreviousData` across every paged list so changing a filter does not blank
 * the table — the rows dim and settle rather than disappearing, which is the
 * pattern the ticket and asset directories already use.
 */
export function useMaintenanceQueue(params: MaintenanceParams, enabled = true) {
  return useQuery({
    queryKey: maintenanceKeys.queue(params),
    queryFn: () => fetchMaintenanceQueue(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useMaintenanceHistory(params: MaintenanceParams, enabled = true) {
  return useQuery({
    queryKey: maintenanceKeys.history(params),
    queryFn: () => fetchMaintenanceHistory(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useScheduledMaintenance(params: MaintenanceParams, enabled = true) {
  return useQuery({
    queryKey: maintenanceKeys.scheduled(params),
    queryFn: () => fetchScheduledMaintenance(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useMaintenanceRecord(id: string | undefined) {
  return useQuery({
    queryKey: maintenanceKeys.detail(id ?? ''),
    queryFn: () => fetchMaintenanceRecord(id as string),
    enabled: Boolean(id),
  })
}

export function useMaintenanceAudit(id: string | undefined, page = 1) {
  return useQuery({
    queryKey: maintenanceKeys.audit(id ?? '', page),
    queryFn: () => fetchMaintenanceAudit(id as string, page),
    enabled: Boolean(id),
    placeholderData: keepPreviousData,
  })
}

/** The administrator's cross-estate directory. 403 for anyone else. */
export function useMaintenanceDirectory(params: MaintenanceParams, enabled = true) {
  return useQuery({
    queryKey: maintenanceKeys.directory(params),
    queryFn: () => fetchMaintenanceDirectory(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useMaintenanceDashboard(enabled = true) {
  return useQuery({
    queryKey: maintenanceKeys.dashboard(),
    queryFn: fetchMaintenanceDashboard,
    enabled,
  })
}
