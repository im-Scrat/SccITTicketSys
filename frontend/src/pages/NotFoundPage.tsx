import { ArrowLeft } from 'lucide-react'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { ButtonLink } from '@/components/ui/Button'
import { Container } from '@/components/ui/Container'

export default function NotFoundPage() {
  useDocumentMeta({ title: 'Page not found' })

  return (
    <Container className="flex min-h-[60vh] flex-col items-center justify-center py-24 text-center">
      <p className="tnum text-[4rem] font-semibold leading-none tracking-[-0.03em] text-ink-strong">
        404
      </p>
      <h1 className="mt-4 text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
        This page doesn’t exist.
      </h1>
      <p className="mt-3 max-w-md text-[15px] leading-relaxed text-muted">
        The link may be broken or the page may have moved. Let’s get you back to somewhere useful.
      </p>
      <div className="mt-8 flex flex-col gap-3 sm:flex-row">
        <ButtonLink
          to="/"
          variant="primary"
          size="lg"
          leftIcon={<ArrowLeft size={17} aria-hidden="true" />}
        >
          Back to homepage
        </ButtonLink>
        <ButtonLink href="/#platform" variant="secondary" size="lg">
          Explore the platform
        </ButtonLink>
      </div>
    </Container>
  )
}
