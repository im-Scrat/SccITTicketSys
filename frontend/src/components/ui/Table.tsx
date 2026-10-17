import type { MouseEvent, ReactNode, ThHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/**
 * Minimal, accessible table primitives on the design tokens. The root wraps the
 * table in a horizontal-scroll container so wide columns never break the page
 * layout (the body must never scroll horizontally). Numeric cells opt into
 * tabular figures via `.tnum`.
 */
export function Table({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className="w-full overflow-x-auto">
      <table className={cn('w-full border-collapse text-sm', className)}>{children}</table>
    </div>
  )
}

export function THead({ children }: { children: ReactNode }) {
  return <thead className="border-b border-border text-left">{children}</thead>
}

export function TBody({ children }: { children: ReactNode }) {
  return <tbody className="divide-y divide-border">{children}</tbody>
}

/**
 * Did this click start on something that has its own behaviour — a link, a
 * button, a form control? A clickable row uses it to leave those alone: a link
 * in the row navigates by itself (and middle-click / Ctrl-click must keep
 * working), and a button's click is not also "open the row".
 */
export function isInteractiveTarget(event: MouseEvent<HTMLElement>): boolean {
  const target = event.target
  return (
    target instanceof Element &&
    target.closest('a, button, input, select, textarea, label, [role="button"]') !== null
  )
}

export function Tr({
  children,
  className,
  onClick,
}: {
  children: ReactNode
  className?: string
  /** Receives the click so a handler can ignore clicks that began on a nested control. */
  onClick?: (event: MouseEvent<HTMLTableRowElement>) => void
}) {
  return (
    <tr
      onClick={onClick}
      className={cn(onClick && 'cursor-pointer hover:bg-surface-sunken', className)}
    >
      {children}
    </tr>
  )
}

interface ThProps extends ThHTMLAttributes<HTMLTableCellElement> {
  children?: ReactNode
}

export function Th({ children, className, ...props }: ThProps) {
  return (
    <th
      className={cn('px-4 py-4 text-sm font-semibold text-muted', className)}
      scope="col"
      {...props}
    >
      {children}
    </th>
  )
}

export function Td({
  children,
  className,
  onClick,
}: {
  children: ReactNode
  className?: string
  onClick?: (event: MouseEvent<HTMLTableCellElement>) => void
}) {
  return (
    <td className={cn('px-4 py-4 align-middle text-ink', className)} onClick={onClick}>
      {children}
    </td>
  )
}
