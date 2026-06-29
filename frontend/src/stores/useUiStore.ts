import { create } from 'zustand'

/** Minimal example store to confirm Zustand wiring. Replace/extend per feature. */
interface UiState {
  sidebarOpen: boolean
  toggleSidebar: () => void
}

export const useUiStore = create<UiState>((set) => ({
  sidebarOpen: true,
  toggleSidebar: () => set((s) => ({ sidebarOpen: !s.sidebarOpen })),
}))
