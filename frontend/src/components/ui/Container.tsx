import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface ContainerProps {
  children: ReactNode
  className?: string
  /** Narrower measure for reading-heavy content (caps line length). */
  size?: 'default' | 'prose'
}

/**
 * Horizontal page gutter + max measure. One place owns the site's outer rhythm
 * so every section lines up on the same vertical edges.
 */
export function Container({ children, className, size = 'default' }: ContainerProps) {
  return (
    <div
      className={cn(
        'mx-auto w-full px-5 sm:px-6 lg:px-8',
        size === 'prose' ? 'max-w-[46rem]' : 'max-w-[76rem]',
        className,
      )}
    >
      {children}
    </div>
  )
}
