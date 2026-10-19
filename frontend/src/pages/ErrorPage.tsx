import { RotateCcw } from 'lucide-react'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Logo } from '@/components/ui/Logo'

/**
 * Full-screen fallback rendered by {@link ErrorBoundary} when a render error
 * escapes. Self-contained (it sits above the layout), with a way to recover.
 */
export function ErrorPage({ onReset }: { onReset?: () => void }) {
  return (
    <div className="flex min-h-dvh flex-col items-center justify-center bg-bg px-6 text-center">
      <Logo withWordmark={false} size={40} />
      <p className="mt-8 font-mono text-sm text-muted">Error</p>
      <h1 className="mt-2 text-2xl font-semibold tracking-[-0.02em] text-ink-strong sm:text-3xl">
        Something went wrong.
      </h1>
      <p className="mt-3 max-w-md text-sm leading-relaxed text-muted">
        An unexpected error interrupted the page. Reloading usually clears it; if it keeps
        happening, let us know.
      </p>
      <div className="mt-8 flex flex-col gap-3 sm:flex-row">
        <Button
          variant="primary"
          size="lg"
          leftIcon={<RotateCcw size={17} aria-hidden="true" />}
          onClick={() => {
            onReset?.()
            window.location.reload()
          }}
        >
          Reload the page
        </Button>
        <ButtonLink href="/" variant="secondary" size="lg">
          Back to homepage
        </ButtonLink>
      </div>
    </div>
  )
}
