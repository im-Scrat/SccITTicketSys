import {
  ArrowLeft,
  KeyRound,
  Lock,
  Mail,
  Pencil,
  Power,
  PowerOff,
  RotateCcw,
  ShieldAlert,
  Trash2,
  Undo2,
} from 'lucide-react'
import { type ReactNode, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  Alert,
  Avatar,
  Button,
  ConfirmDialog,
  PageLoader,
  Select,
  Surface,
  Tabs,
} from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import {
  forcePasswordReset,
  lifecycleAction,
  resendApproval,
  resendRejection,
  sendPasswordReset,
  unlockAccount,
} from '../api/usersApi'
import { AuditTimeline } from '../components/AuditTimeline'
import { EditUserDrawer } from '../components/EditUserDrawer'
import { PermissionMatrix } from '../components/PermissionMatrix'
import { UserStatusBadge } from '../components/UserStatusBadge'
import {
  useArchiveUser,
  useChangeRole,
  useRestoreUser,
  useSetPermissions,
} from '../hooks/mutations'
import { usePermissionMatrix, useRoles, useUserAudit, useUserDetail } from '../hooks/queries'
import { formatDateTime, titleCase } from '../lib/format'
import type { UserDetail } from '../types'

type Tab = 'overview' | 'permissions' | 'audit'

export default function UserDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { user: currentUser, hasPermission } = useAuth()
  const { data: user, isLoading, isError, refetch } = useUserDetail(id)

  useDocumentMeta({ title: user ? user.name : 'User' })

  const [tab, setTab] = useState<Tab>('overview')
  const [flash, setFlash] = useState<{ tone: 'info' | 'error'; text: string } | null>(null)
  const [editOpen, setEditOpen] = useState(false)

  if (isLoading) return <PageLoader />
  if (isError || !user) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <Alert tone="error">We couldn’t load this user.</Alert>
      </div>
    )
  }

  const canManage = hasPermission('users.update')
  const canDelete = hasPermission('users.delete')
  const isSelf = currentUser?.id === user.id

  const run = async (promise: Promise<{ message?: string } | unknown>) => {
    try {
      const result = (await promise) as { message?: string } | undefined
      await refetch()
      setFlash({ tone: 'info', text: result?.message ?? 'Done.' })
    } catch (error) {
      setFlash({ tone: 'error', text: getErrorMessage(error) })
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <BackLink />

      {flash && <Alert tone={flash.tone}>{flash.text}</Alert>}

      <Surface className="p-5">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="flex items-center gap-4">
            <Avatar name={user.name} size="lg" />
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-xl font-semibold text-ink-strong">{user.name}</h1>
                <UserStatusBadge status={user.status} />
                {user.archived_at && (
                  <span className="rounded-full bg-surface-sunken px-2 py-0.5 text-xs text-muted">
                    Archived
                  </span>
                )}
              </div>
              <p className="mt-0.5 text-sm text-muted">{user.email}</p>
              <p className="mt-0.5 text-xs capitalize text-muted">
                {user.role.name ?? '—'} · {user.organization}
              </p>
            </div>
          </div>

          {canManage && !user.archived_at && (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<Pencil size={15} />}
              onClick={() => setEditOpen(true)}
            >
              Edit profile
            </Button>
          )}
        </div>

        {canManage && (
          <ActionRow
            user={user}
            isSelf={isSelf}
            canDelete={canDelete}
            onRun={run}
            onFlash={setFlash}
            refetch={refetch}
            navigate={navigate}
          />
        )}
      </Surface>

      <Tabs
        tabs={[
          { value: 'overview', label: 'Overview' },
          { value: 'permissions', label: 'Permissions' },
          { value: 'audit', label: 'Audit', count: user.audit_count },
        ]}
        value={tab}
        onChange={(v) => setTab(v as Tab)}
      />

      {tab === 'overview' && <OverviewTab user={user} />}
      {tab === 'permissions' && (
        <PermissionsTab user={user} canManage={canManage && !isSelf} onRun={run} />
      )}
      {tab === 'audit' && <AuditTab id={user.id} />}

      {canManage && (
        <EditUserDrawer open={editOpen} onClose={() => setEditOpen(false)} user={user} />
      )}
    </div>
  )
}

function BackLink() {
  return (
    <Link
      to="/app/users"
      className="inline-flex w-fit items-center gap-1.5 text-sm text-muted hover:text-ink"
    >
      <ArrowLeft size={15} aria-hidden="true" /> Back to users
    </Link>
  )
}

/* ----------------------------- action row ----------------------------- */

interface ActionRowProps {
  user: UserDetail
  isSelf: boolean
  canDelete: boolean
  onRun: (promise: Promise<unknown>) => Promise<void>
  onFlash: (flash: { tone: 'info' | 'error'; text: string }) => void
  refetch: () => Promise<unknown>
  navigate: (to: string) => void
}

