import { createStore } from 'zustand/vanilla'
import { countChanges, type LayoutDoc, type ObjectKey } from '../lib/layoutDocument'
import {
  INITIAL_VIEWPORT,
  PAN_STEP,
  ZOOM_STEP,
  clampViewport,
  panBy,
  visibleSize,
  zoomAt,
  type Focus,
  type Viewport,
} from '../lib/viewport'

/** Undo depth. Generous for a session, bounded for memory. */
export const HISTORY_LIMIT = 100

/** Edits with the same coalescing key closer together than this are one undo step (an arrow-key burst). */
const COALESCE_MS = 700

export type EditorMode = 'view' | 'edit'

export interface EditorState {
  /** What the server holds, as this editor last loaded it. */
  base: LayoutDoc | null
  /** That copy's revision — sent as `expected_revision` on save. */
  revision: number | null
  /** The administrator's working copy. */
  draft: LayoutDoc | null
  past: LayoutDoc[]
  future: LayoutDoc[]
  coalesceKey: string | null
  coalesceAt: number

  mode: EditorMode
  selection: ObjectKey[]
  snap: boolean
  showGrid: boolean
  viewport: Viewport
  /** Server refusals by object key (from a 422), shown on the canvas and in the panel. */
  invalid: Partial<Record<ObjectKey, string>>

  /** Load what the server holds; the draft and history start over. */
  reset: (doc: LayoutDoc | null, revision: number | null) => void
  /**
   * Accept a new server copy *under* the current draft (after a merge): the
   * draft becomes `draft`, the base and revision move forward, history stays.
   */
  rebase: (base: LayoutDoc, revision: number, draft: LayoutDoc) => void
  /** Apply one edit to the draft as one undo step (or merged into the last, by key). */
  edit: (change: (doc: LayoutDoc) => LayoutDoc, coalesceKey?: string) => void
  undo: () => void
  redo: () => void
  setMode: (mode: EditorMode) => void
  select: (keys: ObjectKey[], additive?: boolean) => void
  setSnap: (snap: boolean) => void
  setShowGrid: (show: boolean) => void
  setInvalid: (invalid: Partial<Record<ObjectKey, string>>) => void
  zoomIn: (focus?: Focus) => void
  zoomOut: (focus?: Focus) => void
  zoomTo: (zoom: number, focus?: Focus) => void
  panByStep: (fx: number, fy: number) => void
  panByUnits: (dx: number, dy: number) => void
  fit: () => void
  /** Centre the view on a point (layout units), keeping the zoom. */
  centreOn: (x: number, y: number) => void
}

export function isDirty(state: Pick<EditorState, 'base' | 'draft'>): boolean {
  return state.base !== null && state.draft !== null && countChanges(state.base, state.draft) > 0
}

/**
 * One store per mounted room editor (created in `EditorStoreProvider`), so two
 * rooms — or a room and the import-review preview of it — never share a draft,
 * a selection or a view.
 */
