import type { CategoryOption } from './types'

/**
 * Exactly what `TicketOptionsController` returns once the categories are seeded:
 * the thirteen grouped ones in `sort_order`, then the single `other` row. Only
 * "Other" carries `requires_detail` (SDD DD-77); the group-level catch-alls were
 * retired on 2026-10-16 and are no longer returned (only active categories are).
 */
export const API_CATEGORIES: CategoryOption[] = [
  { value: 'hardware', label: 'Hardware', requires_detail: false },
  { value: 'software', label: 'Software', requires_detail: false },
  { value: 'network', label: 'Network', requires_detail: false },
  { value: 'performance-slowness', label: 'Performance & System Slowness', requires_detail: false },
  { value: 'os-updates', label: 'Operating System Updates', requires_detail: false },
  { value: 'power-issue', label: 'Power Issue', requires_detail: false },
  { value: 'audio-video-output', label: 'Audio & Video Output', requires_detail: false },
  { value: 'browser-web', label: 'Browser & Web Issues', requires_detail: false },
  { value: 'peripheral', label: 'Peripheral', requires_detail: false },
  { value: 'account-access', label: 'Account & Access', requires_detail: false },
  { value: 'bios-firmware', label: 'BIOS / Firmware errors', requires_detail: false },
  { value: 'blue-screen', label: 'Blue Screen / Kernel Panic', requires_detail: false },
  { value: 'missing-software', label: 'Missing Software', requires_detail: false },
  { value: 'other', label: 'Other', requires_detail: true },
]
