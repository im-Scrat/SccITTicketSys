import { createContext, useContext } from 'react'

/**
 * True inside the post-import review's read-only preview (Phase 2.10 R7).
 * Pages render exactly as they do in the app; only side effects that would
 * reach outside the preview — the document title, a realtime subscription —
 * check this and stand down.
 */
export const PreviewModeContext = createContext(false)

export function useIsPreview(): boolean {
  return useContext(PreviewModeContext)
}
