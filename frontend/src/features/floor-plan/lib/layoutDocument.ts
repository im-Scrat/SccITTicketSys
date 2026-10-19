import type { Fixture, RoomPlan, SaveLayoutRequest } from '../types'
import { round2 } from './geometry'

/**
 * The editor's draft of a room's Floorplan (Phase 2.11, SDD DD-101).
 *
 * Geometry only: where each workstation stands and how it is turned, every
 * fixture, and the layout's size and grid. Who a machine *is* — its name,
 * status, open ticket — is server data that keeps updating live while the
 * draft is open; it never enters the draft, so a status change can never make
 * a draft "dirty" or conflict with it.
 *
 * Objects are keyed by stable ids (PC uuid, fixture uuid), which is what makes
 * the per-object three-way merge below possible.
 */

export interface WorkstationGeom {
  x: number
  y: number
  rotation: number
}

export interface LayoutSettings {
  width: number
  height: number
  grid_size: number
}

export interface LayoutDoc {
  settings: LayoutSettings
  workstations: Record<string, WorkstationGeom>
  fixtures: Record<string, Fixture>
}

/** The draft the server's plan describes, or null when there is no layout yet. */
export function docFromPlan(plan: RoomPlan): LayoutDoc | null {
  if (!plan.layout) return null

  return {
    settings: {
      width: plan.layout.width,
      height: plan.layout.height,
      grid_size: plan.layout.grid_size,
    },
    workstations: Object.fromEntries(
      plan.pcs.map((pc) => [pc.id, { x: pc.x, y: pc.y, rotation: Math.round(pc.rotation) }]),
    ),
    fixtures: Object.fromEntries(plan.fixtures.map((f) => [f.id, { ...f }])),
  }
}

/** The document as the save endpoint takes it. */
export function toSaveRequest(doc: LayoutDoc, revision: number): SaveLayoutRequest {
  return {
    expected_revision: revision,
    settings: { ...doc.settings },
    workstations: Object.entries(doc.workstations).map(([id, w]) => ({
      pc_unit: id,
      x: round2(w.x),
      y: round2(w.y),
      rotation: w.rotation,
    })),
    fixtures: Object.values(doc.fixtures).map((f) => ({
      ...f,
      x: round2(f.x),
      y: round2(f.y),
      width: round2(f.width),
      height: round2(f.height),
      rotation: round2(f.rotation),
      label: f.label && f.label.trim() !== '' ? f.label.trim() : null,
    })),
  }
}

const same = (a: number, b: number) => Math.abs(a - b) < 0.005

export function sameWorkstation(a?: WorkstationGeom, b?: WorkstationGeom): boolean {
  if (!a || !b) return a === b
  return same(a.x, b.x) && same(a.y, b.y) && a.rotation === b.rotation
}

export function sameFixture(a?: Fixture, b?: Fixture): boolean {
  if (!a || !b) return a === b
  return (
    a.kind === b.kind &&
    same(a.x, b.x) &&
    same(a.y, b.y) &&
    same(a.width, b.width) &&
    same(a.height, b.height) &&
    same(a.rotation, b.rotation) &&
    (a.label ?? null) === (b.label ?? null)
  )
}

export function sameSettings(a: LayoutSettings, b: LayoutSettings): boolean {
  return a.width === b.width && a.height === b.height && a.grid_size === b.grid_size
}

/** An object's identity across documents: `pc:<uuid>`, `fx:<uuid>`, or `settings`. */
export type ObjectKey = `pc:${string}` | `fx:${string}` | 'settings'

type Value = WorkstationGeom | Fixture | LayoutSettings | undefined

function get(doc: LayoutDoc, key: ObjectKey): Value {
  if (key === 'settings') return doc.settings
  const id = key.slice(3)
  return key.startsWith('pc:') ? doc.workstations[id] : doc.fixtures[id]
}

