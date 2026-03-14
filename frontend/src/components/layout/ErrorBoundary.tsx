import { Component, type ReactNode } from 'react';
import { AlertTriangle, Home, RotateCcw } from 'lucide-react';

interface Props {
  children: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  handleReload = () => {
    window.location.reload();
  };

  handleGoHome = () => {
    window.location.href = '/';
  };

  handleDismiss = () => {
    this.setState({ hasError: false, error: null });
  };

  render() {
    if (this.state.hasError) {
      return (
        <div className="flex min-h-[400px] items-center justify-center p-8">
          <div className="w-full max-w-md rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-500/10">
              <AlertTriangle className="h-6 w-6 text-red-400" />
            </div>
            <h2 className="font-display text-lg text-[var(--text-primary)]">Something went wrong</h2>
            <p className="mt-2 text-sm text-[var(--text-secondary)]">
              This page encountered an error. You can try reloading or go back to the dashboard.
            </p>
            {this.state.error && (
              <p className="mt-3 rounded-lg bg-[var(--bg-primary)] p-3 text-left text-xs font-mono text-[var(--text-muted)] break-all">
                {this.state.error.message}
              </p>
            )}
            <div className="mt-6 flex items-center justify-center gap-3">
              <button
                onClick={this.handleGoHome}
                className="inline-flex items-center gap-2 rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-4 py-2 text-sm text-[var(--text-secondary)] transition-colors hover:bg-[var(--bg-elevated)]"
              >
                <Home className="h-4 w-4" />
                Dashboard
              </button>
              <button
                onClick={this.handleReload}
                className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent-blue)] px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-[var(--accent-blue)]/90"
              >
                <RotateCcw className="h-4 w-4" />
                Reload Page
              </button>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
