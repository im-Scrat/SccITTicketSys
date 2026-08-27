import { useNavigate } from 'react-router-dom'
import { StatCard, type StatTone } from '@/components/ui'
import type { KpiItem } from '../types'

/**
 * A row of headline figures — the right form for "a handful of numbers", instead
 * of a chart that would say the same thing with more ink.
 *
 * `tone` is only ever a **status** signal (something is breached, something needs
 * attention), never identity, and the label always states what the number is, so
 * the meaning survives colour-blindness and grayscale.
 */
export function KpiRow({ items }: { items: KpiItem[] }) {
  const navigate = useNavigate()

  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      {items.map((item) => (
        <StatCard
          key={item.key}
          label={item.label}
          value={item.value.toLocaleString()}
          tone={(item.tone ?? 'neutral') as StatTone}
          onClick={item.href ? () => navigate(item.href as string) : undefined}
        />
      ))}
    </div>
  )
}
