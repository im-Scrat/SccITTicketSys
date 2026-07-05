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

export function Tr({
  children,
  className,
  onClick,
}: {
  children: ReactNode
  className?: string
  onClick?: () => void
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
      className={cn('px-3 py-2.5 text-xs font-semibold text-muted', className)}
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
    <td className={cn('px-3 py-2.5 align-middle text-ink', className)} onClick={onClick}>
      {children}
    </td>
  )
}
