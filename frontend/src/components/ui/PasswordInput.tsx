import { Eye, EyeOff } from 'lucide-react'
import { forwardRef, type InputHTMLAttributes, useState } from 'react'
import { cn } from '@/lib/cn'
import { Input } from './Input'

/**
 * Password field with a visibility toggle. The toggle is a real button with an
 * accessible name and `aria-pressed` state; id/aria-* injected by `Field` flow
 * through to the underlying input via prop spread + ref forwarding.
 */
export const PasswordInput = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
  function PasswordInput({ className, ...props }, ref) {
    const [visible, setVisible] = useState(false)

    return (
      <div className="relative">
        <Input
          ref={ref}
          type={visible ? 'text' : 'password'}
          className={cn('pr-10', className)}
          {...props}
        />
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-pressed={visible}
          aria-label={visible ? 'Hide password' : 'Show password'}
          className="absolute inset-y-0 right-0 flex items-center rounded-sm px-2.5 text-muted hover:text-ink"
          tabIndex={-1}
        >
          {visible ? <EyeOff size={16} aria-hidden="true" /> : <Eye size={16} aria-hidden="true" />}
        </button>
      </div>
    )
  },
)