function equal(key: ObjectKey, a: Value, b: Value): boolean {
  if (key === 'settings') return sameSettings(a as LayoutSettings, b as LayoutSettings)
  if (key.startsWith('pc:')) return sameWorkstation(a as WorkstationGeom, b as WorkstationGeom)
  return sameFixture(a as Fixture, b as Fixture)
}

function keys(...docs: LayoutDoc[]): ObjectKey[] {
  const out = new Set<ObjectKey>(['settings'])
  for (const doc of docs) {
    for (const id of Object.keys(doc.workstations)) out.add(`pc:${id}`)
    for (const id of Object.keys(doc.fixtures)) out.add(`fx:${id}`)
  }
  return [...out]
}

function set(doc: LayoutDoc, key: ObjectKey, value: Value): LayoutDoc {
  if (key === 'settings') return { ...doc, settings: value as LayoutSettings }
  const id = key.slice(3)

  if (key.startsWith('pc:')) {
    const workstations = { ...doc.workstations }
    if (value === undefined) delete workstations[id]
    else workstations[id] = value as WorkstationGeom
    return { ...doc, workstations }
  }

  const fixtures = { ...doc.fixtures }
  if (value === undefined) delete fixtures[id]
  else fixtures[id] = value as Fixture
  return { ...doc, fixtures }
}

/** The objects (and settings) that differ between two documents. */
export function changedKeys(base: LayoutDoc, draft: LayoutDoc): ObjectKey[] {
  return keys(base, draft).filter((key) => !equal(key, get(base, key), get(draft, key)))
}

export function countChanges(base: LayoutDoc, draft: LayoutDoc): number {
  return changedKeys(base, draft).length
}

export interface MergeConflict {
  key: ObjectKey
  /** Undefined means "removed" on that side. */
  mine: Value
  theirs: Value
}

export interface MergeResult {
  /** Theirs, with every one of my non-conflicting changes applied on top. */
  doc: LayoutDoc
  /** How many of my changes carried over cleanly. */
  applied: number
  /** Objects both sides changed, differently — the administrator chooses. */
  conflicts: MergeConflict[]
}

/**
 * Per-object three-way merge (DD-101): `base` is what this editor loaded,
 * `mine` its draft, `theirs` what the server now holds.
 *
 * For each object: unchanged by me → theirs; unchanged by them → mine; changed
 * by both to the same thing → that; changed by both differently → a conflict,
 * provisionally resolved to theirs. Nobody's work is dropped silently: either
 * it carries over, or it is listed for a decision.
 */
export function merge3(base: LayoutDoc, mine: LayoutDoc, theirs: LayoutDoc): MergeResult {
  let doc = theirs
  let applied = 0
  const conflicts: MergeConflict[] = []

  for (const key of keys(base, mine, theirs)) {
    const b = get(base, key)
    const m = get(mine, key)
    const t = get(theirs, key)

    if (equal(key, m, b) || equal(key, m, t)) continue

    if (equal(key, t, b)) {
      doc = set(doc, key, m)
      applied++
      continue
    }

    conflicts.push({ key, mine: m, theirs: t })
  }

  return { doc, applied, conflicts }
}

/** Apply the administrator's choice for each conflict ("mine" or "theirs", default theirs). */
export function resolveConflicts(
  merged: MergeResult,
  choices: Partial<Record<ObjectKey, 'mine' | 'theirs'>>,
): LayoutDoc {
  let doc = merged.doc
  for (const conflict of merged.conflicts) {
    if (choices[conflict.key] === 'mine') doc = set(doc, conflict.key, conflict.mine)
  }
  return doc
}

/** Drop workstations for machines that are no longer in the room. */
export function pruneWorkstations(
  doc: LayoutDoc,
  known: Set<string>,
): { doc: LayoutDoc; dropped: string[] } {
  const dropped = Object.keys(doc.workstations).filter((id) => !known.has(id))
  if (dropped.length === 0) return { doc, dropped }

  const workstations = { ...doc.workstations }
  for (const id of dropped) delete workstations[id]
  return { doc: { ...doc, workstations }, dropped }
}
