import { NavLink } from 'react-router-dom';
import {
  LayoutDashboard, Users, CalendarDays, Trophy, Newspaper,
  Mic2, Settings, ChevronLeft, ChevronRight, BarChart3,
  Building2, ArrowLeftRight, UserPlus, GraduationCap, ClipboardList,
  Award, MessageSquare, Bell, Shield, Sparkles, FileSpreadsheet, Zap,
} from 'lucide-react';
import { useUIStore } from '@/stores/uiStore';
import { useAuthStore } from '@/stores/authStore';
import { useUnreadCount } from '@/hooks/useApi';
import { TeamBadge } from '@/components/TeamBadge';

const navSections = [
  {
    label: 'GAME',
    items: [
      { to: '/', icon: LayoutDashboard, label: 'Dashboard' },
      { to: '/my-team', icon: Users, label: 'My Team' },
      { to: '/schedule', icon: CalendarDays, label: 'Schedule' },
    ],
  },
  {
    label: 'LEAGUE',
    items: [
      { to: '/standings', icon: Trophy, label: 'Standings' },
      { to: '/league-hub', icon: Newspaper, label: 'League Hub' },
      { to: '/leaders', icon: BarChart3, label: 'Leaders' },
    ],
  },
  {
    label: 'MANAGEMENT',
    items: [
      { to: '/press-conference', icon: Mic2, label: 'Press Room' },
      { to: '/trades', icon: ArrowLeftRight, label: 'Trades' },
      { to: '/free-agency', icon: UserPlus, label: 'Free Agency' },
      { to: '/draft', icon: GraduationCap, label: 'Draft Room' },
      { to: '/coaching-staff', icon: ClipboardList, label: 'Staff' },
      { to: '/legacy', icon: Award, label: 'Legacy' },
      { to: '/owner-office', icon: Building2, label: 'Owner Office' },
    ],
  },
];