function ActionRow({ user, isSelf, canDelete, onRun, refetch, navigate }: ActionRowProps) {
  const archive = useArchiveUser()
  const restore = useRestoreUser()
  const [confirm, setConfirm] = useState<null | 'suspend' | 'deactivate' | 'archive'>(null)

  const id = user.id
  const s = user.status

  return (
    <div className="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
      {user.archived_at ? (
        canDelete && (
          <Button
            size="sm"
            leftIcon={<Undo2 size={15} />}
            onClick={() => void onRun(restore.mutateAsync(id))}
          >
            Restore account
          </Button>
        )
      ) : (
        <>
          {(s === 'inactive' || s === 'pending' || s === 'rejected') && (
            <Button
              size="sm"
              leftIcon={<Power size={15} />}
              onClick={() => void onRun(lifecycleAction(id, 'activate'))}
            >
              Activate
            </Button>
          )}
          {s === 'suspended' && (
            <Button
              size="sm"
              leftIcon={<RotateCcw size={15} />}
              onClick={() => void onRun(lifecycleAction(id, 'reactivate'))}
            >
              Reactivate
            </Button>
          )}
          {s === 'active' && !isSelf && (
            <>
              <Button
                variant="secondary"
                size="sm"
                leftIcon={<PowerOff size={15} />}
                onClick={() => setConfirm('suspend')}
              >
                Suspend
              </Button>
              <Button variant="secondary" size="sm" onClick={() => setConfirm('deactivate')}>
                Deactivate
              </Button>
            </>
          )}

          <Button
            variant="secondary"
            size="sm"
            leftIcon={<Mail size={15} />}
            onClick={() => void onRun(sendPasswordReset(id))}
          >
            Send reset
          </Button>
          <Button
            variant="secondary"
            size="sm"
            leftIcon={<KeyRound size={15} />}
            onClick={() => void onRun(forcePasswordReset(id, !user.force_password_reset))}
          >
            {user.force_password_reset ? 'Clear reset flag' : 'Force reset'}
          </Button>
          {user.lockout.locked && (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<Lock size={15} />}
              onClick={() => void onRun(unlockAccount(id))}
            >
              Unlock
            </Button>
          )}
          {s === 'active' && (
            <Button variant="secondary" size="sm" onClick={() => void onRun(resendApproval(id))}>
              Resend approval
            </Button>
          )}
          {s === 'rejected' && (
            <Button variant="secondary" size="sm" onClick={() => void onRun(resendRejection(id))}>
              Resend rejection
            </Button>
          )}
          {canDelete && !isSelf && (
            <Button
              variant="danger"
              size="sm"
              leftIcon={<Trash2 size={15} />}
              onClick={() => setConfirm('archive')}
            >
              Archive
            </Button>
          )}
        </>
      )}

      <ConfirmDialog
        open={confirm === 'suspend' || confirm === 'deactivate'}
        onClose={() => setConfirm(null)}
        onConfirm={() => {
          const action = confirm === 'suspend' ? 'suspend' : 'deactivate'
          setConfirm(null)
          void onRun(lifecycleAction(id, action))
        }}
        title={confirm === 'suspend' ? 'Suspend account' : 'Deactivate account'}
        description={`${user.name} will lose access immediately. This is recorded in the audit log.`}
        confirmLabel={confirm === 'suspend' ? 'Suspend' : 'Deactivate'}
        tone="danger"
        loading={false}
      />

      <ConfirmDialog
        open={confirm === 'archive'}
        onClose={() => setConfirm(null)}
        onConfirm={async () => {
          setConfirm(null)
          try {
            await archive.mutateAsync(id)
            navigate('/app/users')
          } catch {
            await refetch()
          }
        }}
        title="Archive account"
        description={`${user.name} will be soft-deleted. All history and attributions are preserved and the account can be restored later.`}
        confirmLabel="Archive"
        tone="danger"
        loading={archive.isPending}
      />
    </div>
  )
}

/* ------------------------------- tabs -------------------------------- */

