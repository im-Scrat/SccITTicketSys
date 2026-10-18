import type { StepperStep } from '@/components/ui'
import type { AssetImport, ImportIssue, ImportStatus, RowStatus } from '../types'

/**
 * The wizard's steps (Phase 2.10 R6). The server's status decides where an
 * import *can* be; within review, `?step=` chooses between fixing rows and
 * confirming. "fix" — never "review" — so it cannot collide with the
 * post-import review's `?review=1`.
 */
export type WizardStep = 'upload' | 'mapping' | 'fix' | 'confirm' | 'importing' | 'summary'

export const WIZARD_STEPS: StepperStep[] = [
  { id: 'upload', label: 'Upload' },
  { id: 'mapping', label: 'Map columns' },
  { id: 'fix', label: 'Review and fix' },
  { id: 'confirm', label: 'Confirm' },
  { id: 'importing', label: 'Importing' },
  { id: 'summary', label: 'Summary' },
]

export function stepFor(status: ImportStatus, requested: string | null): WizardStep {
  switch (status) {
    case 'reading':
    case 'unreadable':
      return 'upload'
    case 'mapping':
    case 'validating':
      return 'mapping'
    case 'review':
      return requested === 'confirm' ? 'confirm' : requested === 'mapping' ? 'mapping' : 'fix'
    case 'queued':
    case 'importing':
      return 'importing'
    default:
      return 'summary'
  }
}

/** Steps the administrator may go back to from here. */
export function reachableSteps(status: ImportStatus): WizardStep[] {
  return status === 'review'
    ? ['mapping', 'fix', 'confirm']
    : status === 'mapping'
      ? ['mapping']
      : []
}

/** Rows that stop a confirm. */
export function blockingCount(item: AssetImport): number {
  return (
    item.plan?.blocking ??
    (item.row_counts ? item.row_counts.invalid + item.row_counts.needs_review : 0)
  )
}

export const ROW_STATUS_TONE: Record<
  RowStatus,
  'success' | 'warning' | 'neutral' | 'info' | 'outline'
> = {
  ready: 'success',
  needs_review: 'warning',
  invalid: 'warning',
  duplicate: 'info',
  excluded: 'outline',
}

export const SEVERITY_LABEL: Record<ImportIssue['severity'], string> = {
  error: 'Must be fixed',
  duplicate: 'Duplicate',
  review: 'Needs a decision',
  warning: 'Check',
  info: 'Note',
}

/** Why a row did not save, in words (the server's stable failure codes). */
export function failureText(code: string | null): string {
  if (code === null) return ''
  if (code.startsWith('invalid_at_save:'))
    return 'Its values no longer passed the checks when it was saved.'
  if (code.startsWith('catalog_')) {
    return code === 'catalog_unavailable'
      ? 'Its catalog model was not available when it was saved.'
      : 'Its catalog entry could not be created (an archived entry with that name exists).'
  }
  const words: Record<string, string> = {
    duplicate_at_save:
      'A record with the same identifier was added before this row was saved. The existing record was not changed.',
    duplicate_preview: 'Already in the register. The existing record was not changed.',
    room_unavailable: 'Its room was archived or taken out of use before the row was saved.',
    save_failed: 'It could not be saved. Try again.',
    stopped: 'The import was stopped before this row.',
    actor_not_authorised: 'The administrator who confirmed the import no longer has access.',
    not_ready: 'It was not ready when the import ran.',
  }
  return words[code] ?? 'It could not be saved.'
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} bytes`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function plural(count: number, one: string, many = `${one}s`): string {
  return `${count.toLocaleString()} ${count === 1 ? one : many}`
}
