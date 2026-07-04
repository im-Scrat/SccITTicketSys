import { Inbox } from 'lucide-react'
import { useState } from 'react'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { Surface } from '@/components/ui/Surface'
import type { RegistrationSummary } from '../types'
import {
  useApproveRegistration,
  useRegistrations,
  useRejectRegistration,
} from '../hooks/useRegistrations'

export default function RegistrationsQueuePage() {
  useDocumentMeta({ title: 'Registrations' })
  const { data: registrations, isLoading, isError } = useRegistrations()

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
          Registration requests
        </h1>
        <p className="mt-1 text-sm text-muted">
          Review pending Teacher and Technician requests. Approving activates the account; rejecting
          keeps it on record and emails the applicant.
        </p>
      </div>

      {isLoading && (
        <div className="flex flex-col gap-3">
          <Skeleton className="h-24" />
          <Skeleton className="h-24" />
        </div>
      )}

      {isError && (
        <Alert tone="error">We couldn’t load the registration queue. Please retry.</Alert>
      )}

      {registrations && registrations.length === 0 && (
        <EmptyState
          icon={<Inbox size={22} />}
          title="No pending requests"
          description="New Teacher and Technician registration requests will appear here for review."
        />
      )}

      {registrations && registrations.length > 0 && (
        <ul className="flex flex-col gap-3">
          {registrations.map((registration) => (
            <li key={registration.id}>
              <RegistrationRow registration={registration} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function RegistrationRow({ registration }: { registration: RegistrationSummary }) {
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const approve = useApproveRegistration()
  const reject = useRejectRegistration()

  const submittedAt = registration.submitted_at
    ? new Date(registration.submitted_at).toLocaleDateString()
    : '—'

  return (
    <Surface className="p-4">
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
            Requested {submittedAt}
          </p>
        </div>

        {!rejecting && (
          <div className="flex shrink-0 gap-2">
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
      </div>

      {rejecting && (
        <div className="mt-3 border-t border-border pt-3">
          <label htmlFor={`reason-${registration.id}`} className="text-xs font-medium text-ink">
            Reason for rejection{' '}
            <span className="text-muted">(optional, shown to the applicant)</span>
          </label>
          <textarea
            id={`reason-${registration.id}`}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            rows={2}
            className="mt-1.5 w-full rounded-sm border border-control-border bg-surface px-2.5 py-1.5 text-sm text-ink focus:border-primary"
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
    </Surface>
  )
}
