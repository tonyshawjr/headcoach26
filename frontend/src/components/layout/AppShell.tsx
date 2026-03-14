import { Component, type ReactNode } from 'react';
import { Outlet } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Navbar } from './Navbar';
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
  const sidebarOpen = useUIStore((s) => s.sidebarOpen);

  return (
    <div className="h-screen overflow-hidden bg-[var(--bg-primary)]">
      <Sidebar />
      <div className={`flex h-full flex-col transition-all duration-300 ${sidebarOpen ? 'pl-60' : 'pl-16'}`}>
        <Navbar />
        <BreakingTicker />
        <main className="flex-1 overflow-y-auto">
          <div className="mx-auto max-w-[1400px] p-6">
            <OutletErrorBoundary>
              <Outlet />
            </OutletErrorBoundary>
          </div>
        </main>
      </div>
    </div>
  );
}
