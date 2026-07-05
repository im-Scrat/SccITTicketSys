import { Download, Plus, Users as UsersIcon } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Alert,
  Button,
  EmptyState,
  Modal,
  Pagination,
  SearchInput,
  Select,
  Skeleton,
  Surface,
  Textarea,
} from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { downloadExport, downloadSelected } from '../api/usersApi'
import { BulkActionBar } from '../components/BulkActionBar'
import { ConfirmBulk } from '../components/ConfirmBulk'
import { CreateUserDrawer } from '../components/CreateUserDrawer'
import { UserFilters } from '../components/UserFilters'
import { UserMetricsCards } from '../components/UserMetricsCards'
import { UsersTable } from '../components/UsersTable'
import { useBulkAction } from '../hooks/mutations'
import { useRoles, useUserMetrics, useUsersList } from '../hooks/queries'
import type { BulkAction, DirectoryParams, SortColumn } from '../types'

const INITIAL: DirectoryParams = {
  sort: 'created_at',
  direction: 'desc',
  per_page: 20,
  page: 1,
  status: 'all',
  role: 'all',
  trashed: 'without',
}

export default function UsersDashboardPage() {
  useDocumentMeta({ title: 'Users' })
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('users.create')
  const canManage = hasPermission('users.update')

  const [params, setParams] = useState<DirectoryParams>(INITIAL)
  const [searchInput, setSearchInput] = useState('')
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [createOpen, setCreateOpen] = useState(false)
  const [flash, setFlash] = useState<string | null>(null)
  const [confirm, setConfirm] = useState<{ action: BulkAction; role?: string } | null>(null)
  const [notifyOpen, setNotifyOpen] = useState(false)

  const metrics = useUserMetrics()
  const roles = useRoles()
  const list = useUsersList(params)
  const bulk = useBulkAction()

  // Debounce search input into the server-side query.
  useEffect(() => {
    const timer = setTimeout(
      () => setParams((prev) => ({ ...prev, search: searchInput || undefined, page: 1 })),
      300,
    )
    return () => clearTimeout(timer)
  }, [searchInput])

  // Clear stale selections whenever the visible page changes.
  useEffect(() => setSelected(new Set()), [params])

  const patch = (next: Partial<DirectoryParams>) => setParams((prev) => ({ ...prev, ...next }))

  const onSort = (column: SortColumn) =>
    patch({
      sort: column,
      direction: params.sort === column && params.direction === 'asc' ? 'desc' : 'asc',
      page: 1,
    })

  const rows = list.data?.data ?? []
  const meta = list.data?.meta
  const ids = useMemo(() => [...selected], [selected])

  const toggle = (id: string) =>
    setSelected((prev) => {
      const copy = new Set(prev)
      if (copy.has(id)) copy.delete(id)
      else copy.add(id)
      return copy
    })

  const toggleAll = () =>
    setSelected((prev) =>
      rows.every((r) => prev.has(r.id)) ? new Set() : new Set(rows.map((r) => r.id)),
    )

  const runBulk = async (action: BulkAction, extra: Record<string, unknown> = {}) => {
    try {
      const result = await bulk.mutateAsync({ action, ids, ...extra } as never)
      setFlash(result.message)
      setSelected(new Set())
    } catch (error) {
      setFlash(getErrorMessage(error))
    }
  }

  const onBulkAction = (action: BulkAction, extra?: { role?: string }) => {
    if (action === 'export') {
      void downloadSelected(ids, 'xlsx')
      return
    }
    if (action === 'notify') {
      setNotifyOpen(true)
      return
    }
    if (action === 'suspend' || action === 'reject' || action === 'deactivate') {
      setConfirm({ action })
      return
    }
    if (action === 'role') {
      setConfirm({ action, role: extra?.role })
      return
    }
    void runBulk(action)
  }

  useEffect(() => {
    if (!flash) return
    const timer = setTimeout(() => setFlash(null), 4000)
    return () => clearTimeout(timer)
  }, [flash])

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">Users</h1>
          <p className="mt-1 text-sm text-muted">
            Manage every account across its lifecycle — directory, roles, permissions, and audit.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            leftIcon={<Download size={15} />}
            onClick={() => void downloadExport(params, 'xlsx')}
          >
            Export
          </Button>
          {canCreate && (
            <Button size="sm" leftIcon={<Plus size={15} />} onClick={() => setCreateOpen(true)}>
              New user
            </Button>
          )}
        </div>
      </header>

      {flash && <Alert tone="info">{flash}</Alert>}

      {metrics.isLoading ? (
        <Skeleton className="h-28" />
      ) : metrics.data ? (
        <UserMetricsCards
          metrics={metrics.data.summary}
          activeStatus={params.status}
          onFilterStatus={(status) => patch({ status, page: 1 })}
        />
      ) : null}

      <Surface className="overflow-hidden">
        <div className="flex flex-col gap-2 border-b border-border p-3 sm:flex-row sm:items-center">
          <SearchInput
            value={searchInput}
            onChange={setSearchInput}
            placeholder="Search name, email or employee number…"
            className="sm:max-w-xs"
            aria-label="Search users"
          />
          <div className="flex flex-1 flex-wrap items-center gap-2 sm:justify-end">
            <UserFilters params={params} roles={roles.data ?? []} onChange={patch} />
            <Select
              aria-label="Rows per page"
              className="w-auto"
              value={String(params.per_page)}
              onChange={(e) => patch({ per_page: Number(e.target.value), page: 1 })}
            >
              {[10, 20, 50, 100].map((n) => (
                <option key={n} value={n}>
                  {n} / page
                </option>
              ))}
            </Select>
          </div>
        </div>

        {list.isLoading ? (
          <div className="flex flex-col gap-2 p-4">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-12" />
            ))}
          </div>
        ) : list.isError ? (
          <div className="p-4">
            <Alert tone="error">We couldn’t load the directory. Please retry.</Alert>
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            className="border-0"
            icon={<UsersIcon size={22} />}
            title="No users match"
            description="Adjust the search or filters — or create a new user to get started."
          />
        ) : (
          <>
            <UsersTable
              users={rows}
              selected={selected}
              onToggle={toggle}
              onToggleAll={toggleAll}
              sort={params.sort ?? 'created_at'}
              direction={params.direction ?? 'desc'}
              onSort={onSort}
              onRowClick={(id) => navigate(`/app/users/${id}`)}
            />
            {meta && (
              <Pagination
                page={meta.current_page}
                lastPage={meta.last_page}
                total={meta.total}
                from={meta.from}
                to={meta.to}
                onPage={(page) => patch({ page })}
              />
            )}
          </>
        )}
      </Surface>

      {canManage && (
        <BulkActionBar
          count={selected.size}
          roles={roles.data ?? []}
          pending={bulk.isPending}
          onClear={() => setSelected(new Set())}
          onAction={onBulkAction}
        />
      )}

      {canCreate && (
        <CreateUserDrawer
          open={createOpen}
          onClose={() => setCreateOpen(false)}
          roles={roles.data ?? []}
          onCreated={(user) => {
            setCreateOpen(false)
            navigate(`/app/users/${user.id}`)
          }}
        />
      )}

      <ConfirmBulk
        state={confirm}
        count={selected.size}
        pending={bulk.isPending}
        onClose={() => setConfirm(null)}
        onConfirm={(reason) => {
          const { action, role } = confirm!
          setConfirm(null)
          void runBulk(action, { reason, role })
        }}
      />

      <NotifyModal
        open={notifyOpen}
        count={selected.size}
        pending={bulk.isPending}
        onClose={() => setNotifyOpen(false)}
        onSend={(subject, message) => {
          setNotifyOpen(false)
          void runBulk('notify', { subject, message })
        }}
      />
    </div>
  )
}

function NotifyModal({
  open,
  count,
  pending,
  onClose,
  onSend,
}: {
  open: boolean
  count: number
  pending: boolean
  onClose: () => void
  onSend: (subject: string, message: string) => void
}) {
  const [subject, setSubject] = useState('')
  const [message, setMessage] = useState('')

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Send notification"
      description={`Email ${count} selected user(s).`}
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Cancel
          </Button>
          <Button
            size="sm"
            loading={pending}
            disabled={!subject.trim() || !message.trim()}
            onClick={() => onSend(subject.trim(), message.trim())}
          >
            Send email
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <label className="flex flex-col gap-1.5 text-xs font-medium text-ink">
          Subject
          <input
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            className="h-[34px] rounded-sm border border-control-border bg-surface px-2.5 text-sm text-ink focus:border-primary"
          />
        </label>
        <label className="flex flex-col gap-1.5 text-xs font-medium text-ink">
          Message
          <Textarea rows={4} value={message} onChange={(e) => setMessage(e.target.value)} />
        </label>
      </div>
    </Modal>
  )
}
