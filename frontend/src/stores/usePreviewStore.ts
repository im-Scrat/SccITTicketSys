import { create } from 'zustand'

/**
 * The page the post-import review is previewing (Phase 2.10 R7): where to
 * render it (the review stage's own element) and which route. Read by
 * `PreviewPortalRoot`, which lives *beside* the app's router — React Router
 * cannot nest one router inside another, so the preview gets its own
 * `MemoryRouter`, portalled into the stage, and the app's URL never moves.
 */
interface PreviewState {
  target: HTMLElement | null
  path: string | null
  show: (target: HTMLElement, path: string) => void
  clear: () => void
}

export const usePreviewStore = create<PreviewState>((set) => ({
  target: null,
  path: null,
  show: (target, path) => set({ target, path }),
  clear: () => set({ target: null, path: null }),
}))
