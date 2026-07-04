import { ShieldOff } from 'lucide-react'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { ButtonLink } from '@/components/ui/Button'

/**
 * 403 surface, shown when an authenticated user lacks permission for a route
 * (rendered by RequirePermission). The backend is the real authority; this is
 * the friendly client-side reflection of a denied capability.
 */
export default function ForbiddenPage() {
  useDocumentMeta({ title: 'Access denied' })

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center py-16 text-center">
      <span className="inline-flex size-12 items-center justify-center rounded-md bg-danger-subtle text-danger-strong">
        <ShieldOff size={24} aria-hidden="true" />
      </span>
      <p className="tnum mt-6 text-[3.25rem] font-semibold leading-none tracking-[-0.03em] text-ink-strong">
        403
      </p>
      <h1 className="mt-3 text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
        Access denied
      </h1>
      <p className="mt-3 max-w-md text-[15px] leading-relaxed text-muted">
        You don’t have permission to view this page. If you think this is a mistake, contact your
        administrator.
      </p>
      <ButtonLink to="/app" variant="secondary" size="lg" className="mt-8">
        Back to workspace
      </ButtonLink>
    </div>
  )
}
