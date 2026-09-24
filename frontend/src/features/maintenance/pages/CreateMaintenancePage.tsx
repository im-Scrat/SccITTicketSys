import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Alert, Button, Surface } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDate } from '@/lib/datetime'
import type { MaintenancePayload } from '../api/maintenanceApi'
import { MaintenanceFormDrawer } from '../components/MaintenanceFormDrawer'
import { useCreateMaintenance } from '../hooks/mutations'
import { useMaintenanceOptions } from '../hooks/queries'
import type { ConcurrentWarning } from '../types'

/**
 * Open a maintenance record (SRS FR-MNT-001/002).
 *
 * A page rather than a drawer on a list, because scheduling work is a
 * deliberate act with its own URL — a technician standing at a machine gets sent
 * here, and the address is worth being able to share.
 *
 * If the server returns a `concurrent` warning, it is shown **after** the record
 * is created rather than blocking it. A machine may legitimately carry a
 * scheduled preventive visit and an active corrective repair at the same time;
 * the useful thing is to tell the technician what else is open, not to refuse
 * the record and make whoever is right work around the system.
 */
export default function CreateMaintenancePage() {
  useDocumentMeta({ title: 'Schedule maintenance' })
  const navigate = useNavigate()

  const options = useMaintenanceOptions()
  const create = useCreateMaintenance()

  const [open, setOpen] = useState(true)
  const [concurrent, setConcurrent] = useState<ConcurrentWarning[]>([])
  const [createdId, setCreatedId] = useState<string | null>(null)

  async function submit(payload: MaintenancePayload) {
    const result = await create.mutateAsync(payload)
    const warnings = result.meta?.concurrent ?? []

    setCreatedId(result.data.id)
    setConcurrent(warnings)

    // Nothing to warn about: go straight to the work surface.
    if (warnings.length === 0) navigate(`/app/maintenance/${result.data.id}`)
  }

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/maintenance')}>
          <ArrowLeft size={26} aria-hidden="true" />
          My maintenance
        </Button>
      </div>

      <header className="measure">
        <h1 className="text-2xl font-bold text-ink-strong">Schedule maintenance</h1>
        <p className="mt-2 text-base text-muted">
          Corrective repairs and preventive rounds are opened the same way — the type decides which
          checklist is issued and whether evidence will be required to finish.
        </p>
      </header>

      {createdId && concurrent.length > 0 && (
        <Alert tone="warning" title="This machine already has open maintenance">
          <p className="mb-3">
            Your record was created. These visits are also open against the same equipment — check
            you are not duplicating one of them.
          </p>

          <ul className="mb-4 flex flex-col gap-2">
            {concurrent.map((item) => (
              <li key={item.id}>
                <Surface className="p-3">
                  <p className="text-sm font-medium text-ink-strong">{item.title}</p>
                  <p className="text-xs text-muted">
                    {item.status_label}
                    {item.type && ` · ${item.type}`}
                    {item.technician && ` · ${item.technician}`}
                    {item.scheduled_for && ` · ${formatDate(item.scheduled_for)}`}
                  </p>
                </Surface>
              </li>
            ))}
          </ul>

          <Button variant="primary" onClick={() => navigate(`/app/maintenance/${createdId}`)}>
            Continue to my record
          </Button>
        </Alert>
      )}

      <MaintenanceFormDrawer
        open={open}
        onClose={() => {
          setOpen(false)
          if (!createdId) navigate('/app/maintenance')
        }}
        options={options.data}
        onSubmit={submit}
        submitting={create.isPending}
      />
    </div>
  )
}
