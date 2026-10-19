import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { isAxiosError } from 'axios'
import { useQueryClient } from '@tanstack/react-query'
import { ChevronRight, DoorClosed, History, ListTree, PanelRight, Pencil } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import {
  Alert,
  Button,
  ButtonLink,
  Drawer,
  EmptyState,
  ErrorState,
  LiveRegion,
  Modal,
  PcStatusBadge,
  Skeleton,
} from '@/components/ui'
import { useIsPreview } from '@/contexts/PreviewModeContext'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { LiveStatus } from '@/features/dashboard/components/LiveStatus'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { usePresenceChannel } from '@/hooks/usePresenceChannel'
import { cn } from '@/lib/cn'
import { PC_STATUS, PC_STATUS_TONE_CLASSES, PC_STATUS_VALUES } from '@/lib/pcStatus'
import ForbiddenPage from '@/pages/ForbiddenPage'
import { DetailsPanel } from '../components/DetailsPanel'
import {
  AutoArrangeDialog,
  ConflictDialog,
  LayoutSettingsDialog,
  ShortcutsDialog,
} from '../components/EditorDialogs'
import { PresenceIndicator, SaveStateBadge, type SaveState } from '../components/EditorStatus'
import { EditorToolbar } from '../components/EditorToolbar'
import { FloorPlanCanvas } from '../components/FloorPlanCanvas'
import { SetupLayout } from '../components/SetupLayout'
import { WorkstationsPanel } from '../components/WorkstationsPanel'
import { useCreateLayout, useSaveLayout, withStatusChanged } from '../hooks/mutations'
import { floorPlanKeys, useRoomPlan } from '../hooks/queries'
import { useFloorPlanChannel } from '../hooks/useFloorPlanChannel'
import { useUnsavedChangesGuard } from '../hooks/useUnsavedChangesGuard'
import type { DragItem } from '../lib/dragItem'
import { clearDraft, readDraft, writeDraft, type StoredDraft } from '../lib/draftStorage'
import { FIXTURES, newFixture } from '../lib/fixtures'
import {
  arrangeInRows,
  boxInsideRoom,
  corners,
  halfExtent,
  normalizeRotation,
  overlapsAny,
  pointsInsideRoom,
  quarterTurn,
  snapAndClamp,
  workstationBox,
  type Point,
} from '../lib/geometry'
import {
  countChanges,
  docFromPlan,
  merge3,
  resolveConflicts,
  toSaveRequest,
  type LayoutDoc,
  type LayoutSettings,
  type MergeResult,
  type ObjectKey,
} from '../lib/layoutDocument'
import { visibleSize } from '../lib/viewport'
import { isDirty } from '../stores/createEditorStore'
import { EditorStoreProvider } from '../stores/EditorStoreContext'
import { useEditor, useEditorStore } from '../stores/useEditor'
import type { FixtureKind, LayoutStaleConflict, RoomPlan } from '../types'

/**
 * A room's Floorplan editor — `/app/floor-plan/rooms/:id` (Phase 2.11).
 *
 * Loads the room's plan, then hands it to a per-room editor store. Access is
 * the server's call: 401/403 shows the Forbidden page, 404 a "not found" state
 * with a way back.
 */
export default function FloorPlanRoomPage() {
  const { id } = useParams<{ id: string }>()
  const query = useRoomPlan(id)

  if (query.isLoading) return <WorkspaceSkeleton />

  if (query.isError) {
    const status = isAxiosError(query.error) ? query.error.response?.status : undefined
    if (status === 401 || status === 403) return <ForbiddenPage />
    if (status === 404) {
      return (
        <EmptyState
          icon={<DoorClosed className="size-6" aria-hidden="true" />}
          title="This room could not be found"
          description="It may have been archived, or the link is out of date."
          action={<ButtonLink to="/app/floor-plan">Back to Floorplan</ButtonLink>}
        />
      )
    }
    return (
      <ErrorState
        title="The Floorplan could not be loaded"
        description="Check your connection and try again."
        onRetry={() => void query.refetch()}
        retrying={query.isFetching}
      />
    )
  }

  if (!query.data || !id) return null

  return (
    <EditorStoreProvider key={id} snap={query.data.editor.snap_to_grid}>
      <RoomEditor roomId={id} plan={query.data} />
    </EditorStoreProvider>
  )
}

function WorkspaceSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy="true" aria-label="Loading the Floorplan">
      <Skeleton className="h-6 w-48" />
      <Skeleton className="h-10 w-80" />
      <Skeleton className="h-[60dvh] w-full" />
    </div>
  )
}

type RemoteNotice = { by: string | null; revision: number | null }

