import { Check, Minus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Button } from '@/components/ui'
import { cn } from '@/lib/cn'
import type { PermissionMatrix as MatrixData } from '../types'

interface PermissionMatrixProps {
  matrix: MatrixData
  canEdit: boolean
  saving: boolean
  onSave: (grants: string[], denies: string[]) => void
}

type OverrideState = 'default' | 'grant' | 'deny'

/**
 * The per-user permission override matrix (SRS FR-USER-004). Each permission
 * resolves to allow/deny via the role baseline unless overridden. Admins set a
 * per-permission state — Default (inherit role), Grant (force allow), or Deny
 * (force deny) — and Save syncs the full override set. Deny always wins, exactly
 * as the server-side PermissionResolver computes it.
 */
export function PermissionMatrix({ matrix, canEdit, saving, onSave }: PermissionMatrixProps) {
  const [grants, setGrants] = useState<Set<string>>(new Set(matrix.grants))
  const [denies, setDenies] = useState<Set<string>>(new Set(matrix.denies))

  useEffect(() => {
    setGrants(new Set(matrix.grants))
    setDenies(new Set(matrix.denies))
  }, [matrix])

  const roleSet = useMemo(() => new Set(matrix.role_permissions), [matrix.role_permissions])
  const byModule = useMemo(() => {
    const groups: Record<string, MatrixData['all_permissions']> = {}
    for (const permission of matrix.all_permissions) {
      ;(groups[permission.module] ??= []).push(permission)
    }
    return groups
  }, [matrix.all_permissions])

  const dirty =
    !setsEqual(grants, new Set(matrix.grants)) || !setsEqual(denies, new Set(matrix.denies))

  const stateOf = (name: string): OverrideState =>
    denies.has(name) ? 'deny' : grants.has(name) ? 'grant' : 'default'

  const setState = (name: string, next: OverrideState) => {
    if (!canEdit) return
    setGrants((prev) => {
      const copy = new Set(prev)
      if (next === 'grant') copy.add(name)
      else copy.delete(name)
      return copy
    })
    setDenies((prev) => {
      const copy = new Set(prev)
      if (next === 'deny') copy.add(name)
      else copy.delete(name)
      return copy
    })
  }

  return (
    <div className="flex flex-col gap-5">
      {Object.entries(byModule).map(([module, permissions]) => (
        <div key={module}>
          <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted">
            {module}
          </h3>
          <div className="divide-y divide-border rounded-md border border-border">
            {permissions.map((permission) => {
              const state = stateOf(permission.name)
              const effective =
                state === 'grant' || (state === 'default' && roleSet.has(permission.name))
              return (
                <div
                  key={permission.name}
                  className="flex items-center justify-between gap-3 px-3 py-2"
                >
                  <div className="min-w-0">
                    <div className="flex items-center gap-1.5">
                      <span className="font-mono text-xs text-ink">{permission.name}</span>
                      {effective ? (
                        <Check
                          size={13}
                          className="text-success-strong"
                          aria-label="Effective: allowed"
                        />
                      ) : (
                        <Minus size={13} className="text-faint" aria-label="Effective: denied" />
                      )}
                    </div>
                  </div>
                  <SegmentedOverride
                    value={state}
                    disabled={!canEdit}
                    fromRole={roleSet.has(permission.name)}
                    onChange={(next) => setState(permission.name, next)}
                  />
                </div>
              )
            })}
          </div>
        </div>
      ))}

      {canEdit && (
        <div className="sticky bottom-0 flex items-center justify-end gap-2 border-t border-border bg-surface py-3">
          <span className="mr-auto text-xs text-muted">
            {dirty ? 'Unsaved override changes' : 'No pending changes'}
          </span>
          <Button
            size="sm"
            disabled={!dirty}
            loading={saving}
            onClick={() => onSave(Array.from(grants), Array.from(denies))}
          >
            Save overrides
          </Button>
        </div>
      )}
    </div>
  )
}

function SegmentedOverride({
  value,
  disabled,
  fromRole,
  onChange,
}: {
  value: OverrideState
  disabled: boolean
  fromRole: boolean
  onChange: (next: OverrideState) => void
}) {
  const options: Array<{ key: OverrideState; label: string }> = [
    { key: 'default', label: fromRole ? 'Role' : 'None' },
    { key: 'grant', label: 'Grant' },
    { key: 'deny', label: 'Deny' },
  ]
  return (
    <div className="flex shrink-0 overflow-hidden rounded-sm border border-border">
      {options.map((option) => {
        const active = value === option.key
        return (
          <button
            key={option.key}
            type="button"
            disabled={disabled}
            aria-pressed={active}
            onClick={() => onChange(option.key)}
            className={cn(
              'px-2 py-1 text-xs font-medium disabled:cursor-not-allowed',
              active
                ? option.key === 'deny'
                  ? 'bg-danger-subtle text-danger-strong'
                  : option.key === 'grant'
                    ? 'bg-success-subtle text-success-strong'
                    : 'bg-surface-sunken text-ink'
                : 'text-muted hover:bg-surface-sunken',
            )}
          >
            {option.label}
          </button>
        )
      })}
    </div>
  )
}

function setsEqual(a: Set<string>, b: Set<string>): boolean {
  if (a.size !== b.size) return false
  for (const value of a) if (!b.has(value)) return false
  return true
}