function OverviewTab({ user }: { user: UserDetail }) {
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Surface className="p-5">
        <SectionTitle>Profile</SectionTitle>
        <DescriptionList
          items={[
            ['Full name', user.name],
            ['Email', user.email],
            ['Employee number', user.employee_number ?? '—'],
            ['Phone', user.contact_number ?? '—'],
            ['Organization', user.organization],
            ['Role', titleCase(user.role.name)],
          ]}
        />
      </Surface>

      <Surface className="p-5">
        <SectionTitle>Account</SectionTitle>
        <DescriptionList
          items={[
            ['Status', <UserStatusBadge key="s" status={user.status} />],
            ['Registration source', titleCase(user.registration_source)],
            ['Registered', formatDateTime(user.registered_at)],
            ['Approved / verified', formatDateTime(user.approved_at)],
            ['Created by', user.created_by ?? '—'],
            ['Updated by', user.updated_by ?? '—'],
          ]}
        />
      </Surface>

      <Surface className="p-5">
        <SectionTitle>Activity & security</SectionTitle>
        <DescriptionList
          items={[
            ['Last login', formatDateTime(user.last_login_at)],
            ['Last login IP', user.last_login_ip ?? '—'],
            ['Last activity', formatDateTime(user.last_activity_at)],
            ['Last password change', formatDateTime(user.password_changed_at)],
            ['Password reset required', user.force_password_reset ? 'Yes' : 'No'],
            [
              'Account lockout',
              user.lockout.locked ? (
                <span key="l" className="inline-flex items-center gap-1 text-danger-strong">
                  <ShieldAlert size={14} /> Locked
                </span>
              ) : (
                'Not locked'
              ),
            ],
            ['Current session', user.session.active ? 'Active' : 'None'],
          ]}
        />
      </Surface>

      {user.status === 'rejected' && user.rejection.reason && (
        <Surface className="p-5">
          <SectionTitle>Rejection</SectionTitle>
          <DescriptionList
            items={[
              ['Reason', user.rejection.reason],
              ['Rejected at', formatDateTime(user.rejection.at)],
              ['Rejected by', user.rejection.by ?? '—'],
            ]}
          />
        </Surface>
      )}
    </div>
  )
}

function PermissionsTab({
  user,
  canManage,
  onRun,
}: {
  user: UserDetail
  canManage: boolean
  onRun: (promise: Promise<unknown>) => Promise<void>
}) {
  const { data: matrix, isLoading } = usePermissionMatrix(user.id)
  const roles = useRoles()
  const changeRole = useChangeRole()
  const setPermissions = useSetPermissions()

  return (
    <div className="flex flex-col gap-4">
      <Surface className="p-5">
        <SectionTitle>Role</SectionTitle>
        <p className="mb-3 text-sm text-muted">
          The role sets the permission baseline; per-user overrides adjust it below.
        </p>
        <div className="flex items-center gap-2">
          <Select
            aria-label="Role"
            className="w-auto"
            disabled={!canManage}
            value={user.role.slug ?? ''}
            onChange={(e) =>
              void onRun(changeRole.mutateAsync({ id: user.id, role: e.target.value }))
            }
          >
            {(roles.data ?? []).map((role) => (
              <option key={role.slug} value={role.slug ?? ''}>
                {role.name}
              </option>
            ))}
          </Select>
          {!canManage && (
            <span className="text-xs text-muted">You cannot change your own role.</span>
          )}
        </div>
      </Surface>

      <Surface className="p-5">
        <SectionTitle>Permission overrides</SectionTitle>
        {isLoading || !matrix ? (
          <p className="text-sm text-muted">Loading permissions…</p>
        ) : (
          <PermissionMatrix
            matrix={matrix}
            canEdit={canManage}
            saving={setPermissions.isPending}
            onSave={(grants, denies) =>
              void onRun(setPermissions.mutateAsync({ id: user.id, grants, denies }))
            }
          />
        )}
      </Surface>
    </div>
  )
}

function AuditTab({ id }: { id: string }) {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useUserAudit(id, page)

  return (
    <Surface className="p-5">
      <SectionTitle>Audit history</SectionTitle>
      <AuditTimeline entries={data?.data} isLoading={isLoading} />
      {data && data.meta.last_page > 1 && (
        <div className="mt-3 flex justify-end gap-2">
          <Button
            variant="secondary"
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage((p) => p - 1)}
          >
            Newer
          </Button>
          <Button
            variant="secondary"
            size="sm"
            disabled={page >= data.meta.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            Older
          </Button>
        </div>
      )}
    </Surface>
  )
}

/* ----------------------------- primitives ---------------------------- */

function SectionTitle({ children }: { children: ReactNode }) {
  return <h2 className="mb-3 text-sm font-semibold text-ink-strong">{children}</h2>
}

function DescriptionList({ items }: { items: Array<[string, ReactNode]> }) {
  return (
    <dl className="grid grid-cols-1 gap-x-4 gap-y-2.5 sm:grid-cols-[9rem_1fr]">
      {items.map(([label, value], i) => (
        <div key={i} className="grid grid-cols-[9rem_1fr] gap-x-4 sm:contents">
          <dt className="text-xs text-muted sm:py-0.5">{label}</dt>
          <dd className="text-sm text-ink sm:py-0.5">{value}</dd>
        </div>
      ))}
    </dl>
  )
}
