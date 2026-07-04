import type { AnchorHTMLAttributes, ButtonHTMLAttributes, ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { cn } from '@/lib/cn'
import { buttonVariants, type ButtonSize, type ButtonVariant } from './buttonVariants'
import { Spinner } from './Spinner'

interface VisualProps {
  variant?: ButtonVariant
  size?: ButtonSize
  leftIcon?: ReactNode
  rightIcon?: ReactNode
}

function Inner({
  leftIcon,
  rightIcon,
  loading,
  children,
}: VisualProps & { loading?: boolean; children: ReactNode }) {
  return (
    <>
      {loading ? <Spinner size={16} /> : leftIcon}
      {children != null && <span>{children}</span>}
      {!loading && rightIcon}
    </>
  )
}

type ButtonProps = VisualProps &
  ButtonHTMLAttributes<HTMLButtonElement> & {
    /** Keeps the button's color and shows a spinner (DESIGN.md: no dimming on load). */
    loading?: boolean
  }

export function Button({
  variant = 'primary',
  size = 'md',
  leftIcon,
  rightIcon,
  loading = false,
  className,
  children,
  type = 'button',
  disabled,
  onClick,
  ...rest
}: ButtonProps) {
  return (
    <button
      type={type}
      className={cn(buttonVariants(variant, size), loading && 'pointer-events-none', className)}
      disabled={disabled}
      aria-busy={loading || undefined}
      onClick={loading ? undefined : onClick}
      {...rest}
    >
      <Inner leftIcon={leftIcon} rightIcon={rightIcon} loading={loading}>
        {children}
      </Inner>
    </button>
  )
}

type ButtonLinkProps = VisualProps & {
  className?: string
  children: ReactNode
} & (
    | ({ to: string; href?: undefined } & Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'>)
    | ({ href: string; to?: undefined } & AnchorHTMLAttributes<HTMLAnchorElement>)
  )

/**
 * A button-styled link: renders a react-router `<Link>` for internal `to`
 * targets and a plain `<a>` for `href` (hash anchors, mailto, external).
 */
export function ButtonLink({
  variant = 'primary',
  size = 'md',
  leftIcon,
  rightIcon,
  className,
  children,
  ...rest
}: ButtonLinkProps) {
  const classes = cn(buttonVariants(variant, size), className)
  const inner = (
    <Inner leftIcon={leftIcon} rightIcon={rightIcon}>
      {children}
    </Inner>
  )

  if ('to' in rest && rest.to !== undefined) {
    const { to, ...anchorRest } = rest
    return (
      <Link to={to} className={classes} {...anchorRest}>
        {inner}
      </Link>
    )
  }

  const { href, ...anchorRest } = rest as { href: string } & AnchorHTMLAttributes<HTMLAnchorElement>
  return (
    <a href={href} className={classes} {...anchorRest}>
      {inner}
    </a>
  )
}
