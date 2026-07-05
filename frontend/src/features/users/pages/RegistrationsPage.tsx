import { Inbox } from 'lucide-react'
import { useEffect, useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  EmptyState,
  Pagination,
  SearchInput,
  Skeleton,
  Surface,
  Textarea,
} from '@/components/ui'
import type { RegistrationSummary } from '@/features/auth/types'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { EditRegistrationDrawer } from '../components/EditRegistrationDrawer'
import {
  useApproveRegistration,
  useRegistrations,
  useRejectRegistration,
} from '../hooks/registrations'
import { formatDate } from '../lib/format'

export default function RegistrationsPage() {
  useDocumentMeta({ title: 'Registrations' })

  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<RegistrationSummary | null>(null)

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(search)
      setPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [search])

  const { data, isLoading, isError } = useRegistrations({ search: debounced || undefined, page })
  const rows = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
          Registration requests
        </h1>
        <p className="mt-1 text-sm text-muted">
          Review pending Teacher and Technician requests. Approving activates the account; rejecting
          keeps it on record and emails the applicant.
        </p>
      </div>

      <Surface className="overflow-hidden">
        <div className="border-b border-border p-3">
          <SearchInput
            value={search}
            onChange={setSearch}
            placeholder="Search pending requests…"
            className="sm:max-w-xs"
            aria-label="Search registrations"
          />
        </div>

        {isLoading ? (
          <div className="flex flex-col gap-3 p-4">
            <Skeleton className="h-24" />
            <Skeleton className="h-24" />
          </div>
        ) : isError ? (
          <div className="p-4">
            <Alert tone="error">We couldn’t load the registration queue. Please retry.</Alert>
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            className="border-0"
            icon={<Inbox size={22} />}
            title="No pending requests"
            description="New Teacher and Technician registration requests will appear here for review."
          />
        ) : (
          <>
            <ul className="divide-y divide-border">
              {rows.map((registration) => (
                <li key={registration.id} className="p-4">
                  <RegistrationRow
                    registration={registration}
                    onEdit={() => setEditing(registration)}
                  />
                </li>
              ))}
            </ul>
            {meta && (
              <Pagination
                page={meta.current_page}
                lastPage={meta.last_page}
                total={meta.total}
                from={meta.from}
                to={meta.to}
                onPage={setPage}
              />
            )}
          </>
        )}
      </Surface>

      <EditRegistrationDrawer
        open={editing !== null}
        onClose={() => setEditing(null)}
        registration={editing}
      />
    </div>
  )
}

function RegistrationRow({
  registration,
  onEdit,
}: {
  registration: RegistrationSummary
  onEdit: () => void
}) {
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const approve = useApproveRegistration()
  const reject = useRejectRegistration()

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium text-ink-strong">{registration.name}</span>
          <Badge tone="primary" className="capitalize">
            {registration.role.name}
          </Badge>
        </div>
        <p className="mt-1 text-sm text-muted">{registration.email}</p>
        <p className="mt-0.5 text-xs text-muted">
          {registration.employee_number ? `#${registration.employee_number} · ` : ''}
          {registration.contact_number ? `${registration.contact_number} · ` : ''}
          Requested {formatDate(registration.submitted_at)}
        </p>
      </div>

      {!rejecting && (
        <div className="flex shrink-0 gap-2">
          <Button variant="ghost" size="sm" onClick={onEdit}>
            Edit
          </Button>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setRejecting(true)}
            disabled={approve.isPending}
          >
            Reject
          </Button>
          <Button
            variant="primary"
            size="sm"
            loading={approve.isPending}
            onClick={() => approve.mutate(registration.id)}
          >
            Approve
          </Button>
        </div>
      )}

      {rejecting && (
        <div className="w-full border-t border-border pt-3 sm:mt-0 sm:w-72 sm:border-0 sm:pt-0">
          <label htmlFor={`reason-${registration.id}`} className="text-xs font-medium text-ink">
            Reason <span className="text-muted">(optional)</span>
          </label>
          <Textarea
            id={`reason-${registration.id}`}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            rows={2}
            className="mt-1.5"
          />
          <div className="mt-2 flex justify-end gap-2">
            <Button variant="ghost" size="sm" onClick={() => setRejecting(false)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              size="sm"
              loading={reject.isPending}
              onClick={() =>
                reject.mutate({ id: registration.id, reason: reason.trim() || undefined })
              }
            >
              Confirm rejection
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
