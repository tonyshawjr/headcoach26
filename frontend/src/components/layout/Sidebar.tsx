import { NavLink, Link, useLocation } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useUIStore } from '@/stores/uiStore';
import { useLogout, useUnreadCount } from '@/hooks/useApi';
import { ChevronUp, X, Menu } from 'lucide-react';
import { useState, useEffect } from 'react';

/* ── Nav items ────────────────────────────────────── */

interface NavEntry {
  label: string;
  to?: string;
  children?: { label: string; to: string; adminOnly?: boolean }[];
}

const navItems: NavEntry[] = [
  { label: 'Dashboard', to: '/' },
  {
    label: 'Team',
    children: [
      { label: 'My Team', to: '/my-team' },
      { label: 'Coaching Staff', to: '/coaching-staff' },
      { label: 'Salary Cap', to: '/salary-cap' },
      { label: 'Contract Planner', to: '/contract-planner' },
      { label: 'Press Conference', to: '/press-conference' },
    ],
  },
  {
    label: 'League',
    children: [
      { label: 'Standings', to: '/standings' },
      { label: 'Schedule', to: '/schedule' },
      { label: 'Playoffs', to: '/playoffs' },
      { label: 'Teams', to: '/teams' },
      { label: 'Leaders', to: '/leaders' },
      { label: 'League Hub', to: '/league-hub' },
    ],
  },
  {
    label: 'Front Office',
    children: [
      { label: 'Offseason Hub', to: '/offseason' },
      { label: 'Trades', to: '/trades' },
      { label: 'Free Agency', to: '/free-agency' },
      { label: 'Draft Room', to: '/draft' },
      { label: "Owner's Office", to: '/owner-office' },
    ],
  },
  {
    label: 'History',
    children: [
      { label: 'Legacy', to: '/legacy' },
      { label: 'Records', to: '/records' },
      { label: 'Achievements', to: '/achievements' },
      { label: 'League History', to: '/league-history' },
    ],
  },
  { label: 'Fantasy', to: '/fantasy' },
  { label: 'Messages', to: '/messages' },
  { label: 'Settings', to: '/settings' },
];

const bottomItems: NavEntry[] = [];

/* ── Sidebar ──────────────────────────────────────── */

export function Sidebar() {
  const location = useLocation();
  const { user } = useAuthStore();
  const theme = useUIStore((s) => s.theme);
  const toggleTheme = useUIStore((s) => s.toggleTheme);
  const sidebarOpen = useUIStore((s) => s.sidebarOpen);
  const setSidebarOpen = useUIStore((s) => s.setSidebarOpen);
  const logout = useLogout();

  const { data: unreadData } = useUnreadCount();
  const unreadCount = (unreadData as { count: number } | undefined)?.count ?? 0;

  // Accordion state — which expandable section is open
  const findActiveAccordion = (): string | null => {
    for (const item of navItems) {
      if (item.children?.some((c) => location.pathname === c.to)) {
        return item.label;
      }
    }
    return null;
  };

  const [openAccordion, setOpenAccordion] = useState<string | null>(findActiveAccordion);

  useEffect(() => {
    const active = findActiveAccordion();
    if (active) setOpenAccordion(active);
  }, [location.pathname]);

  const toggle = (label: string) => {
    setOpenAccordion((prev) => (prev === label ? null : label));
  };

  // unused but kept for structure
  const _bottomItems = bottomItems;

  return (
    <>
      {/* Mobile overlay */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/70 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      <aside
        className={`fixed top-0 left-0 z-50 h-full w-[240px] flex flex-col transition-transform lg:translate-x-0 ${
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
        style={{ backgroundColor: '#0d0d0d', overflow: 'hidden' }}
      >
        {/* ── Brand ── */}
        <div className="flex items-center justify-between px-6 pt-6 pb-4">
          <Link
            to="/"
            className="text-[20px] font-bold tracking-wide"
            style={{ color: '#F5A623', fontFamily: "'Barlow Condensed', sans-serif" }}
            onClick={() => setSidebarOpen(false)}
          >
            Head Coach
          </Link>
          <button
            onClick={() => setSidebarOpen(false)}
            className="flex h-7 w-7 items-center justify-center rounded text-white/40 hover:text-white lg:hidden"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        {/* ── Main nav ── */}
        <nav className="flex-1 px-6">
          {navItems.map((item) => {
            // Simple link
            if (item.to) {
              const isActive = item.to === '/'
                ? location.pathname === '/'
                : location.pathname === item.to;

              return (
                <NavLink
                  key={item.label}
                  to={item.to}
                  end={item.to === '/'}
                  className="sidebar-item"
                  onClick={() => setSidebarOpen(false)}
                >
                  <span className={isActive ? 'text-white' : ''}>
                    {item.label}
                    {item.to === '/messages' && unreadCount > 0 && (
                      <span className="ml-2 inline-flex h-4 min-w-[16px] items-center justify-center rounded-full bg-[#E3342F] px-1 text-[9px] font-bold text-white align-middle">
                        {unreadCount > 9 ? '9+' : unreadCount}
                      </span>
                    )}
                  </span>
                </NavLink>
              );
            }

            // Expandable accordion
            const isOpen = openAccordion === item.label;
            const hasActiveChild = item.children?.some((c) => location.pathname === c.to);
            const children = item.children?.filter((c) => !c.adminOnly || user?.is_admin) ?? [];

            return (
              <div key={item.label}>
                <button
                  onClick={() => toggle(item.label)}
                  className="sidebar-item w-full text-left"
                >
                  <span className={hasActiveChild ? 'text-white' : ''}>
                    {item.label}
                    <ChevronUp
                      className={`inline ml-1.5 h-3.5 w-3.5 text-white/30 transition-transform duration-200 ${
                        isOpen ? '' : 'rotate-180'
                      }`}
                    />
                  </span>
                </button>

                {/* Children — gold text */}
                {isOpen && (
                  <div className="pb-1">
                    {children.map((child) => {
                      const childActive = location.pathname === child.to;
                      return (
                        <NavLink
                          key={child.to}
                          to={child.to}
                          className="sidebar-child"
                          onClick={() => setSidebarOpen(false)}
                        >
                          <span className={childActive ? 'opacity-100' : 'opacity-80 hover:opacity-100'}>
                            {child.label}
                          </span>
                        </NavLink>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })}

        </nav>

        {/* ── Theme toggle at bottom ── */}
        <div className="px-6 pb-6">
          <div className="sidebar-divider" />
          <button
            onClick={toggleTheme}
            className="flex items-center gap-3 py-3 w-full group"
          >
            {/* Toggle switch */}
            <div
              className={`relative h-[22px] w-[40px] rounded-full transition-colors ${
                theme === 'dark' ? 'bg-white/20' : 'bg-[#F5A623]'
              }`}
            >
              <div
                className={`absolute top-[3px] h-[16px] w-[16px] rounded-full bg-white shadow transition-transform ${
                  theme === 'dark' ? 'left-[3px]' : 'left-[21px]'
                }`}
              />
            </div>
            <span className="text-[15px] text-white/80 group-hover:text-white transition-colors" style={{ fontFamily: "'Barlow Condensed', sans-serif" }}>
              {theme === 'dark' ? 'Light Mode' : 'Dark Mode'}
            </span>
          </button>
        </div>
      </aside>
    </>
  );
}
