import { lazy, Suspense, useEffect, useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from '@/components/ui/sonner';
import { useSession } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { AppShell } from '@/components/layout/AppShell';
import { ErrorBoundary } from '@/components/layout/ErrorBoundary';

// Pages
import Login from '@/pages/Login';
import Dashboard from '@/pages/Dashboard';
import MyTeam from '@/pages/MyTeam';
import Schedule from '@/pages/Schedule';
import Standings from '@/pages/Standings';
import WeeklyPrep from '@/pages/WeeklyPrep';
import BoxScore from '@/pages/BoxScore';
import LeagueHub from '@/pages/LeagueHub';
import PlayerProfile from '@/pages/PlayerProfile';
import PressConference from '@/pages/PressConference';
import Leaders from '@/pages/Leaders';
import ArticlePage from '@/pages/ArticlePage';
import SettingsPage from '@/pages/SettingsPage';
import OwnerOffice from '@/pages/OwnerOffice';
import TradePage from '@/pages/TradePage';
import FreeAgency from '@/pages/FreeAgency';
import DraftRoom from '@/pages/DraftRoom';
import CoachingStaff from '@/pages/CoachingStaff';
import Legacy from '@/pages/Legacy';
import Glossary from '@/pages/Glossary';
import FranchiseSetup from '@/pages/FranchiseSetup';

// Phase 4: Multiplayer Pages (lazy loaded)
const MessageBoard = lazy(() => import('./pages/MessageBoard'));
const CommissionerPage = lazy(() => import('./pages/CommissionerPage'));
const NotificationsPage = lazy(() => import('./pages/NotificationsPage'));

// Phase 5: AI Pack Pages (lazy loaded)
const AiStudio = lazy(() => import('./pages/AiStudio'));
const RosterImport = lazy(() => import('./pages/RosterImport'));

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30 * 1000,
      refetchOnWindowFocus: false,
    },
  },
});

function LoadingScreen() {
  return (
    <div className="flex h-screen items-center justify-center bg-[var(--bg-primary)]">
      <div className="text-center">
        <p className="font-display text-xl text-[var(--accent-blue)]">HEAD COACH 26</p>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading...</p>
      </div>
    </div>
  );
}

function PageLoader() {
  return (
    <div className="flex h-64 items-center justify-center">
      <div className="text-center">
        <div className="mx-auto h-6 w-6 animate-spin rounded-full border-2 border-[var(--border)] border-t-[var(--accent-blue)]" />
        <p className="mt-3 text-sm text-[var(--text-secondary)]">Loading...</p>
      </div>
    </div>
  );
}

function InstallGate({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<'checking' | 'installed' | 'needs_install'>('checking');

  useEffect(() => {
    fetch('/install/status', { credentials: 'include' })
      .then((r) => {
        if (!r.ok) throw new Error('not found');
        return r.json();
      })
      .then((data) => {
        setStatus(data?.installed ? 'installed' : 'needs_install');
      })
      .catch(() => {
        // /install endpoint gone = install dir removed = already installed
        setStatus('installed');
      });
  }, []);

  if (status === 'checking') {
    return <LoadingScreen />;
  }

  if (status === 'needs_install') {
    window.location.href = '/install/';
    return <LoadingScreen />;
  }

  return <>{children}</>;
}

function AuthGate({ children }: { children: React.ReactNode }) {
  const { isLoading: sessionLoading } = useSession();
  const { isAuthenticated, isLoading } = useAuthStore();

  if (isLoading || sessionLoading) {
    return <LoadingScreen />;
  }

  if (!isAuthenticated) {
    return <Login />;
  }

  return <>{children}</>;
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <InstallGate>
        <AuthGate>
          <ErrorBoundary>
          <Routes>
            <Route element={<AppShell />}>
              <Route path="/" element={<Dashboard />} />
              <Route path="/my-team" element={<MyTeam />} />
              <Route path="/schedule" element={<Schedule />} />
              <Route path="/standings" element={<Standings />} />
              <Route path="/league-hub" element={<LeagueHub />} />
              <Route path="/leaders" element={<Leaders />} />
              <Route path="/press-conference" element={<PressConference />} />
              <Route path="/trades" element={<TradePage />} />
              <Route path="/free-agency" element={<FreeAgency />} />
              <Route path="/draft" element={<DraftRoom />} />
              <Route path="/coaching-staff" element={<CoachingStaff />} />
              <Route path="/legacy" element={<Legacy />} />
              <Route path="/owner-office" element={<OwnerOffice />} />
              <Route path="/settings" element={<SettingsPage />} />
              <Route path="/game-plan/:id" element={<WeeklyPrep />} />
              <Route path="/box-score/:id" element={<BoxScore />} />
              <Route path="/player/:id" element={<PlayerProfile />} />
              <Route path="/glossary" element={<Glossary />} />
              <Route path="/franchise-setup" element={<FranchiseSetup />} />
              <Route path="/article/:id" element={<ArticlePage />} />
              {/* Phase 4: Multiplayer Pages */}
              <Route path="/messages" element={<Suspense fallback={<PageLoader />}><MessageBoard /></Suspense>} />
              <Route path="/commissioner" element={<Suspense fallback={<PageLoader />}><CommissionerPage /></Suspense>} />
              <Route path="/notifications" element={<Suspense fallback={<PageLoader />}><NotificationsPage /></Suspense>} />
              {/* Phase 5: AI Pack Pages */}
              <Route path="/ai-studio" element={<Suspense fallback={<PageLoader />}><AiStudio /></Suspense>} />
              <Route path="/roster-import" element={<Suspense fallback={<PageLoader />}><RosterImport /></Suspense>} />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
          </Routes>
          </ErrorBoundary>
        </AuthGate>
        </InstallGate>
        <Toaster position="bottom-right" />
      </BrowserRouter>
    </QueryClientProvider>
  );
}
