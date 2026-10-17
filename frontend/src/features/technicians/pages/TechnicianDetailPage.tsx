import { ArrowLeft } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { Alert, Skeleton, Surface, Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatMinutes, formatRelative, titleCase } from '@/lib/datetime'
import { useTechnician } from '../hooks/queries'

function Figure({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="flex flex-col gap-1 py-3">
      <dt className="text-sm text-muted">{label}</dt>
      <dd className="text-xl font-semibold text-ink-strong tnum">{value}</dd>
    </div>
  )
}

/** Phase 2.9 — one technician: recorded and calculated figures, then their active work. */
export default function TechnicianDetailPage() {
  const { id } = useParams<{ id: string }>()
  const query = useTechnician(id)
  const person = query.data
  useDocumentMeta({ title: person ? person.name : 'Technician' })

  return (
    <div className="flex flex-col gap-6">
      <Link
        to="/app/technicians"
        className="inline-flex w-fit items-center gap-2 rounded-sm text-sm font-semibold text-primary-strong hover:underline"
      >
        <ArrowLeft size={16} aria-hidden="true" />
        All technicians
      </Link>

      {query.isError ? (
        <Alert tone="error" title="This technician could not be found">
          They may not be a technician, or the account no longer exists.
        </Alert>
      ) : query.isLoading || !person ? (
        <Skeleton className="h-80" />
      ) : (
        <>
          <header>
            <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
              {person.name}
            </h1>
            <p className="mt-1 text-sm capitalize text-muted">Account {person.account_status}</p>
          </header>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Surface className="p-5">
              <h2 className="text-base font-semibold text-ink-strong">Recorded</h2>
              <p className="mt-1 text-sm text-muted">
                Counted from assignment and maintenance rows.
              </p>
              <dl className="mt-2 grid grid-cols-2 gap-x-6 sm:grid-cols-3">
                <Figure label="Active assignments" value={person.recorded.active_assignments} />
                <Figure label="Awaiting acceptance" value={person.recorded.pending} />
                <Figure label="In progress" value={person.recorded.in_progress} />
                <Figure label="On hold" value={person.recorded.on_hold} />
                <Figure label="Completed" value={person.recorded.completed_assignments} />
                <Figure label="Declined" value={person.recorded.declined_assignments} />
                <Figure
                  label="Maintenance completed"
                  value={person.recorded.completed_maintenance}
                />
                <Figure
                  label="Last activity"
                  value={
                    person.recorded.last_activity_at
                      ? formatRelative(person.recorded.last_activity_at)
                      : '—'
                  }
                />
              </dl>
            </Surface>

            <Surface className="p-5">
              <h2 className="text-base font-semibold text-ink-strong">Calculated</h2>
              <p className="mt-1 text-sm text-muted">
                Medians over every assignment that reached each step. A dash means no data yet.
              </p>
              <dl className="mt-2 grid grid-cols-2 gap-x-6 sm:grid-cols-3">
                <Figure
                  label="Assigned → accepted"
                  value={formatMinutes(person.calculated.median_minutes_to_accept)}
                />
                <Figure
                  label="Assigned → started"
                  value={formatMinutes(person.calculated.median_minutes_to_start)}
                />
                <Figure
                  label="Assigned → completed"
                  value={formatMinutes(person.calculated.median_minutes_to_complete)}
                />
                <Figure label="Overdue tickets" value={person.calculated.sla_breached} />
                <Figure label="Open tickets at risk" value={person.calculated.sla_at_risk} />
              </dl>
            </Surface>
          </div>

          <section aria-labelledby="active-work-heading" className="flex flex-col gap-3">
            <h2 id="active-work-heading" className="text-lg font-semibold text-ink-strong">
              Active work
            </h2>
            {person.active_assignments.length === 0 ? (
              <p className="text-sm text-muted">No active assignments.</p>
            ) : (
              <Surface className="p-2">
                <Table>
                  <THead>
                    <Tr>
                      <Th>Ticket</Th>
                      <Th>Assignment</Th>
                      <Th>Priority</Th>
                      <Th>Assigned</Th>
                    </Tr>
                  </THead>
                  <TBody>
                    {person.active_assignments.map((row, index) => (
                      <Tr key={row.id ?? index}>
                        <Td>
                          {row.id ? (
                            <Link
                              to={`/app/tickets/manage/${row.id}`}
                              className="rounded-sm font-semibold text-ink-strong hover:underline"
                            >
                              <span className="tnum text-muted">{row.number}</span> {row.title}
                            </Link>
                          ) : (
                            row.title
                          )}
                        </Td>
                        <Td>{titleCase(row.assignment_status.replace('_', ' '))}</Td>
                        <Td>{row.priority ?? '—'}</Td>
                        <Td className="text-muted">
                          {row.assigned_at ? formatRelative(row.assigned_at) : '—'}
                        </Td>
                      </Tr>
                    ))}
                  </TBody>
                </Table>
              </Surface>
            )}
          </section>
        </>
      )}
    </div>
  )
}
