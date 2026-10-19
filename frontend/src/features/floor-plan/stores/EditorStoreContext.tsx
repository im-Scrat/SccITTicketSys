import { useState, type ReactNode } from 'react'
import { createEditorStore } from './createEditorStore'
import { EditorStoreContext } from './useEditor'

/** Mounts one editor store for one room editor (keyed by the caller per room). */
export function EditorStoreProvider({ snap, children }: { snap: boolean; children: ReactNode }) {
  const [store] = useState(() => createEditorStore({ snap }))
  return <EditorStoreContext.Provider value={store}>{children}</EditorStoreContext.Provider>
}