export function Sidebar() {
  const open = useUIStore((s) => s.sidebarOpen);
  const toggle = useUIStore((s) => s.toggleSidebar);
  const team = useAuthStore((s) => s.team);
  const user = useAuthStore((s) => s.user);
  const { data: unreadData } = useUnreadCount();
  const unreadCount = (unreadData as { count: number } | undefined)?.count ?? 0;

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    `group flex items-center gap-3 rounded px-3 py-2 text-[13px] font-medium transition-all duration-150 ${
      isActive
        ? 'bg-[var(--accent-blue)]/10 text-[var(--accent-blue)] border-l-2 border-[var(--accent-blue)] -ml-[2px]'
        : 'text-[var(--text-secondary)] hover:bg-white/[0.04] hover:text-[var(--text-primary)]'
    }`;

  return (
    <aside
      className={`fixed left-0 top-0 z-40 flex h-screen flex-col border-r border-[var(--border)] bg-[var(--sidebar)] transition-all duration-300 ${
        open ? 'w-60' : 'w-16'
      }`}
    >
      {/* Brand */}
      <div className="flex h-14 items-center gap-3 border-b border-[var(--border)] px-4">
        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-[var(--accent-blue)]">
          <Zap className="h-4 w-4 text-white" />
        </div>
        {open && (
          <div className="flex flex-col leading-none">
            <span className="font-display text-sm tracking-wide">HEAD COACH</span>
            <span className="text-[10px] font-semibold tracking-widest text-[var(--accent-blue)]">26</span>
          </div>
        )}
      </div>

      {/* Team badge */}
      {team && open && (
        <div
          className="mx-3 mt-3 rounded p-2.5"
          style={{
            background: `linear-gradient(135deg, ${team.primary_color}15, ${team.primary_color}08)`,
            borderLeft: `3px solid ${team.primary_color}`,
          }}
        >
          <div className="flex items-center gap-2.5">
            <TeamBadge
              abbreviation={team.abbreviation}
              primaryColor={team.primary_color}
              secondaryColor={team.secondary_color}
              size="md"
            />
            <div className="min-w-0">
              <p className="truncate text-xs font-semibold leading-tight">{team.city} {team.name}</p>
              <p className="text-[11px] text-[var(--text-secondary)]">
                {team.wins}-{team.losses}
                {team.streak && (
                  <span className="ml-1.5 text-[var(--text-muted)]">{team.streak}</span>
                )}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Collapsed team badge */}
      {team && !open && (
        <div className="mx-auto mt-3">
          <TeamBadge
            abbreviation={team.abbreviation}
            primaryColor={team.primary_color}
            secondaryColor={team.secondary_color}
            size="md"
          />
        </div>
      )}

      {/* Nav sections */}
      <nav className="mt-2 flex-1 overflow-y-auto px-2 pb-2">
        {navSections.map((section) => (
          <div key={section.label} className="mt-4 first:mt-2">
            {open && (
              <div className="mb-1.5 flex items-center gap-2 px-3">
                <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">
                  {section.label}
                </span>
                <div className="h-px flex-1 bg-[var(--border)]" />
              </div>
            )}
            {!open && (
              <div className="mx-2 mb-1.5 h-px bg-[var(--border)]" />
            )}
            <div className="space-y-0.5">
              {section.items.map(({ to, icon: Icon, label }) => (
                <NavLink key={to} to={to} className={linkClass}>
                  <Icon className="h-4 w-4 shrink-0" />
                  {open && <span>{label}</span>}
                </NavLink>
              ))}
            </div>
          </div>
        ))}

        {/* Additional items outside sections */}
        <div className="mt-4">
          {open && (
            <div className="mb-1.5 flex items-center gap-2 px-3">
              <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">
                COMMS
              </span>
              <div className="h-px flex-1 bg-[var(--border)]" />
            </div>
          )}
          {!open && (
            <div className="mx-2 mb-1.5 h-px bg-[var(--border)]" />
          )}

          <div className="space-y-0.5">
            <NavLink to="/messages" className={linkClass}>
              <MessageSquare className="h-4 w-4 shrink-0" />
              {open && <span>Messages</span>}
            </NavLink>

            {/* Notifications - with unread badge */}
            <NavLink to="/notifications" className={linkClass}>
              <div className="relative shrink-0">
                <Bell className="h-4 w-4" />
                {unreadCount > 0 && (
                  <span className="absolute -right-1 -top-1 flex h-3 w-3 items-center justify-center rounded-full bg-[var(--accent-red)] text-[7px] font-bold text-white ring-2 ring-[var(--sidebar)]">
                    {unreadCount > 9 ? '9+' : unreadCount}
                  </span>
                )}
              </div>
              {open && <span>Notifications</span>}
            </NavLink>
          </div>
        </div>

        {/* Admin section */}
        {(user?.is_admin) && (
          <div className="mt-4">
            {open && (
              <div className="mb-1.5 flex items-center gap-2 px-3">
                <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-red)]">
                  ADMIN
                </span>
                <div className="h-px flex-1 bg-[var(--accent-red)]/20" />
              </div>
            )}
            {!open && (
              <div className="mx-2 mb-1.5 h-px bg-[var(--accent-red)]/20" />
            )}
            <div className="space-y-0.5">
              <NavLink to="/ai-studio" className={linkClass}>
                <Sparkles className="h-4 w-4 shrink-0" />
                {open && <span>AI Studio</span>}
              </NavLink>
              <NavLink to="/commissioner" className={linkClass}>
                <Shield className="h-4 w-4 shrink-0" />
                {open && <span>Commissioner</span>}
              </NavLink>
              <NavLink to="/roster-import" className={linkClass}>
                <FileSpreadsheet className="h-4 w-4 shrink-0" />
                {open && <span>Import</span>}
              </NavLink>
              <NavLink to="/settings" className={linkClass}>
                <Settings className="h-4 w-4 shrink-0" />
                {open && <span>Settings</span>}
              </NavLink>
            </div>
          </div>
        )}

        {/* Settings for non-admin */}
        {!user?.is_admin && (
          <div className="mt-4 space-y-0.5">
            <NavLink to="/ai-studio" className={linkClass}>
              <Sparkles className="h-4 w-4 shrink-0" />
              {open && <span>AI Studio</span>}
            </NavLink>
            <NavLink to="/settings" className={linkClass}>
              <Settings className="h-4 w-4 shrink-0" />
              {open && <span>Settings</span>}
            </NavLink>
          </div>
        )}
      </nav>

      {/* Collapse button */}
      <button
        onClick={toggle}
        className="flex h-10 items-center justify-center border-t border-[var(--border)] text-[var(--text-muted)] transition-colors hover:bg-white/[0.04] hover:text-[var(--text-primary)]"
      >
        {open ? <ChevronLeft className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
      </button>
    </aside>
  );
}
