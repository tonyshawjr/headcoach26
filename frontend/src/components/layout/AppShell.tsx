import { Component, type ReactNode, useEffect } from 'react';
import { Outlet } from 'react-router-dom';
import { TopNav } from './TopNav';
import { ScoreStrip } from './ScoreStrip';
import { BreakingTicker } from './BreakingTicker';
import { useUIStore } from '@/stores/uiStore';

class OutletErrorBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
  constructor(props: { children: ReactNode }) {
    super(props);
    this.state = { error: null };
  }
  static getDerivedStateFromError(error: Error) {
    return { error };
  }
  render() {
    if (this.state.error) {
      return (
        <div style={{ background: '#fee', border: '2px solid red', padding: 16, borderRadius: 8 }}>
          <h2 style={{ color: 'red', margin: 0 }}>Page Error Caught</h2>
          <pre style={{ whiteSpace: 'pre-wrap', fontSize: 12, marginTop: 8 }}>
            {this.state.error.message}
          </pre>
          <pre style={{ whiteSpace: 'pre-wrap', fontSize: 10, color: '#666', marginTop: 4 }}>
            {this.state.error.stack}
          </pre>
          <button onClick={() => this.setState({ error: null })} style={{ marginTop: 8, padding: '4px 12px' }}>
            Retry
          </button>
        </div>
      );
    }
    return this.props.children;
  }
}

export function AppShell() {
  useEffect(() => {
    useUIStore.getState().initTheme();
  }, []);

  return (
    <div className="min-h-screen bg-[var(--bg-primary)] pb-10">
      <ScoreStrip />
      <TopNav />
      <main className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6">
        <OutletErrorBoundary>
          <Outlet />
        </OutletErrorBoundary>
      </main>
      <BreakingTicker />
    </div>
  );
}
