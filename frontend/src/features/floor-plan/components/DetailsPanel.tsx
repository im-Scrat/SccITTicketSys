import { useEffect, useState, type ReactNode } from 'react'
import { Copy, ExternalLink, RotateCw, Trash2, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Alert, Button, Field, Input, PcStatusBadge, Select, Skeleton } from '@/components/ui'
import { usePcUnit } from '@/features/assets/hooks/queries'
import { MaintenanceStatusBadge } from '@/features/maintenance/components/MaintenanceBadges'
import { formatDate } from '@/lib/datetime'
import { PC_STATUS, PC_STATUS_VALUES } from '@/lib/pcStatus'
import { FIXTURES } from '../lib/fixtures'
import {
  corners,
  normalizeRotation,
  overlapsAny,
  pointsInsideRoom,
  round2,
  workstationBox,
  boxInsideRoom,
} from '../lib/geometry'
import type { LayoutDoc, ObjectKey } from '../lib/layoutDocument'
import { usePcMaintenanceHistory } from '../hooks/queries'
import { FIXTURE_KINDS, type Fixture, type FixtureKind, type RoomPlan } from '../types'

interface DetailsPanelProps {
  plan: RoomPlan
  doc: LayoutDoc
  selection: ObjectKey[]
  editing: boolean
  invalid: Partial<Record<ObjectKey, string>>
  onEdit: (change: (doc: LayoutDoc) => LayoutDoc, coalesceKey?: string) => void
  onRemove: (keys: ObjectKey[]) => void
  onDuplicate: (keys: ObjectKey[]) => void
  onRotate: (keys: ObjectKey[], direction: 1 | -1) => void
  onClose: () => void
  onAnnounce: (message: string) => void
}

/**
 * What is selected, in full (Phase 2.11). A workstation shows its real record
 * — identity, repair status, position, asset facts, specification, open
 * tickets and repairs — read from the asset and maintenance APIs, never
 * invented. There are deliberately no remote-control buttons and no "last
 * seen": the system has neither capability, so it offers neither.
 */
export function DetailsPanel(props: DetailsPanelProps) {
  const { selection, plan, doc } = props

  if (selection.length === 0) return <RoomSummary plan={plan} doc={doc} />

  if (selection.length > 1) {
    return (
      <PanelFrame title={`${selection.length} objects selected`} onClose={props.onClose}>
        <p className="text-sm text-muted">
          Drag any of them to move them together, or use the arrow keys.
        </p>
        {props.editing && (
          <div className="flex flex-wrap gap-3">
            <Button
              size="sm"
              variant="secondary"
              leftIcon={<RotateCw className="size-4" aria-hidden="true" />}
              onClick={() => props.onRotate(selection, 1)}
            >
              Turn
            </Button>
            <Button
              size="sm"
              variant="danger"
              leftIcon={<Trash2 className="size-4" aria-hidden="true" />}
              onClick={() => props.onRemove(selection)}
            >
              Remove
            </Button>
          </div>
        )}
      </PanelFrame>
    )
  }

  const key = selection[0]
  if (key.startsWith('pc:')) return <PcDetails {...props} pcId={key.slice(3)} />
  const fixture = doc.fixtures[key.slice(3)]
  return fixture ? <FixtureDetails {...props} fixture={fixture} /> : null
}

function PanelFrame({
  title,
  subtitle,
  badge,
  onClose,
  children,
}: {
  title: string
  subtitle?: ReactNode
  badge?: ReactNode
  onClose?: () => void
  children: ReactNode
}) {
  return (
    <section aria-label="Details" className="flex flex-col gap-5">
      <header className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="text-lg font-bold text-ink-strong text-balance">{title}</h2>
          {subtitle && <p className="mt-0.5 font-mono text-sm text-muted">{subtitle}</p>}
          {badge && <div className="mt-2 flex flex-wrap gap-2">{badge}</div>}
        </div>
        {onClose && (
          <button
            type="button"
            onClick={onClose}
            className="grid size-11 shrink-0 place-items-center rounded-md text-muted hover:bg-surface-sunken hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            aria-label="Clear selection"
          >
            <X className="size-5" aria-hidden="true" />
          </button>
        )}
      </header>
      {children}
    </section>
  )
}

function Facts({ rows }: { rows: [string, ReactNode][] }) {
  const shown = rows.filter(([, value]) => value !== null && value !== undefined && value !== '')
  if (shown.length === 0) return <p className="text-sm text-muted">Nothing recorded.</p>
  return (
    <dl className="grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
      {shown.map(([label, value]) => (
        <div key={label} className="contents">
          <dt className="text-muted">{label}</dt>
          <dd className="min-w-0 break-words text-ink">{value}</dd>
        </div>
      ))}
    </dl>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-3 border-t border-border pt-4">
      <h3 className="text-sm font-semibold text-ink-strong">{title}</h3>
      {children}
    </section>
  )
}

