import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface SectionHeadingProps {
  title: ReactNode
  lead?: ReactNode
  align?: 'left' | 'center'
  className?: string
  /** Heading level — keep one h1 per page; sections use h2. */
  as?: 'h1' | 'h2' | 'h3'
  id?: string
}

/**
 * Section title + supporting lead. Deliberately no uppercase tracked eyebrow
 * (No-Eyebrow Rule) — hierarchy comes from size and weight. Lead is capped to a
 * comfortable measure.
 */
export function SectionHeading({
  title,
  lead,
  align = 'left',
  className,
  as: Tag = 'h2',
  id,
}: SectionHeadingProps) {
  return (
    <div
      className={cn(
        'flex flex-col gap-3',
        align === 'center' && 'items-center text-center',
        className,
      )}
    >
      <Tag
        id={id}
        className={cn(
          'text-[1.75rem] font-semibold leading-[1.15] tracking-[-0.02em] text-ink-strong sm:text-[2rem]',
        )}
      >
        {title}
      </Tag>
      {lead && (
        <p
          className={cn(
            'max-w-2xl text-[1.0625rem] leading-relaxed text-muted',
            align === 'center' && 'mx-auto',
          )}
        >
          {lead}
        </p>
      )}
    </div>
  )
}
