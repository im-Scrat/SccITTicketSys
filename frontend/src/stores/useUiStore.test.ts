import { describe, it, expect, beforeEach } from 'vitest'
import { useUiStore } from './useUiStore'

describe('useUiStore', () => {
  beforeEach(() => {
    useUiStore.setState({ sidebarOpen: true })
  })

  it('toggles the sidebar', () => {
    expect(useUiStore.getState().sidebarOpen).toBe(true)
    useUiStore.getState().toggleSidebar()
    expect(useUiStore.getState().sidebarOpen).toBe(false)
  })
})