/**
 * A number field that commits on blur or Enter — one undo step per commit, not
 * per keystroke. `onCommit` returns `false` when the plan refused the value
 * (outside the room, overlapping); the field then shows the real value again
 * rather than a number the plan does not have.
 */
function NumberField({
  label,
  value,
  min,
  max,
  step = 1,
  disabled,
  onCommit,
  error,
}: {
  label: string
  value: number
  min: number
  max: number
  step?: number
  disabled?: boolean
  onCommit: (value: number) => boolean
  error?: string
}) {
  const [text, setText] = useState(String(round2(value)))
  useEffect(() => setText(String(round2(value))), [value])

  const commit = () => {
    const parsed = Number(text)
    if (!Number.isFinite(parsed)) {
      setText(String(round2(value)))
      return
    }
    const clamped = Math.min(max, Math.max(min, parsed))
    const accepted = clamped === value || onCommit(clamped)
    setText(String(round2(accepted ? clamped : value)))
  }

  return (
    <Field label={label} error={error}>
      <Input
        type="number"
        inputMode="decimal"
        value={text}
        min={min}
        max={max}
        step={step}
        disabled={disabled}
        onChange={(e) => setText(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault()
            commit()
          }
        }}
      />
    </Field>
  )
}

function PcDetails({
  plan,
  doc,
  pcId,
  editing,
  invalid,
  onEdit,
  onRemove,
  onClose,
  onAnnounce,
  onRotate,
}: DetailsPanelProps & { pcId: string }) {
  const placed = plan.pcs.find((pc) => pc.id === pcId)
  const waiting = plan.unplaced.find((pc) => pc.id === pcId)
  const info = placed ?? waiting
  const geom = doc.workstations[pcId]
  const record = usePcUnit(pcId)
  const history = usePcMaintenanceHistory(pcId)
  const size = plan.workstation_size
  const room = { width: doc.settings.width, height: doc.settings.height }

  if (!info) return null

  const setPoint = (axis: 'x' | 'y', value: number): boolean => {
    if (!geom) return false
    const next = { ...geom, [axis]: value }
    if (!boxInsideRoom(workstationBox(next.x, next.y, size), room)) {
      onAnnounce(`Not moved: ${info.name} would not fit inside the room there.`)
      return false
    }
    const hit = overlapsAny(pcId, next, doc.workstations, size)
    if (hit) {
      onAnnounce(`Not moved: ${info.name} would overlap another workstation.`)
      return false
    }
    onEdit((d) => ({ ...d, workstations: { ...d.workstations, [pcId]: next } }))
    return true
  }

  const pc = record.data?.data
  const open = (history.data ?? []).filter((m) => m.status.is_open)
  const done = (history.data ?? []).filter((m) => !m.status.is_open).slice(0, 5)

  return (
    <PanelFrame
      title={info.name}
      subtitle={info.unit_code}
      onClose={onClose}
      badge={
        <>
          <PcStatusBadge status={info.status.value} size="md" />
          {placed?.open_ticket && (
            <Link
              to={`/app/tickets/manage/${placed.open_ticket.id}`}
              className="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-sm font-medium text-primary-strong hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
              Ticket {placed.open_ticket.reference}
            </Link>
          )}
        </>
      }
    >
      <p className="text-sm text-muted">
        {PC_STATUS[info.status.value].description} This is the recorded repair status — the system
        does not monitor connections.
      </p>

      {invalid[`pc:${pcId}`] && <Alert tone="error">{invalid[`pc:${pcId}`]}</Alert>}

      <Section title="On this Floorplan">
        {geom ? (
          <>
            <div className="grid grid-cols-2 gap-3">
              <NumberField
                label="X"
                value={geom.x}
                min={size / 2}
                max={room.width - size / 2}
                disabled={!editing}
                onCommit={(v) => setPoint('x', v)}
              />
              <NumberField
                label="Y"
                value={geom.y}
                min={size / 2}
                max={room.height - size / 2}
                disabled={!editing}
                onCommit={(v) => setPoint('y', v)}
              />
            </div>
            <Field label="Facing">
              <Select
                value={String(geom.rotation)}
                disabled={!editing}
                onChange={(e) =>
                  onEdit((d) => ({
                    ...d,
                    workstations: {
                      ...d.workstations,
                      [pcId]: { ...geom, rotation: Number(e.target.value) },
                    },
                  }))
                }
              >
                <option value="0">Screen up (0°)</option>
                <option value="90">Screen right (90°)</option>
                <option value="180">Screen down (180°)</option>
                <option value="270">Screen left (270°)</option>
              </Select>
            </Field>
            {editing && (
              <div className="flex flex-wrap gap-3">
                <Button
                  size="sm"
                  variant="secondary"
                  leftIcon={<RotateCw className="size-4" aria-hidden="true" />}
                  onClick={() => onRotate([`pc:${pcId}`], 1)}
                >
                  Turn
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  leftIcon={<Trash2 className="size-4" aria-hidden="true" />}
                  onClick={() => onRemove([`pc:${pcId}`])}
                >
                  Remove from plan
                </Button>
              </div>
            )}
          </>
        ) : (
          <p className="text-sm text-muted">
            Not placed yet. In edit mode, place it from the Workstations panel.
          </p>
        )}
      </Section>

      <Section title="Asset">
        {record.isLoading ? (
          <Skeleton className="h-24" />
        ) : record.isError || !pc ? (
          <p className="text-sm text-muted">The asset record could not be loaded.</p>
        ) : (
          <Facts
            rows={[
              ['Hostname', pc.hostname && <span className="font-mono">{pc.hostname}</span>],
              [
                'IP address',
                pc.network.ip_address && <span className="font-mono">{pc.network.ip_address}</span>,
              ],
              [
                'MAC address',
                pc.network.mac_address && (
                  <span className="font-mono">{pc.network.mac_address}</span>
                ),
              ],
              ['Make', [pc.brand, pc.model].filter(Boolean).join(' ')],
              ['Serial', pc.serial_number && <span className="font-mono">{pc.serial_number}</span>],
              ['Condition', pc.condition_label],
              [
                'Warranty',
                pc.warranty.expiration
                  ? `${pc.warranty.under_warranty ? 'Until' : 'Expired'} ${formatDate(pc.warranty.expiration)}`
                  : null,
              ],
            ]}
          />
        )}
      </Section>

      {pc?.specification && (
        <Section title="Specification">
          <Facts
            rows={[
              ['Processor', pc.specification.cpu],
              ['Memory', pc.specification.ram],
              ['Storage', pc.specification.storage_primary],
              ['Graphics', pc.specification.gpu],
              ['Operating system', pc.specification.operating_system],
              ['Monitor', pc.specification.monitor],
            ]}
          />
        </Section>
      )}

      <Section title="Open tickets">
        {pc && pc.active_tickets.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {pc.active_tickets.map((t) => (
              <li key={t.id}>
                <Link
                  to={`/app/tickets/manage/${t.id}`}
                  className="text-sm font-medium text-primary-strong underline-offset-4 hover:underline"
                >
                  {t.number}
                </Link>
                <span className="text-sm text-ink"> — {t.title}</span>
                {t.status && <span className="block text-xs text-muted">{t.status}</span>}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted">{record.isLoading ? 'Loading…' : 'No open tickets.'}</p>
        )}
      </Section>

      <Section title="Repairs">
        {history.isLoading ? (
          <Skeleton className="h-16" />
        ) : history.isError ? (
          <p className="text-sm text-muted">The repair history could not be loaded.</p>
        ) : open.length + done.length === 0 ? (
          <p className="text-sm text-muted">No repairs recorded.</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {[...open, ...done].map((m) => (
              <li key={m.id} className="flex flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                  <MaintenanceStatusBadge status={m.status} />
                  <Link
                    to={`/app/maintenance/${m.id}`}
                    className="text-sm font-medium text-primary-strong underline-offset-4 hover:underline"
                  >
                    {m.title}
                  </Link>
                </div>
                <span className="text-xs text-muted">
                  {formatDate(m.completed_at ?? m.started_at ?? m.scheduled_for ?? m.created_at)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Link
        to={`/app/assets/pc-units/${pcId}`}
        className="inline-flex items-center gap-2 self-start text-sm font-semibold text-primary-strong underline-offset-4 hover:underline"
      >
        Open asset record
        <ExternalLink className="size-4" aria-hidden="true" />
      </Link>
    </PanelFrame>
  )
}

function FixtureDetails({
  doc,
  fixture,
  editing,
  onEdit,
  onRemove,
  onDuplicate,
  onClose,
  onAnnounce,
}: DetailsPanelProps & { fixture: Fixture }) {
  const room = { width: doc.settings.width, height: doc.settings.height }
  const [label, setLabel] = useState(fixture.label ?? '')
  useEffect(() => setLabel(fixture.label ?? ''), [fixture.label, fixture.id])

  const update = (patch: Partial<Fixture>): boolean => {
    const next = { ...fixture, ...patch }
    if (!pointsInsideRoom(corners(next.x, next.y, next.width, next.height, next.rotation), room)) {
      onAnnounce(
        `Not changed: the ${FIXTURES[next.kind].label.toLowerCase()} would not fit inside the room.`,
      )
      return false
    }
    onEdit((d) => ({ ...d, fixtures: { ...d.fixtures, [fixture.id]: next } }))
    return true
  }

  return (
    <PanelFrame
      title={fixture.label || FIXTURES[fixture.kind].label}
      subtitle={fixture.label ? FIXTURES[fixture.kind].label : undefined}
      onClose={onClose}
    >
      <Field label="Kind">
        <Select
          value={fixture.kind}
          disabled={!editing}
          onChange={(e) => update({ kind: e.target.value as FixtureKind })}
        >
          {FIXTURE_KINDS.map((kind) => (
            <option key={kind} value={kind}>
              {FIXTURES[kind].label}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="Label" hint="Optional, up to 60 characters.">
        <Input
          value={label}
          maxLength={60}
          disabled={!editing}
          onChange={(e) => setLabel(e.target.value)}
          onBlur={() => {
            const next = label.trim() === '' ? null : label.trim()
            if (next !== fixture.label) update({ label: next })
          }}
        />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <NumberField
          label="X"
          value={fixture.x}
          min={0}
          max={room.width}
          disabled={!editing}
          onCommit={(v) => update({ x: v })}
        />
        <NumberField
          label="Y"
          value={fixture.y}
          min={0}
          max={room.height}
          disabled={!editing}
          onCommit={(v) => update({ y: v })}
        />
        <NumberField
          label="Width"
          value={fixture.width}
          min={4}
          max={room.width}
          disabled={!editing}
          onCommit={(v) => update({ width: v })}
        />
        <NumberField
          label="Depth"
          value={fixture.height}
          min={4}
          max={room.height}
          disabled={!editing}
          onCommit={(v) => update({ height: v })}
        />
        <NumberField
          label="Turn (°)"
          value={fixture.rotation}
          min={0}
          max={359.99}
          step={15}
          disabled={!editing}
          onCommit={(v) => update({ rotation: normalizeRotation(v) })}
        />
      </div>
      {editing && (
        <div className="flex flex-wrap gap-3">
          <Button
            size="sm"
            variant="secondary"
            leftIcon={<Copy className="size-4" aria-hidden="true" />}
            onClick={() => onDuplicate([`fx:${fixture.id}`])}
          >
            Duplicate
          </Button>
          <Button
            size="sm"
            variant="danger"
            leftIcon={<Trash2 className="size-4" aria-hidden="true" />}
            onClick={() => onRemove([`fx:${fixture.id}`])}
          >
            Delete
          </Button>
        </div>
      )}
    </PanelFrame>
  )
}

function RoomSummary({ plan, doc }: { plan: RoomPlan; doc: LayoutDoc }) {
  const all = [...plan.pcs, ...plan.unplaced]
  return (
    <section aria-label="Details" className="flex flex-col gap-5">
      <div>
        <h2 className="text-lg font-bold text-ink-strong">Nothing selected</h2>
        <p className="mt-1 text-sm text-muted">
          Select a workstation to see its asset record, tickets and repairs.
        </p>
      </div>
      <Section title="Status legend">
        <ul className="flex flex-col gap-3">
          {PC_STATUS_VALUES.map((value) => (
            <li key={value} className="flex items-start gap-3">
              <PcStatusBadge status={value} />
              <span className="text-sm text-muted">
                {PC_STATUS[value].description}{' '}
                {all.filter((pc) => pc.status.value === value).length} in this room.
              </span>
            </li>
          ))}
        </ul>
        <p className="text-xs text-muted">
          Recorded by staff, tickets and repairs. This system does not monitor whether a PC is
          switched on.
        </p>
      </Section>
      <Section title="Room">
        <Facts
          rows={[
            ['Size', `${doc.settings.width} × ${doc.settings.height} units`],
            ['Grid', `${doc.settings.grid_size} units`],
            [
              'Saved',
              plan.layout?.updated_at
                ? `${formatDate(plan.layout.updated_at)}${plan.layout.updated_by ? ` by ${plan.layout.updated_by.name}` : ''}`
                : null,
            ],
          ]}
        />
      </Section>
    </section>
  )
}
