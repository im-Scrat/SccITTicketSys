import { createContext, useContext } from 'react'
import { useStore } from 'zustand'
import type { EditorState, EditorStore } from './createEditorStore'

export const EditorStoreContext = createContext<EditorStore | null>(null)

export function useEditorStore(): EditorStore {
  const store = useContext(EditorStoreContext)
  if (!store) throw new Error('useEditorStore must be used inside <EditorStoreProvider>.')
  return store
}

/** Select from the room's editor store; re-renders only when the selection changes. */
export function useEditor<T>(selector: (state: EditorState) => T): T {
  return useStore(useEditorStore(), selector)
}