export function createEditorStore(options: { snap: boolean }) {
  return createStore<EditorState>()((set, get) => {
    const content = () => {
      const doc = get().draft ?? get().base
      return doc ? { width: doc.settings.width, height: doc.settings.height } : null
    }
    const view = (
      next: (size: { width: number; height: number }, current: Viewport) => Viewport,
    ) => {
      const size = content()
      if (size) set({ viewport: next(size, get().viewport) })
    }

    return {
      base: null,
      revision: null,
      draft: null,
      past: [],
      future: [],
      coalesceKey: null,
      coalesceAt: 0,
      mode: 'view',
      selection: [],
      snap: options.snap,
      showGrid: true,
      viewport: INITIAL_VIEWPORT,
      invalid: {},

      reset: (doc, revision) =>
        set((state) => ({
          base: doc,
          revision,
          draft: doc,
          past: [],
          future: [],
          coalesceKey: null,
          invalid: {},
          selection: state.selection.filter((key) => doc !== null && exists(doc, key)),
          viewport:
            doc && state.base && sameSize(doc, state.base) ? state.viewport : INITIAL_VIEWPORT,
        })),

      rebase: (base, revision, draft) =>
        set((state) => ({
          base,
          revision,
          draft,
          past: state.draft ? [...state.past, state.draft].slice(-HISTORY_LIMIT) : state.past,
          future: [],
          coalesceKey: null,
          invalid: {},
        })),

      edit: (change, coalesceKey) =>
        set((state) => {
          if (!state.draft) return {}
          const next = change(state.draft)
          if (next === state.draft) return {}

          const now = Date.now()
          const merge =
            coalesceKey !== undefined &&
            coalesceKey === state.coalesceKey &&
            now - state.coalesceAt < COALESCE_MS

          const sizeChanged = !sameSize(next, state.draft)

          return {
            draft: next,
            past: merge ? state.past : [...state.past, state.draft].slice(-HISTORY_LIMIT),
            future: [],
            coalesceKey: coalesceKey ?? null,
            coalesceAt: now,
            invalid: {},
            selection: state.selection.filter((key) => exists(next, key)),
            viewport: sizeChanged
              ? clampViewport(state.viewport, {
                  width: next.settings.width,
                  height: next.settings.height,
                })
              : state.viewport,
          }
        }),

      undo: () =>
        set((state) => {
          const previous = state.past.at(-1)
          if (!previous || !state.draft) return {}
          return {
            draft: previous,
            past: state.past.slice(0, -1),
            future: [state.draft, ...state.future],
            coalesceKey: null,
            invalid: {},
            selection: state.selection.filter((key) => exists(previous, key)),
          }
        }),

      redo: () =>
        set((state) => {
          const next = state.future[0]
          if (!next || !state.draft) return {}
          return {
            draft: next,
            past: [...state.past, state.draft],
            future: state.future.slice(1),
            coalesceKey: null,
            invalid: {},
            selection: state.selection.filter((key) => exists(next, key)),
          }
        }),

      setMode: (mode) => set({ mode, coalesceKey: null }),

      select: (keys, additive = false) =>
        set((state) => {
          if (!additive) return { selection: keys }
          const current = new Set(state.selection)
          for (const key of keys) {
            if (current.has(key)) current.delete(key)
            else current.add(key)
          }
          return { selection: [...current] }
        }),

      setSnap: (snap) => set({ snap }),
      setShowGrid: (showGrid) => set({ showGrid }),
      setInvalid: (invalid) => set({ invalid }),

      zoomIn: (focus) => view((size, vp) => zoomAt(vp, size, vp.zoom * ZOOM_STEP, focus)),
      zoomOut: (focus) => view((size, vp) => zoomAt(vp, size, vp.zoom / ZOOM_STEP, focus)),
      zoomTo: (zoom, focus) => view((size, vp) => zoomAt(vp, size, zoom, focus)),
      panByStep: (fx, fy) =>
        view((size, vp) => {
          const visible = visibleSize(size, vp.zoom)
          return panBy(vp, size, fx * visible.width * PAN_STEP, fy * visible.height * PAN_STEP)
        }),
      panByUnits: (dx, dy) => view((size, vp) => panBy(vp, size, dx, dy)),
      fit: () => set({ viewport: INITIAL_VIEWPORT }),
      centreOn: (x, y) =>
        view((size, vp) => {
          const visible = visibleSize(size, vp.zoom)
          return clampViewport(
            { zoom: vp.zoom, x: x - visible.width / 2, y: y - visible.height / 2 },
            size,
          )
        }),
    }
  })
}

export type EditorStore = ReturnType<typeof createEditorStore>

function exists(doc: LayoutDoc, key: ObjectKey): boolean {
  if (key === 'settings') return true
  const id = key.slice(3)
  return key.startsWith('pc:') ? id in doc.workstations : id in doc.fixtures
}

function sameSize(a: LayoutDoc, b: LayoutDoc): boolean {
  return a.settings.width === b.settings.width && a.settings.height === b.settings.height
}
