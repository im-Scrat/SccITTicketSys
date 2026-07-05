import { Select } from '@/components/ui'
import type { AccountStatus, DirectoryParams, RoleOption } from '../types'

interface UserFiltersProps {
  params: DirectoryParams
  roles: RoleOption[]
  onChange: (patch: Partial<DirectoryParams>) => void
}

const statuses: AccountStatus[] = ['active', 'pending', 'suspended', 'rejected', 'inactive']

/** Secondary filters (status / role / archived) beside the search field. */
export function UserFilters({ params, roles, onChange }: UserFiltersProps) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <Select
        aria-label="Filter by status"
        className="w-auto"
        value={params.status ?? 'all'}
        onChange={(e) => onChange({ status: e.target.value, page: 1 })}
      >
        <option value="all">All statuses</option>
        {statuses.map((s) => (
          <option key={s} value={s} className="capitalize">
            {s.charAt(0).toUpperCase() + s.slice(1)}
          </option>
        ))}
      </Select>

      <Select
        aria-label="Filter by role"
        className="w-auto"
        value={params.role ?? 'all'}
        onChange={(e) => onChange({ role: e.target.value, page: 1 })}
      >
        <option value="all">All roles</option>
        {roles.map((role) => (
          <option key={role.slug} value={role.slug ?? ''}>
            {role.name}
          </option>
        ))}
      </Select>

      <Select
        aria-label="Archived visibility"
        className="w-auto"
        value={params.trashed ?? 'without'}
        onChange={(e) =>
          onChange({ trashed: e.target.value as DirectoryParams['trashed'], page: 1 })
        }
      >
        <option value="without">Active records</option>
        <option value="only">Archived only</option>
        <option value="with">Include archived</option>
      </Select>
    </div>
  )
}
