import { Component, type ErrorInfo, type ReactNode } from 'react'
import { ErrorPage } from '@/pages/ErrorPage'

interface Props {
  children: ReactNode
}

interface State {
  hasError: boolean
}

/**
 * App-level error boundary. Catches render errors anywhere below it and shows a
 * recoverable fallback instead of a blank white screen. (React's function API
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

  handleReset = () => {
    this.setState({ hasError: false })
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return <ErrorPage onReset={this.handleReset} />
    }
    return this.props.children
  }
}