function RoomEditor({ roomId, plan }: { roomId: string; plan: RoomPlan }) {
  useDocumentMeta({ title: `${plan.room.name} · Floorplan` })
  const preview = useIsPreview()
  const queryClient = useQueryClient()
  const { user, hasPermission } = useAuth()
  const userId = user?.id
  const store = useEditorStore()
  const draft = useEditor((s) => s.draft)
  const mode = useEditor((s) => s.mode)
  const selection = useEditor((s) => s.selection)
  const invalid = useEditor((s) => s.invalid)
  const dirty = useEditor(isDirty)
  const changes = useEditor((s) => (s.base && s.draft ? countChanges(s.base, s.draft) : 0))

  const editing = mode === 'edit' && !preview && plan.editor.can_edit && plan.layout !== null
  const canMoveIn = hasPermission('assets.view') && hasPermission('assets.update') && !preview

  const [announcement, setAnnouncement] = useState('')
  const announce = useCallback(
    (message: string) =>
      setAnnouncement((prev) => (prev === message ? `${message}\u00a0` : message)),
    [],
  )
  const [highlight, setHighlight] = useState<Set<string> | null>(null)
  const [recovered, setRecovered] = useState<StoredDraft | null>(null)
  const [remote, setRemote] = useState<RemoteNotice | null>(null)
  const [conflict, setConflict] = useState<{
    merge: MergeResult
    current: RoomPlan
    by: string | null
  } | null>(null)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [dialog, setDialog] = useState<'settings' | 'shortcuts' | 'arrange' | null>(null)
  const [drawer, setDrawer] = useState<'workstations' | 'details' | null>(null)
  const [editingMembers, setEditingMembers] = useState<Set<string>>(new Set())

  const save = useSaveLayout(roomId)
  const create = useCreateLayout(roomId)
  const loaded = useRef<{ version: number | null; revision: number | null } | null>(null)
  const ownRevision = useRef<number | null>(null)

  /* ── load / reconcile the server's plan ────────────────────────────── */

  useEffect(() => {
    const doc = docFromPlan(plan)
    const version = plan.layout?.version ?? null
    const revision = plan.layout?.revision ?? null
    const previous = loaded.current
    const state = store.getState()

    if (previous && previous.version === version && previous.revision === revision) return
    // Never step backwards: a render can still carry the plan from before this
    // editor's own save landed in the cache.
    if (
      previous &&
      previous.version === version &&
      revision !== null &&
      previous.revision !== null &&
      revision < previous.revision
    )
      return
    loaded.current = { version, revision }

    // First load, a different version, or no unsaved work: take the server's copy.
    if (!previous || previous.version !== version || !isDirty(state)) {
      state.reset(doc, revision)
      if (previous && revision !== ownRevision.current && doc) {
        announce(
          `${plan.layout?.updated_by?.name ?? 'Someone'} updated this Floorplan. You are seeing the latest version.`,
        )
      }
      setRemote(null)

      if (!previous && doc && userId && !preview) {
        const stored = readDraft(userId, roomId)
        if (
          stored &&
          stored.layoutVersion === version &&
          countChanges(stored.base, stored.draft) > 0
        )
          setRecovered(stored)
      }
      return
    }

    // Unsaved work and a newer server copy: never overwrite — offer to combine.
    if (revision !== ownRevision.current) {
      setRemote({ by: plan.layout?.updated_by?.name ?? null, revision })
    }
  }, [plan, store, userId, roomId, preview, announce])

  /* ── keep an unsaved draft in this tab ─────────────────────────────── */

  useEffect(() => {
    if (!userId || preview || !plan.layout) return
    const state = store.getState()
    const timer = window.setTimeout(() => {
      if (state.base && state.draft && state.revision !== null && dirty) {
        writeDraft(userId, roomId, {
          layoutVersion: plan.layout!.version,
          revision: state.revision,
          base: state.base,
          draft: state.draft,
          savedAt: Date.now(),
        })
      } else if (!dirty && !recovered) {
        clearDraft(userId, roomId)
      }
    }, 400)
    return () => window.clearTimeout(timer)
  }, [draft, dirty, userId, preview, roomId, plan.layout, store, recovered])

  /* ── realtime ──────────────────────────────────────────────────────── */

  const live = useFloorPlanChannel(roomId, {
    onPlanChanged: (event) => {
      if (event.revision !== null && event.revision === ownRevision.current) return
      void queryClient.invalidateQueries({ queryKey: floorPlanKeys.room(roomId) })
      void queryClient.invalidateQueries({ queryKey: ['floor-plan', 'rooms'] })
    },
    onStatusChanged: (event) => {
      queryClient.setQueryData<RoomPlan>(floorPlanKeys.room(roomId), (current) =>
        current ? withStatusChanged(current, event.pc.id, event.pc.status) : current,
      )
      announce(`${event.pc.name} is now ${event.pc.status.label}.`)
    },
  })

  // DD-16's fallback: not live → refetch now and then, but only while clean.
  useRoomPlan(roomId, { poll: !live && !dirty && !preview })

  const { members, whisper } = usePresenceChannel<{ id: string; editing: boolean }>(
    preview ? undefined : `floor-plan.editors.${roomId}`,
    'editing',
    (data) =>
      setEditingMembers((current) => {
        const next = new Set(current)
        if (data.editing) next.add(data.id)
        else next.delete(data.id)
        return next
      }),
  )

  useEffect(() => {
    if (userId) whisper({ id: userId, editing })
  }, [editing, members.length, userId, whisper])

  /* ── leaving with unsaved work ─────────────────────────────────────── */

  const guard = useUnsavedChangesGuard(dirty && !preview)

  /* ── the workspace fills what is left of the viewport ──────────────── */

  // Measured, not a fixed `calc(100dvh - …)`: the header wraps differently at
  // each width and alerts come and go above the canvas, so only the element's
  // real top edge says how much height is left. Never below 320px — on a very
  // short screen the page scrolls rather than the canvas collapsing.
  const workspace = useRef<HTMLDivElement>(null)
  const [workspaceHeight, setWorkspaceHeight] = useState<number | null>(null)
  const hasWorkspace = plan.layout !== null && draft !== null

  useEffect(() => {
    const el = workspace.current
    if (!el) return
    const main = el.closest('main')
    const measure = () => {
      const top = el.getBoundingClientRect().top + window.scrollY
      const gap = main ? parseFloat(getComputedStyle(main).paddingBottom) || 16 : 16
      setWorkspaceHeight(Math.max(320, Math.floor(window.innerHeight - top - gap)))
    }
    measure()
    window.addEventListener('resize', measure)
    const observer =
      typeof ResizeObserver === 'undefined' || !el.parentElement
        ? null
        : new ResizeObserver(measure)
    if (observer && el.parentElement) observer.observe(el.parentElement)
    return () => {
      window.removeEventListener('resize', measure)
      observer?.disconnect()
    }
  }, [hasWorkspace])

  /* ── helpers ───────────────────────────────────────────────────────── */

  const pcName = useCallback(
    (id: string) =>
      [...plan.pcs, ...plan.unplaced].find((pc) => pc.id === id)?.name ?? 'Workstation',
    [plan.pcs, plan.unplaced],
  )

  const describeKey = useCallback(
    (key: ObjectKey): string => {
      if (key === 'settings') return 'Room size and grid'
      const id = key.slice(3)
      if (key.startsWith('pc:')) return pcName(id)
      const f = store.getState().draft?.fixtures[id] ?? store.getState().base?.fixtures[id]
      return f
        ? f.label
          ? `${FIXTURES[f.kind].label} “${f.label}”`
          : FIXTURES[f.kind].label
        : 'Fixture'
    },
    [pcName, store],
  )

  const room = draft
    ? { width: draft.settings.width, height: draft.settings.height }
    : { width: 1, height: 1 }
  const size = plan.workstation_size

  const viewCentre = (): Point => {
    const { viewport, draft: d } = store.getState()
    if (!d) return { x: 0, y: 0 }
    const visible = visibleSize(
      { width: d.settings.width, height: d.settings.height },
      viewport.zoom,
    )
    return { x: viewport.x + visible.width / 2, y: viewport.y + visible.height / 2 }
  }

  /* ── edits ─────────────────────────────────────────────────────────── */

  const placePc = (pcId: string, at?: Point) => {
    const d = store.getState().draft
    if (!d) return
    let point =
      at ??
      snapAndClamp(viewCentre(), { x: size / 2, y: size / 2 }, room, d.settings.grid_size, true)
    if (overlapsAny(pcId, point, d.workstations, size)) {
      const spot = arrangeInRows([pcId], d.workstations, room, {
        size,
        grid: d.settings.grid_size,
        gap: 0,
      })[pcId]
      if (!spot) {
        announce(`No free spot for ${pcName(pcId)} — make the room larger or move something.`)
        return
      }
      point = spot
    }
    store.getState().edit((doc) => ({
      ...doc,
      workstations: { ...doc.workstations, [pcId]: { x: point.x, y: point.y, rotation: 0 } },
    }))
    store.getState().select([`pc:${pcId}`])
    announce(
      `${pcName(pcId)} placed at x ${Math.round(point.x)}, y ${Math.round(point.y)}. Use the arrow keys or drag to move it.`,
    )
  }

  const addFixture = (kind: FixtureKind, at?: Point) => {
    const d = store.getState().draft
    if (!d) return
    const preset = FIXTURES[kind]
    const point =
      at ??
      snapAndClamp(
        viewCentre(),
        halfExtent(preset.width, preset.height, 0),
        room,
        d.settings.grid_size,
        true,
      )
    const fixture = newFixture(kind, point.x, point.y)
    store
      .getState()
      .edit((doc) => ({ ...doc, fixtures: { ...doc.fixtures, [fixture.id]: fixture } }))
    store.getState().select([`fx:${fixture.id}`])
    announce(`${preset.label} added. Drag it, or use the Details panel to size it.`)
  }

  const onDropItem = (item: DragItem, point: Point) => {
    if (item.type === 'pc') placePc(item.id, point)
    else addFixture(item.kind, point)
  }

  const removeKeys = (keys: ObjectKey[]) => {
    if (keys.length === 0) return
    store.getState().edit((doc) => {
      const workstations = { ...doc.workstations }
      const fixtures = { ...doc.fixtures }
      for (const key of keys) {
        if (key.startsWith('pc:')) delete workstations[key.slice(3)]
        if (key.startsWith('fx:')) delete fixtures[key.slice(3)]
      }
      return { ...doc, workstations, fixtures }
    })
    store.getState().select([])
    announce(
      keys.length === 1 && keys[0].startsWith('pc:')
        ? `${pcName(keys[0].slice(3))} removed from the plan. It is still registered to this room.`
        : `${keys.length} object${keys.length === 1 ? '' : 's'} removed.`,
    )
  }

  const rotateKeys = (keys: ObjectKey[], direction: 1 | -1) => {
    if (keys.length === 0) return
    let refused = 0
    store.getState().edit((doc) => {
      const workstations = { ...doc.workstations }
      const fixtures = { ...doc.fixtures }
      for (const key of keys) {
        const id = key.slice(3)
        if (key.startsWith('pc:') && workstations[id]) {
          workstations[id] = {
            ...workstations[id],
            rotation: quarterTurn(workstations[id].rotation, direction),
          }
        }
        if (key.startsWith('fx:') && fixtures[id]) {
          const f = fixtures[id]
          const next = { ...f, rotation: normalizeRotation(f.rotation + 90 * direction) }
          if (
            pointsInsideRoom(
              corners(next.x, next.y, next.width, next.height, next.rotation),
              doc.settings,
            )
          )
            fixtures[id] = next
          else refused++
        }
      }
      return { ...doc, workstations, fixtures }
    })
    announce(
      refused > 0
        ? `${refused} fixture${refused === 1 ? '' : 's'} could not turn without leaving the room.`
        : 'Turned.',
    )
  }

  const duplicateKeys = (keys: ObjectKey[]) => {
    const d = store.getState().draft
    if (!d) return
    const copies = keys
      .filter((k) => k.startsWith('fx:'))
      .map((k) => d.fixtures[k.slice(3)])
      .filter(Boolean)
      .map((f) => {
        const offset = d.settings.grid_size
        const point = snapAndClamp(
          { x: f.x + offset, y: f.y + offset },
          halfExtent(f.width, f.height, f.rotation),
          room,
          d.settings.grid_size,
          false,
        )
        return { ...f, id: crypto.randomUUID(), x: point.x, y: point.y }
      })
    if (copies.length === 0) return
    store.getState().edit((doc) => ({
      ...doc,
      fixtures: { ...doc.fixtures, ...Object.fromEntries(copies.map((c) => [c.id, c])) },
    }))
    store.getState().select(copies.map((c) => `fx:${c.id}` as ObjectKey))
    announce(`${copies.length === 1 ? 'Fixture' : `${copies.length} fixtures`} duplicated.`)
  }

  const nudge = (dx: number, dy: number) => {
    const { draft: d, selection: sel } = store.getState()
    if (!d || sel.length === 0) return
    const moved: Record<string, Point> = {}
    for (const key of sel) {
      const id = key.slice(3)
      if (key.startsWith('pc:')) {
        const w = d.workstations[id]
        if (!w) continue
        const next = { x: w.x + dx, y: w.y + dy }
        if (!boxInsideRoom(workstationBox(next.x, next.y, size), d.settings))
          return announce('At the edge of the room.')
        moved[id] = next
      } else {
        const f = d.fixtures[id]
        if (!f) continue
        if (
          !pointsInsideRoom(corners(f.x + dx, f.y + dy, f.width, f.height, f.rotation), d.settings)
        )
          return announce('At the edge of the room.')
      }
    }
    const statics = Object.fromEntries(
      Object.entries(d.workstations).filter(([id]) => !(id in moved)),
    )
    for (const [id, p] of Object.entries(moved)) {
      const hit = overlapsAny(id, p, statics, size)
      if (hit) return announce(`${pcName(id)} would overlap ${pcName(hit)}.`)
    }
    store.getState().edit((doc) => {
      const workstations = { ...doc.workstations }
      const fixtures = { ...doc.fixtures }
      for (const key of sel) {
        const id = key.slice(3)
        if (key.startsWith('pc:') && workstations[id])
          workstations[id] = {
            ...workstations[id],
            x: workstations[id].x + dx,
            y: workstations[id].y + dy,
          }
        if (key.startsWith('fx:') && fixtures[id])
          fixtures[id] = { ...fixtures[id], x: fixtures[id].x + dx, y: fixtures[id].y + dy }
      }
      return { ...doc, workstations, fixtures }
    }, 'nudge')
    if (sel.length === 1) {
      const id = sel[0].slice(3)
      const p = sel[0].startsWith('pc:')
        ? store.getState().draft!.workstations[id]
        : store.getState().draft!.fixtures[id]
      if (p) announce(`${describeKey(sel[0])} at x ${Math.round(p.x)}, y ${Math.round(p.y)}.`)
    }
  }

  const unplacedIds = useMemo(
    () =>
      [...plan.pcs, ...plan.unplaced]
        .filter((pc) => draft && !(pc.id in draft.workstations))
        .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }))
        .map((pc) => pc.id),
    [plan.pcs, plan.unplaced, draft],
  )

  const arrange = (gap: number): number => {
    const d = store.getState().draft
    if (!d) return 0
    const spots = arrangeInRows(unplacedIds, d.workstations, room, {
      size,
      grid: d.settings.grid_size,
      gap,
    })
    const count = Object.keys(spots).length
    if (count === 0) return 0
    store.getState().edit((doc) => ({
      ...doc,
      workstations: {
        ...doc.workstations,
        ...Object.fromEntries(
          Object.entries(spots).map(([id, p]) => [id, { x: p.x, y: p.y, rotation: 0 }]),
        ),
      },
    }))
    announce(`${count} PC${count === 1 ? '' : 's'} arranged. Undo puts them back.`)
    return count
  }

  const applySettings = (next: LayoutSettings): string | null => {
    const d = store.getState().draft
    if (!d) return null
    const outside =
      Object.values(d.workstations).filter(
        (w) => !boxInsideRoom(workstationBox(w.x, w.y, size), next),
      ).length +
      Object.values(d.fixtures).filter(
        (f) => !pointsInsideRoom(corners(f.x, f.y, f.width, f.height, f.rotation), next),
      ).length
    if (outside > 0)
      return `${outside} object${outside === 1 ? ' would' : 's would'} be left outside a room that size. Move them first.`
    store.getState().edit((doc) => ({ ...doc, settings: next }))
    announce(`Room set to ${next.width} by ${next.height}, grid ${next.grid_size}.`)
    return null
  }

  /* ── saving ────────────────────────────────────────────────────────── */

  const doSave = (onSaved?: () => void) => {
    const { draft: d, revision } = store.getState()
    if (!d || revision === null || !plan.layout) return
    const request = toSaveRequest(d, revision)
    setSaveError(null)

    save.mutate(
      { version: plan.layout.version, request },
      {
        onSuccess: (saved) => {
          ownRevision.current = saved.layout?.revision ?? null
          loaded.current = {
            version: saved.layout?.version ?? null,
            revision: saved.layout?.revision ?? null,
          }
          store.getState().reset(docFromPlan(saved), saved.layout?.revision ?? null)
          // Only now does the cache change, so the reconcile effect sees a
          // revision it already knows is its own.
          queryClient.setQueryData(floorPlanKeys.room(roomId), saved)
          if (user) clearDraft(user.id, roomId)
          setRemote(null)
          setRecovered(null)
          announce('Floorplan saved.')
          onSaved?.()
        },
        onError: (error) => {
          if (!isAxiosError(error))
            return setSaveError('The Floorplan could not be saved. Try again.')
          const status = error.response?.status
          const body = error.response?.data as Partial<LayoutStaleConflict> & {
            errors?: Record<string, string[]>
            code?: string
          }

          if (status === 409 && body?.code === 'layout_stale' && body.current) {
            const theirs = docFromPlan(body.current)
            const { base: b, draft: mine } = store.getState()
            if (theirs && b && mine) {
              setConflict({
                merge: merge3(b, mine, theirs),
                current: body.current,
                by: body.current.layout?.updated_by?.name ?? null,
              })
            }
            return
          }
          if (status === 409) {
            setSaveError(
              'This layout is no longer the active one. Reload to see the current Floorplan.',
            )
            void queryClient.invalidateQueries({ queryKey: floorPlanKeys.room(roomId) })
            return
          }
          if (status === 422 && body?.errors) {
            const marks: Partial<Record<ObjectKey, string>> = {}
            for (const [path, messages] of Object.entries(body.errors)) {
              const match = /^(workstations|fixtures)\.(\d+)\./.exec(path)
              if (!match) continue
              const item =
                match[1] === 'workstations'
                  ? request.workstations[Number(match[2])]
                  : request.fixtures[Number(match[2])]
              if (!item) continue
              const key = (
                match[1] === 'workstations'
                  ? `pc:${(item as { pc_unit: string }).pc_unit}`
                  : `fx:${(item as { id: string }).id}`
              ) as ObjectKey
              marks[key] = messages[0]
            }
            store.getState().setInvalid(marks)
            const n = Object.keys(marks).length
            setSaveError(
              n > 0
                ? `${n} object${n === 1 ? ' needs' : 's need'} attention before saving — they are outlined in red.`
                : 'Some changes were refused. Check the layout and try again.',
            )
            return
          }
          if (status === 403)
            return setSaveError('You do not have permission to edit this Floorplan.')
          setSaveError('The Floorplan could not be saved. Check your connection and try again.')
        },
      },
    )
  }

  const resolveConflict = (choices: Partial<Record<ObjectKey, 'mine' | 'theirs'>>) => {
    if (!conflict) return
    const theirs = docFromPlan(conflict.current)
    if (!theirs || !conflict.current.layout) return
    queryClient.setQueryData(floorPlanKeys.room(roomId), conflict.current)
    loaded.current = {
      version: conflict.current.layout.version,
      revision: conflict.current.layout.revision,
    }
    store
      .getState()
      .rebase(theirs, conflict.current.layout.revision, resolveConflicts(conflict.merge, choices))
    setConflict(null)
    setRemote(null)
    doSave()
  }

  const combineNow = () => {
    const { base: b, draft: mine } = store.getState()
    const theirs = docFromPlan(plan)
    if (!b || !mine || !theirs || !plan.layout) return
    const merged = merge3(b, mine, theirs)
    if (merged.conflicts.length > 0) {
      setConflict({ merge: merged, current: plan, by: remote?.by ?? null })
      return
    }
    loaded.current = { version: plan.layout.version, revision: plan.layout.revision }
    store.getState().rebase(theirs, plan.layout.revision, merged.doc)
    setRemote(null)
    announce(
      `Combined: ${merged.applied} of your changes carried over onto the latest version. Nothing clashed.`,
    )
  }

  const restoreRecovered = () => {
    if (!recovered || !plan.layout) return
    const current = docFromPlan(plan)
    if (!current) return
    if (recovered.revision === plan.layout.revision) {
      store.getState().rebase(current, plan.layout.revision, recovered.draft)
      announce('Your unsaved changes were restored.')
    } else {
      const merged = merge3(recovered.base, recovered.draft, current)
      store.getState().rebase(current, plan.layout.revision, merged.doc)
      announce(
        merged.conflicts.length > 0
          ? `Restored ${merged.applied} changes. ${merged.conflicts.length} clashed with a newer save and were left as saved.`
          : `Restored ${merged.applied} changes onto the latest version.`,
      )
    }
    store.getState().setMode('edit')
    setRecovered(null)
  }

  const discard = () => {
    const { base: b, revision } = store.getState()
    store.getState().reset(b, revision)
    if (user) clearDraft(user.id, roomId)
    setSaveError(null)
    announce('Changes discarded.')
  }

  /* ── keyboard ──────────────────────────────────────────────────────── */

  const handlers = useRef({ doSave, removeKeys, rotateKeys, duplicateKeys, nudge })
  handlers.current = { doSave, removeKeys, rotateKeys, duplicateKeys, nudge }

  useEffect(() => {
    if (preview) return
    const onKey = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null
      if (target?.closest('input, textarea, select, [contenteditable="true"], [role="dialog"]'))
        return
      if (document.querySelector('[role="dialog"][aria-modal="true"]')) return

      const state = store.getState()
      const mod = event.ctrlKey || event.metaKey
      const key = event.key
      const isEditing = state.mode === 'edit' && plan.editor.can_edit && plan.layout !== null
      const h = handlers.current

      if (mod && key.toLowerCase() === 's') {
        event.preventDefault()
        if (isEditing && isDirty(state)) h.doSave()
        return
      }
      if (isEditing && mod && key.toLowerCase() === 'z') {
        event.preventDefault()
        if (event.shiftKey) state.redo()
        else state.undo()
        return
      }
      if (isEditing && mod && key.toLowerCase() === 'y') {
        event.preventDefault()
        state.redo()
        return
      }
      if (isEditing && mod && key.toLowerCase() === 'd') {
        event.preventDefault()
        h.duplicateKeys(state.selection)
        return
      }
      if (mod || event.altKey) return

      if (key === 'Escape') {
        if (state.selection.length) state.select([])
        return
      }
      if (key.startsWith('Arrow')) {
        const dx = key === 'ArrowLeft' ? -1 : key === 'ArrowRight' ? 1 : 0
        const dy = key === 'ArrowUp' ? -1 : key === 'ArrowDown' ? 1 : 0
        event.preventDefault()
        if (isEditing && state.selection.length > 0 && state.draft) {
          const step = event.shiftKey ? 1 : state.draft.settings.grid_size
          h.nudge(dx * step, dy * step)
        } else {
          state.panByStep(dx, dy)
        }
        return
      }
      if (isEditing && (key === 'Delete' || key === 'Backspace')) {
        event.preventDefault()
        h.removeKeys(state.selection)
        return
      }
      if (isEditing && key.toLowerCase() === 'r') {
        h.rotateKeys(state.selection, event.shiftKey ? -1 : 1)
        return
      }
      if (key === '+' || key === '=') return state.zoomIn()
      if (key === '-' || key === '_') return state.zoomOut()
      if (key.toLowerCase() === 'f' || key === '0') return state.fit()
      if (key.toLowerCase() === 'g') return state.setShowGrid(!state.showGrid)
      if (isEditing && key.toLowerCase() === 's') return state.setSnap(!state.snap)
      if (key === '?') setDialog('shortcuts')
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [preview, store, plan.editor.can_edit, plan.layout])

  /* ── render ────────────────────────────────────────────────────────── */

  const machines = [...plan.pcs, ...plan.unplaced].filter(
    (pc, i, all) => all.findIndex((p) => p.id === pc.id) === i,
  )
  const placedCount = draft ? Object.keys(draft.workstations).length : 0
  const location = [plan.room.building?.name, plan.room.floor?.name].filter(Boolean).join(' · ')

  const saveState: SaveState = save.isPending
    ? { kind: 'saving' }
    : saveError
      ? { kind: 'failed', message: saveError }
      : dirty
        ? { kind: 'unsaved', count: changes }
        : {
            kind: 'saved',
            at: plan.layout?.updated_at ?? null,
            by: plan.layout?.updated_by?.name ?? null,
          }

  const activate = (key: ObjectKey) => {
    if (window.matchMedia?.('(min-width: 80rem)').matches === false) setDrawer('details')
    void key
  }

  const selectedPc =
    selection.length === 1 && selection[0].startsWith('pc:')
      ? machines.find((pc) => `pc:${pc.id}` === selection[0])
      : undefined

  const details = draft && (
    <DetailsPanel
      plan={plan}
      doc={draft}
      selection={selection}
      editing={editing}
      invalid={invalid}
      onEdit={(change, coalesceKey) => store.getState().edit(change, coalesceKey)}
      onRemove={removeKeys}
      onDuplicate={duplicateKeys}
      onRotate={rotateKeys}
      onClose={() => {
        store.getState().select([])
        setDrawer(null)
      }}
      onAnnounce={announce}
    />
  )

  const workstations = draft && (
    <WorkstationsPanel
      plan={plan}
      doc={draft}
      editing={editing}
      canMoveIn={canMoveIn}
      selection={selection}
      onAnnounce={announce}
      onPlace={(pcId) => {
        placePc(pcId)
        setDrawer(null)
      }}
      onSelect={(pcId) => {
        store.getState().select([`pc:${pcId}`])
        const w = store.getState().draft?.workstations[pcId]
        if (w) store.getState().centreOn(w.x, w.y)
        setDrawer(null)
      }}
    />
  )

  return (
    <div className="flex flex-col gap-4">
      <LiveRegion message={announcement} />

      {/* Compact on purpose: title, actions, counts and the toolbar take about
          150px, so at 1280×720 the canvas starts above the fold. */}
      <header className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <div className="flex min-w-0 items-center gap-2">
            <nav aria-label="Breadcrumb" className="shrink-0">
              <ol className="flex items-center gap-1.5 text-sm text-muted">
                <li>
                  <Link
                    to="/app/floor-plan"
                    className="font-medium text-primary-strong underline-offset-4 hover:underline"
                  >
                    Floorplan
                  </Link>
                </li>
                <li aria-hidden="true">
                  <ChevronRight className="size-4" />
                </li>
              </ol>
            </nav>
            <h1 className="min-w-0 truncate text-xl font-bold text-ink-strong">{plan.room.name}</h1>
          </div>

          {plan.layout && (
            <div className="flex flex-wrap items-center gap-2">
              {!preview && <LiveStatus live={live} compact />}
              <PresenceIndicator members={members} selfId={user?.id} editing={editingMembers} />
              <SaveStateBadge state={saveState} onRetry={saveError ? () => doSave() : undefined} />
              {plan.editor.can_edit &&
                !preview &&
                (editing ? (
                  <>
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={discard}
                      disabled={!dirty || save.isPending}
                    >
                      Discard
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => doSave()}
                      loading={save.isPending}
                      disabled={!dirty}
                    >
                      Save
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => store.getState().setMode('view')}
                      disabled={dirty}
                    >
                      Done
                    </Button>
                  </>
                ) : (
                  <Button
                    size="sm"
                    leftIcon={<Pencil className="size-4" aria-hidden="true" />}
                    onClick={() => store.getState().setMode('edit')}
                  >
                    Edit layout
                  </Button>
                ))}
            </div>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
          <p className="text-sm text-muted">
            {location || plan.room.code}
            {plan.layout && (
              <>
                {' · '}
                <span className="tabular-nums">
                  {machines.length} in room · {placedCount} placed · {machines.length - placedCount}{' '}
                  not placed
                </span>
              </>
            )}
          </p>

          {plan.layout && machines.length > 0 && (
            <div
              role="group"
              aria-label="Highlight by status"
              className="flex flex-wrap items-center gap-2"
            >
              {PC_STATUS_VALUES.map((value) => {
                const count = machines.filter((pc) => pc.status.value === value).length
                const on = highlight?.has(value) ?? false
                const tone = PC_STATUS_TONE_CLASSES[PC_STATUS[value].tone]
                const Icon = PC_STATUS[value].icon
                return (
                  <button
                    key={value}
                    type="button"
                    aria-pressed={on}
                    onClick={() =>
                      setHighlight((current) => {
                        const next = new Set(current ?? [])
                        if (next.has(value)) next.delete(value)
                        else next.add(value)
                        return next.size === 0 ? null : next
                      })
                    }
                    className={cn(
                      'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
                      tone.border,
                      on ? cn(tone.wash, tone.text) : 'bg-surface text-ink hover:bg-surface-sunken',
                    )}
                  >
                    <Icon className={cn('size-4', tone.text)} aria-hidden="true" />
                    {PC_STATUS[value].label}
                    <span className="tabular-nums">{count}</span>
                  </button>
                )
              })}
              {highlight && (
                <button
                  type="button"
                  onClick={() => setHighlight(null)}
                  className="text-sm font-medium text-primary-strong underline-offset-4 hover:underline"
                >
                  Show all
                </button>
              )}
            </div>
          )}
        </div>
      </header>

      {!plan.layout ? (
        <div className="rounded-lg border border-border bg-surface">
          <SetupLayout
            roomName={plan.room.name}
            machineCount={machines.length}
            canEdit={plan.editor.can_edit && !preview}
            creating={create.isPending}
            error={create.error}
            onCreate={(sizeChoice) =>
              create.mutate(sizeChoice, {
                onSuccess: () => {
                  store.getState().setMode('edit')
                  announce('Floorplan created. Place the room’s PCs from the Workstations panel.')
                },
              })
            }
          />
        </div>
      ) : draft ? (
        <>
          {recovered && (
            <Alert tone="info" title="You have unsaved changes from earlier">
              <span className="block">
                {countChanges(recovered.base, recovered.draft)} changes were kept in this tab{' '}
                {new Date(recovered.savedAt).toLocaleTimeString([], {
                  hour: '2-digit',
                  minute: '2-digit',
                })}
                .
              </span>
              <span className="mt-3 flex flex-wrap gap-3">
                <Button
                  size="sm"
                  leftIcon={<History className="size-4" aria-hidden="true" />}
                  onClick={restoreRecovered}
                >
                  Restore them
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => {
                    if (user) clearDraft(user.id, roomId)
                    setRecovered(null)
                  }}
                >
                  Discard
                </Button>
              </span>
            </Alert>
          )}
          {remote && dirty && (
            <Alert
              tone="warning"
              title={`${remote.by ?? 'Someone'} saved this Floorplan while you were editing`}
            >
              <span className="block">
                Your unsaved changes are kept. Combine them with the newer version before saving.
              </span>
              <Button size="sm" className="mt-3" onClick={combineNow}>
                Combine now
              </Button>
            </Alert>
          )}
          {saveError && <Alert tone="error">{saveError}</Alert>}

          <EditorToolbar
            editing={editing}
            hasSelection={selection.length > 0}
            hasFixtureSelection={selection.some((k) => k.startsWith('fx:'))}
            unplacedCount={unplacedIds.length}
            onAddFixture={(kind) => addFixture(kind)}
            onRotate={() => rotateKeys(store.getState().selection, 1)}
            onDelete={() => removeKeys(store.getState().selection)}
            onDuplicate={() => duplicateKeys(store.getState().selection)}
            onArrange={() => setDialog('arrange')}
            onSettings={() => setDialog('settings')}
            onShortcuts={() => setDialog('shortcuts')}
            end={
              <span className="flex flex-wrap gap-2 xl:hidden">
                <Button
                  size="sm"
                  variant="secondary"
                  leftIcon={<ListTree className="size-4" aria-hidden="true" />}
                  onClick={() => setDrawer('workstations')}
                >
                  Workstations
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  leftIcon={<PanelRight className="size-4" aria-hidden="true" />}
                  onClick={() => setDrawer('details')}
                >
                  Details
                </Button>
              </span>
            }
          />

          <div
            ref={workspace}
            style={workspaceHeight ? { height: workspaceHeight } : undefined}
            className="grid h-[calc(100dvh-12rem)] min-h-80 gap-4 xl:grid-cols-[20rem_minmax(0,1fr)_23rem]"
          >
            <aside
              aria-label="Workstations panel"
              className="hidden min-h-0 flex-col overflow-hidden rounded-lg border border-border bg-surface p-4 xl:flex"
            >
              {workstations}
            </aside>

            <div
              role="group"
              aria-label={`Floorplan of ${plan.room.name}, ${placedCount} workstation${placedCount === 1 ? '' : 's'} placed`}
              aria-describedby="floorplan-help"
              className={cn(
                'relative min-h-0 overflow-hidden rounded-lg border bg-surface-sunken',
                editing ? 'border-primary' : 'border-border',
              )}
            >
              <p id="floorplan-help" className="sr-only">
                Tab to a workstation and press Enter for its details.
                {editing
                  ? ' Arrow keys move the selection; R turns it; Delete removes it; Control+Z undoes.'
                  : ' Arrow keys pan; plus and minus zoom.'}
              </p>
              <FloorPlanCanvas
                plan={plan}
                doc={draft}
                editing={editing}
                highlight={highlight}
                onAnnounce={announce}
                onDropItem={onDropItem}
                onActivate={activate}
              />
              {editing && placedCount === 0 && Object.keys(draft.fixtures).length === 0 && (
                <div className="pointer-events-none absolute inset-x-4 top-4 flex justify-center">
                  <p className="pointer-events-auto max-w-md rounded-md border border-border bg-surface px-4 py-3 text-center text-sm text-ink shadow-(--shadow-overlay-sm)">
                    {unplacedIds.length > 0
                      ? `Place this room’s ${unplacedIds.length} PC${unplacedIds.length === 1 ? '' : 's'} from the Workstations panel, or `
                      : 'Add desks, walls and doors from the toolbar. '}
                    {unplacedIds.length > 0 && (
                      <button
                        type="button"
                        className="font-semibold text-primary-strong underline underline-offset-2"
                        onClick={() => setDialog('arrange')}
                      >
                        arrange them in rows
                      </button>
                    )}
                    {unplacedIds.length > 0 && '.'}
                  </p>
                </div>
              )}
              {selectedPc && (
                <div className="absolute inset-x-3 bottom-3 flex items-center justify-between gap-3 rounded-md border border-border bg-surface px-4 py-2 shadow-(--shadow-overlay-sm) xl:hidden">
                  <span className="flex min-w-0 items-center gap-2">
                    <span className="truncate text-sm font-semibold text-ink-strong">
                      {selectedPc.name}
                    </span>
                    <PcStatusBadge status={selectedPc.status.value} />
                  </span>
                  <Button size="sm" variant="secondary" onClick={() => setDrawer('details')}>
                    Details
                  </Button>
                </div>
              )}
            </div>

            {/* Focusable because it scrolls on its own: a keyboard user must be
                able to reach and scroll a long PC record (WCAG 2.1.1). */}
            <aside
              aria-label="Details panel"
              tabIndex={0}
              className="hidden min-h-0 overflow-y-auto rounded-lg border border-border bg-surface p-5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus xl:block"
            >
              {details}
            </aside>
          </div>

          <Drawer
            open={drawer === 'workstations'}
            onClose={() => setDrawer(null)}
            title="Workstations"
            size="sm"
          >
            {workstations}
          </Drawer>
          <Drawer
            open={drawer === 'details'}
            onClose={() => setDrawer(null)}
            title="Details"
            size="sm"
          >
            {details}
          </Drawer>

          <LayoutSettingsDialog
            open={dialog === 'settings'}
            settings={draft.settings}
            onClose={() => setDialog(null)}
            onApply={applySettings}
          />
          <ShortcutsDialog open={dialog === 'shortcuts'} onClose={() => setDialog(null)} />
          <AutoArrangeDialog
            open={dialog === 'arrange'}
            count={unplacedIds.length}
            onClose={() => setDialog(null)}
            onArrange={arrange}
          />
          <ConflictDialog
            open={conflict !== null}
            merge={conflict?.merge ?? null}
            by={conflict?.by ?? null}
            describe={describeKey}
            saving={save.isPending}
            onCancel={() => {
              if (conflict?.current.layout) {
                const theirs = docFromPlan(conflict.current)
                if (theirs) {
                  queryClient.setQueryData(floorPlanKeys.room(roomId), conflict.current)
                  loaded.current = {
                    version: conflict.current.layout.version,
                    revision: conflict.current.layout.revision,
                  }
                  store
                    .getState()
                    .rebase(
                      theirs,
                      conflict.current.layout.revision,
                      resolveConflicts(conflict.merge, {}),
                    )
                  announce('Combined with the newer version. Review, then save when ready.')
                }
              }
              setConflict(null)
            }}
            onResolve={resolveConflict}
          />
        </>
      ) : null}

      <Modal
        open={guard.pending !== null}
        onClose={guard.stay}
        title="Leave without saving?"
        description={`You have ${changes} unsaved change${changes === 1 ? '' : 's'} on this Floorplan. If you leave, they stay in this tab and are offered back when you return.`}
        footer={
          <div className="flex flex-wrap justify-end gap-3">
            <Button variant="secondary" onClick={guard.stay}>
              Stay
            </Button>
            <Button variant="secondary" onClick={guard.leave}>
              Leave
            </Button>
            <Button onClick={() => doSave(guard.leave)} loading={save.isPending}>
              Save and leave
            </Button>
          </div>
        }
      >
        <p className="text-sm text-muted">Saving applies every change in one go.</p>
      </Modal>
    </div>
  )
}

/** Exported for tests: the empty draft used when nothing is loaded. */
export type { LayoutDoc }
