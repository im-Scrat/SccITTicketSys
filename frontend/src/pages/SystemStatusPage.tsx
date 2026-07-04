import { useQuery } from '@tanstack/react-query'
import { RotateCcw, TriangleAlert } from 'lucide-react'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { getHealth } from '@/services/health'
import { Button } from '@/components/ui/Button'
import { Container } from '@/components/ui/Container'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { StatusPill, type AssetStatus } from '@/components/ui/StatusPill'
import { Surface } from '@/components/ui/Surface'

function toStatus(value: string): AssetStatus {
  const normalized = value.toLowerCase()
  if (normalized === 'ok' || normalized === 'healthy' || normalized === 'up') return 'online'
  if (normalized === 'degraded') return 'maintenance'
  return 'offline'
}

function StatusRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between px-4 py-3">
      <span className="text-sm font-medium capitalize text-ink">{label}</span>
      <StatusPill status={toStatus(value)} label={value} />
    </div>
  )
}

export default function SystemStatusPage() {
  useDocumentMeta({
    title: 'System status',
    description: 'Live health of the SccIT backend services.',
  })

  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: ['health'],
    queryFn: getHealth,
  })

  return (
    <Container className="max-w-2xl py-16 lg:py-20">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
            System status
          </h1>
          <p className="mt-2 text-[15px] leading-relaxed text-muted">
            Live health of the SccIT backend services.
          </p>
        </div>
        {data && (
          <StatusPill
            status={toStatus(data.status)}
            label={data.status === 'healthy' ? 'Operational' : data.status}
          />
        )}
      </div>

      <div className="mt-8">
        {isLoading && (
          <Surface className="divide-y divide-border" aria-busy="true">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="flex items-center justify-between px-4 py-3">
                <Skeleton width={120} height={12} />
                <Skeleton width={72} height={20} className="rounded-full" />
              </div>
            ))}
          </Surface>
        )}

        {isError && (
          <EmptyState
            icon={<TriangleAlert size={22} />}
            title="Couldn’t reach the API"
            description="The backend health endpoint didn’t respond. If you’re running the stack locally, make sure the services are up, then try again."
            action={
              <Button
                variant="secondary"
                leftIcon={<RotateCcw size={16} aria-hidden="true" />}
                loading={isFetching}
                onClick={() => refetch()}
              >
                Retry
              </Button>
            }
          />
        )}

        {data && (
          <>
            <Surface className="divide-y divide-border">
              <StatusRow label="Overall" value={data.status} />
              {Object.entries(data.checks).map(([key, value]) => (
                <StatusRow key={key} label={key} value={value} />
              ))}
            </Surface>
            <p className="mt-4 text-xs text-muted">
              Laravel <span className="slashed-zero font-mono">{data.laravel}</span>
            </p>
          </>
        )}
      </div>
    </Container>
  )
}
