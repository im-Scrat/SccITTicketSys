import { cn } from '@/lib/cn'

interface AvatarProps {
  name: string
  size?: 'sm' | 'md' | 'lg'
  className?: string
}

const sizes = {
  sm: 'size-7 text-xs',
  md: 'size-9 text-sm',
  lg: 'size-14 text-lg',
}

/** Two-letter initials from a full name (first + last where available). */
function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0]!.slice(0, 2).toUpperCase()
  return (parts[0]![0]! + parts[parts.length - 1]![0]!).toUpperCase()
}

/**
 * Initials avatar placeholder (no uploaded image yet). `rounded-full` is a
 * deliberate exception to the tokenized radii, reserved for avatars/dots.
 */
export function Avatar({ name, size = 'md', className }: AvatarProps) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'inline-flex shrink-0 items-center justify-center rounded-full bg-primary-subtle font-semibold text-primary-strong',
        sizes[size],
        className,
      )}
    >
      {initials(name)}
    </span>
  )
}
