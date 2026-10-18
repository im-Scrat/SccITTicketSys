import { useState } from 'react'
import { Combobox, Select } from '@/components/ui'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useEquipmentSearch } from '../../hooks/queries'
import type { OverallParams } from '../../types'

const REPAIR_CLASSES = [
  { value: 'corrective', label: 'Corrective repairs' },
  { value: 'preventive', label: 'Preventive visits' },
  { value: 'upgrade', label: 'Upgrades' },
]

/** Mirrors App\Enums\ComponentType; the server validates every value. */
const COMPONENT_TYPES: Array<[string, string]> = [
  ['system_unit', 'System Unit'],
  ['printer', 'Printer'],
  ['ups', 'UPS'],
  ['network_device', 'Network Device'],
  ['scanner', 'Scanner'],
  ['projector', 'Projector'],
  ['cpu', 'CPU'],
  ['motherboard', 'Motherboard'],
  ['ram', 'RAM'],
  ['gpu', 'GPU'],
  ['storage', 'Storage'],
  ['power_supply', 'Power Supply'],
  ['network_adapter', 'Network Adapter'],
  ['cooler', 'Cooler'],
  ['chassis', 'Chassis'],
  ['monitor', 'Monitor'],
  ['keyboard', 'Keyboard'],
  ['mouse', 'Mouse'],
  ['peripheral', 'Peripheral'],
  ['other', 'Other'],
]

interface OverallExtraFiltersProps {
  params: OverallParams
  patch: (changes: Partial<Record<keyof OverallParams, string | null>>) => void
  canPickEquipment: boolean
}

/**
 * The filters only Overall Reports has (Phase 2.10 R4): one piece of
 * equipment (searched on the server), and — marked as applying to Repairs
 * only — repair class and the failed part's type.
 */
export function OverallExtraFilters({ params, patch, canPickEquipment }: OverallExtraFiltersProps) {
  const [query, setQuery] = useState('')
  const debounced = useDebouncedValue(query, 300)
  const search = useEquipmentSearch(debounced, canPickEquipment)
  const [selectedLabel, setSelectedLabel] = useState<string | undefined>(undefined)
  const options = search.data ?? []

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
      {canPickEquipment && (
        <Combobox
          label="Equipment"
          options={options}
          value={params.equipment ?? null}
          selectedLabel={selectedLabel ?? (params.equipment ? 'Selected equipment' : undefined)}
          onChange={(value) => {
            setSelectedLabel(options.find((o) => o.value === value)?.label)
            patch({ equipment: value })
          }}
          onSearch={setQuery}
          loading={search.isFetching}
          placeholder="Search by code or name"
          hint="Shows its profile and narrows tickets and repairs to it."
          emptyText="No equipment matches"
        />
      )}
      <label className="flex flex-col gap-2">
        <span className="text-sm font-semibold text-ink">Repair class</span>
        <Select
          value={params.maintenance_class ?? ''}
          onChange={(e) => patch({ maintenance_class: e.target.value || null })}
          aria-describedby="repair-class-hint"
        >
          <option value="">All repairs</option>
          {REPAIR_CLASSES.map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
        </Select>
        <span id="repair-class-hint" className="text-xs text-muted">
          Applies to Repairs only.
        </span>
      </label>
      <label className="flex flex-col gap-2">
        <span className="text-sm font-semibold text-ink">Failed part</span>
        <Select
          value={params.component_type ?? ''}
          onChange={(e) => patch({ component_type: e.target.value || null })}
          aria-describedby="component-hint"
        >
          <option value="">Any part</option>
          {COMPONENT_TYPES.map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </Select>
        <span id="component-hint" className="text-xs text-muted">
          Applies to replacements in Repairs only.
        </span>
      </label>
    </div>
  )
}
