import { ShieldCheck, UserCog, Users, Wrench } from 'lucide-react'
import { StatCard, type StatTone } from '@/components/ui'
import type { UserMetrics } from '../types'

interface UserMetricsCardsProps {
  metrics: UserMetrics['summary']
  /** The status currently applied as a directory filter (for the pressed state). */
  activeStatus?: string
  onFilterStatus?: (status: string) => void
}

const statusCards: Array<{
  key: keyof UserMetrics['summary']
  label: string
  status: string
  tone: StatTone
}> = [
  { key: 'active', label: 'Active', status: 'active', tone: 'success' },
  { key: 'pending', label: 'Pending', status: 'pending', tone: 'warning' },
  { key: 'suspended', label: 'Suspended', status: 'suspended', tone: 'danger' },
  { key: 'rejected', label: 'Rejected', status: 'rejected', tone: 'danger' },
  { key: 'inactive', label: 'Inactive', status: 'inactive', tone: 'neutral' },
]

/**
 * The dashboard KPI grid: total + one filterable card per account status, then a
 * per-role breakdown. Clicking a status card applies it as a directory filter.
 */
export function UserMetricsCards({ metrics, activeStatus, onFilterStatus }: UserMetricsCardsProps) {
  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <StatCard
          label="Total users"
          value={metrics.total}
          icon={<Users size={16} aria-hidden="true" />}
          onClick={onFilterStatus ? () => onFilterStatus('all') : undefined}
          active={activeStatus === 'all' || activeStatus === undefined}
        />
        {statusCards.map((card) => (
          <StatCard
            key={card.key}
            label={card.label}
            value={metrics[card.key]}
            tone={card.tone}
            onClick={onFilterStatus ? () => onFilterStatus(card.status) : undefined}
            active={activeStatus === card.status}
          />
        ))}
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatCard
          label="Administrators"
          value={metrics.administrators}
          tone="primary"
          icon={<ShieldCheck size={16} aria-hidden="true" />}
        />
        <StatCard
          label="Technicians"
          value={metrics.technicians}
          icon={<Wrench size={16} aria-hidden="true" />}
        />
        <StatCard
          label="Teachers"
          value={metrics.teachers}
          icon={<UserCog size={16} aria-hidden="true" />}
        />
        <StatCard label="Archived" value={metrics.archived} tone="neutral" />
      </div>
    </div>
  )
}
