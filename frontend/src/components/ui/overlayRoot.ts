import { createContext, useContext } from 'react'

/**
 * Where floating layers (Tooltip, Combobox listbox) portal to. Outside any
 * dialog that is `<body>`; inside a Modal or Drawer it is that overlay's root
 * wrapper. Two reasons it cannot simply be `<body>` there:
 *
 * - The overlay makes every other child of `<body>` inert, so a listbox
 *   portalled beside it would be unclickable and hidden from screen readers.
 * - The dialog element itself animates in with a transform, which would make it
 *   the containing block of a `position: fixed` child for the length of the
 *   animation. The root wrapper has no transform, so fixed coordinates stay
 *   viewport coordinates.
 */
export const OverlayRootContext = createContext<HTMLElement | null>(null)

/** The element floating layers should portal into right now. */
export function useOverlayRoot(): HTMLElement {
  return useContext(OverlayRootContext) ?? document.body
}
