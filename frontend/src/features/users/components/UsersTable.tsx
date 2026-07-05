import { ArrowDown, ArrowUp, ChevronRight, KeyRound } from 'lucide-react'
import { Avatar, Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import { cn } from '@/lib/cn'
import { formatDate } from '../lib/format'
import type { SortColumn, UserListItem } from '../types'
import { UserStatusBadge } from './UserStatusBadge'

interface UsersTableProps {
  users: UserListItem[]
  selected: Set<string>
  onToggle: (id: string) => void
  onToggleAll: () => void
  sort: SortColumn
  direction: 'asc' | 'desc'
  onSort: (column: SortColumn) => void
  onRowClick: (id: string) => void
}

export function UsersTable({
  users,
  selected,
  onToggle,
  onToggleAll,
  sort,
  direction,
  onSort,
  onRowClick,
}: UsersTableProps) {
  const allSelected = users.length > 0 && users.every((u) => selected.has(u.id))

  return (
    <Table>
      <THead>
        <Tr>
          <Th className="w-10">
            <input
              type="checkbox"
              aria-label="Select all users on this page"
              className="size-4 rounded-sm border-control-border accent-primary"
              checked={allSelected}
              onChange={onToggleAll}
            />
          </Th>
          <SortableTh
            label="Name"
            column="name"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          <SortableTh
            label="Role"
            column="role"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          <SortableTh
            label="Status"
            column="status"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          <Th className="hidden md:table-cell">Employee #</Th>
          <SortableTh
            label="Last login"
            column="last_login_at"
            sort={sort}
            direction={direction}
            onSort={onSort}
            className="hidden lg:table-cell"
          />
          <Th className="w-8" />
        </Tr>
      </THead>
      <TBody>
        {users.map((user) => (
          <Tr key={user.id} onClick={() => onRowClick(user.id)}>
            <Td className="w-10" onClick={(e) => e.stopPropagation()}>
              <input
                type="checkbox"
                aria-label={`Select ${user.name}`}
                className="size-4 rounded-sm border-control-border accent-primary"
                checked={selected.has(user.id)}
                onClick={(e) => e.stopPropagation()}
                onChange={() => onToggle(user.id)}
              />
            </Td>
            <Td>
              <div className="flex items-center gap-2.5">
                <Avatar name={user.name} size="sm" />
                <div className="min-w-0">
                  <div className="flex items-center gap-1.5">
                    <span className="truncate font-medium text-ink-strong">{user.name}</span>
                    {user.force_password_reset && (
                      <KeyRound
                        size={13}
                        className="shrink-0 text-warning-strong"
                        aria-label="Password reset required"
                      />
                    )}
                    {user.archived && (
                      <span className="rounded-full bg-surface-sunken px-1.5 text-[0.6875rem] text-muted">
                        Archived
                      </span>
                    )}
                  </div>
                  <span className="block truncate text-xs text-muted">{user.email}</span>
                </div>
              </div>
            </Td>
            <Td className="capitalize text-muted">{user.role.name ?? '—'}</Td>
            <Td>
              <UserStatusBadge status={user.status} />
            </Td>
            <Td className="hidden font-mono text-xs text-muted md:table-cell">
              {user.employee_number ?? '—'}
            </Td>
            <Td className="hidden text-muted tnum lg:table-cell">
              {formatDate(user.last_login_at)}
            </Td>
            <Td className="w-8 text-faint">
              <ChevronRight size={16} aria-hidden="true" />
            </Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}

interface SortableThProps {
  label: string
  column: SortColumn
  sort: SortColumn
  direction: 'asc' | 'desc'
  onSort: (column: SortColumn) => void
  className?: string
}

function SortableTh({ label, column, sort, direction, onSort, className }: SortableThProps) {
  const active = sort === column
  return (
    <Th className={className}>
      <button
        type="button"
        onClick={() => onSort(column)}
        className={cn(
          'inline-flex items-center gap-1 font-semibold hover:text-ink',
          active ? 'text-ink' : 'text-muted',
        )}
      >
        {label}
        {active &&
          (direction === 'asc' ? (
            <ArrowUp size={13} aria-hidden="true" />
          ) : (
            <ArrowDown size={13} aria-hidden="true" />
          ))}
      </button>
    </Th>
  )
}
