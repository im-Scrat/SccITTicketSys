import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ErrorBoundary } from './ErrorBoundary'

function Boom({ fail }: { fail: boolean }) {
  if (fail) throw new Error('preview failed')
  return <p>Preview ready</p>
}

describe('ErrorBoundary', () => {
  beforeEach(() => {
    // React and the boundary both log the caught error; keep the run readable.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders a custom fallback that can retry', async () => {
    let fail = true
    function Flaky() {
      return <Boom fail={fail} />
    }

    render(
      <ErrorBoundary
        fallback={(reset) => (
          <button type="button" onClick={reset}>
            Try again
          </button>
        )}
      >
        <Flaky />
      </ErrorBoundary>,
    )

    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(screen.getByText('Preview ready')).toBeInTheDocument()
  })

  it('renders again when a reset key changes', () => {
    const fallback = () => <p>This preview could not be shown</p>
    const { rerender } = render(
      <ErrorBoundary fallback={fallback} resetKeys={['slide-1']}>
        <Boom fail />
      </ErrorBoundary>,
    )
    expect(screen.getByText('This preview could not be shown')).toBeInTheDocument()

    rerender(
      <ErrorBoundary fallback={fallback} resetKeys={['slide-2']}>
        <Boom fail={false} />
      </ErrorBoundary>,
    )
    expect(screen.getByText('Preview ready')).toBeInTheDocument()
  })
})
