import { Component, type ErrorInfo, type ReactNode } from 'react'
import { ErrorPage } from '@/pages/ErrorPage'

interface Props {
  children: ReactNode
  /**
   * Replaces the full-page fallback — e.g. a panel-sized ErrorState, so one
   * failing preview in a dialog does not take the whole screen with it.
   */
  fallback?: (reset: () => void) => ReactNode
  /**
   * Render the children again when any of these change while the fallback is
   * up (the next slide, a new route): the failure belonged to what was shown.
   */
  resetKeys?: readonly unknown[]
}

interface State {
  hasError: boolean
}

/**
 * Error boundary. At the app level it catches render errors anywhere below it
 * and shows a recoverable fallback instead of a blank white screen; with
 * `fallback` and `resetKeys` it also contains a failure to one region. (React's function API
 * has no error-boundary equivalent, so this stays a class component.)
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false }

  static getDerivedStateFromError(): State {
    return { hasError: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Surface during development; a later phase can forward this to logging.
    console.error('Unhandled UI error:', error, info.componentStack)
  }

  componentDidUpdate(previous: Props): void {
    if (!this.state.hasError) return
    const before = previous.resetKeys ?? []
    const after = this.props.resetKeys ?? []
    if (
      before.length !== after.length ||
      before.some((key, index) => !Object.is(key, after[index]))
    ) {
      this.handleReset()
    }
  }

  handleReset = () => {
    this.setState({ hasError: false })
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return this.props.fallback ? (
        this.props.fallback(this.handleReset)
      ) : (
        <ErrorPage onReset={this.handleReset} />
      )
    }
    return this.props.children
  }
}
