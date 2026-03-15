import { useState, useEffect, useRef, useCallback } from 'react';
import { NavLink, Link, useLocation } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  Sun, Moon, Bell,
  User, LogOut, ChevronDown, ChevronRight, Menu, X,
  Gamepad2, Award, Trophy, MessageSquare, Settings, Shield,
} from 'lucide-react';
import { PlayerSearch } from '@/components/PlayerSearch';
import { TeamBadge } from '@/components/TeamBadge';
import { teamApi } from '@/api/client';
import { useAuthStore } from '@/stores/authStore';
import { useUIStore } from '@/stores/uiStore';
import {
  useLogout, useSimulateWeek, useAdvanceWeek, useSchedule, useUnreadCount,
} from '@/hooks/useApi';
import { toast } from 'sonner';

/* ──────────────────────────────────────────────────────────────────────────
   Nav menu definitions
   ────────────────────────────────────────────────────────────────────────── */

interface NavItem {
  label: string;
  to: string;
  adminOnly?: boolean;
  /** If true, shows a flyout submenu with all teams on hover */
  teamsSubmenu?: boolean;
}

interface NavMenu {
  id: string;
  label: string;
  items: NavItem[];
}

const navMenus: NavMenu[] = [
  {
    id: 'team',
    label: 'Team',
    items: [
      { label: 'Roster & Depth Chart', to: '/my-team' },
      { label: 'Salary Cap', to: '/salary-cap' },
      { label: 'Contract Planner', to: '/contract-planner' },
      { label: 'Coaching Staff', to: '/coaching-staff' },
      { label: 'Press Conference', to: '/press-conference' },
    ],
  },
  {
    id: 'league',
    label: 'League',
    items: [
      { label: 'Standings', to: '/standings' },
      { label: 'Schedule', to: '/schedule' },
      { label: 'Playoffs', to: '/playoffs' },
      { label: 'Teams', to: '/teams', teamsSubmenu: true },
      { label: 'Leaders', to: '/leaders' },
      { label: 'Awards', to: '/awards' },
      { label: 'Records', to: '/records' },
      { label: 'League Hub', to: '/league-hub' },
      { label: 'History', to: '/league-history' },
    ],
  },
  {
    id: 'front-office',
    label: 'Front Office',
    items: [
      { label: 'Trades', to: '/trades' },
      { label: 'Free Agency', to: '/free-agency' },
      { label: 'Draft Room', to: '/draft' },
      { label: "Owner's Office", to: '/owner-office' },
    ],
  },
];

/* User profile dropdown items */
interface ProfileItem {
  label: string;
  to: string;
  icon: typeof Award;
  adminOnly?: boolean;
  separator?: boolean;
}

const profileItems: ProfileItem[] = [
  { label: 'My Profile', to: '/profile', icon: User },
  { label: 'Fantasy Football', to: '/fantasy', icon: Gamepad2, separator: true },
  { label: 'Legacy', to: '/legacy', icon: Award },
  { label: 'Achievements', to: '/achievements', icon: Trophy },
  { label: 'Messages', to: '/messages', icon: MessageSquare, separator: true },
  { label: 'Settings', to: '/settings', icon: Settings },
  { label: 'Commissioner', to: '/commissioner', icon: Shield, adminOnly: true },
];

/* ──────────────────────────────────────────────────────────────────────────
   Sim / Advance label logic
   ────────────────────────────────────────────────────────────────────────── */

function useNavLabels() {
  const league = useAuthStore((s) => s.league);
  const { data: schedule } = useSchedule(league?.id);

  const phase = league?.phase ?? 'preseason';
  const week = league?.current_week ?? 0;

  const weekGames = schedule?.[String(week)] ?? [];
  const hasUnsimmed = weekGames.some((g: { is_simulated: boolean }) => !g.is_simulated);
  const weekSimmed = weekGames.length > 0 && !hasUnsimmed;

  let simLabel = 'Sim Week';
  let simDisabled = false;
  let simTooltip = '';

  if (phase === 'preseason' || week === 0) {
    simLabel = 'Start Season First';
    simDisabled = true;
    simTooltip = 'Click "Start Season" to begin';
  } else if (weekSimmed) {
    simLabel = `Week ${week} Done`;
    simDisabled = true;
    simTooltip = `All games for Week ${week} are complete.`;
  } else if (hasUnsimmed) {
    simLabel = `Sim Week ${week}`;
    simDisabled = false;
    simTooltip = `Simulate all games for Week ${week}`;
  } else {
    simLabel = 'No Games';
    simDisabled = true;
    simTooltip = 'No games scheduled for this week';
  }

  let advLabel = 'Advance';
  let advTooltip = '';

  if (phase === 'preseason') {
    advLabel = 'Start Season';
    advTooltip = 'Begin the regular season';
  } else if (phase === 'regular') {
    advLabel = week >= 18 ? 'Start Playoffs' : `Week ${week + 1}`;
    advTooltip = week >= 18 ? 'Advance to the playoffs' : `Go to Week ${week + 1}`;
  } else if (phase === 'playoffs') {
    advLabel = week >= 22 ? 'Enter Offseason' : 'Next Round';
    advTooltip = week >= 22 ? 'Enter the offseason' : 'Advance to the next playoff round';
  } else if (phase === 'offseason') {
    advLabel = 'New Season';
    advTooltip = 'Start a new season';
  }

  return { simLabel, simDisabled, simTooltip, advLabel, advTooltip, weekSimmed };
}

/* ──────────────────────────────────────────────────────────────────────────
   TopNav — ESPN-style dark header with logo, nav dropdowns, and profile
   ────────────────────────────────────────────────────────────────────────── */

export function TopNav() {
  const location = useLocation();
  const { user, coach, team, league } = useAuthStore();
  const theme = useUIStore((s) => s.theme);
  const toggleTheme = useUIStore((s) => s.toggleTheme);
  const logout = useLogout();

  const sim = useSimulateWeek(league?.id ?? 0);
  const advance = useAdvanceWeek(league?.id ?? 0);
  const { simDisabled, weekSimmed } = useNavLabels();

  const { data: unreadData } = useUnreadCount();
  const unreadCount = (unreadData as { count: number } | undefined)?.count ?? 0;

  const [openMenu, setOpenMenu] = useState<string | null>(null);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [teamsHover, setTeamsHover] = useState(false);

  // Fetch all teams for the flyout submenu
  const { data: teamsData } = useQuery({
    queryKey: ['teams-nav', league?.id],
    queryFn: () => teamApi.list(league!.id),
    enabled: !!league?.id,
    staleTime: 60000,
  });

  // Build sorted teams list for flyout
  const allTeams: { id: number; city: string; name: string; abbreviation: string; primary_color: string; secondary_color: string }[] = [];
  if (teamsData) {
    const conferences = (teamsData as any)?.conferences as Record<string, Record<string, any[]>> | undefined;
    if (conferences) {
      Object.values(conferences).forEach((divisions) => {
        Object.values(divisions).forEach((teams) => {
          teams.forEach((t: any) => allTeams.push(t));
        });
      });
    }
  }
  allTeams.sort((a, b) => `${a.city} ${a.name}`.localeCompare(`${b.city} ${b.name}`));
  const navRef = useRef<HTMLElement>(null);

  // Close on route change
  useEffect(() => {
    setOpenMenu(null);
    setMobileOpen(false);
  }, [location.pathname]);

  // Close on outside click
  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (navRef.current && !navRef.current.contains(e.target as Node)) {
        setOpenMenu(null);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const toggleMenu = useCallback((id: string) => {
    setOpenMenu((prev) => (prev === id ? null : id));
  }, []);

  const [justSimmed, setJustSimmed] = useState(false);

  const handleSim = async () => {
    try {
      const result = await sim.mutateAsync();
      toast.success(`Week ${result.week} simulated — ${result.results.length} games completed`);
      setJustSimmed(true); // Force button to show Advance immediately
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Simulation failed');
    }
  };

  const handleAdvance = async () => {
    try {
      const result = await advance.mutateAsync();
      const msg = result.phase === 'playoffs'
        ? 'Advancing to the Playoffs!'
        : result.phase === 'offseason'
          ? 'Season complete — entering the Offseason'
          : result.phase === 'preseason'
            ? 'Welcome to the new season!'
            : `Advanced to Week ${result.week}`;
      toast.success(msg);
      setJustSimmed(false);
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Advance failed');
    }
  };

  const filterItems = (items: NavItem[]) =>
    items.filter((item) => !item.adminOnly || user?.is_admin);

  const filterProfileItems = (items: ProfileItem[]) =>
    items.filter((item) => !item.adminOnly || user?.is_admin);

  /* Dropdown link styling */
  const dropdownLinkClass = ({ isActive }: { isActive: boolean }) =>
    `block px-4 py-2.5 text-[13px] font-medium transition-colors ${
      isActive
        ? 'bg-[#2188FF]/10 text-[#2188FF]'
        : 'text-[#ccc] hover:bg-white/5 hover:text-white'
    }`;

  /* Mobile link styling */
  const mobileLinkClass = ({ isActive }: { isActive: boolean }) =>
    `block px-6 py-3 text-sm font-medium border-b border-white/5 transition-colors ${
      isActive
        ? 'bg-[#2188FF]/10 text-[#2188FF]'
        : 'text-[#999] hover:bg-white/5 hover:text-white'
    }`;

  const isProfileOpen = openMenu === 'profile';
  const profileActive = profileItems.some((item) => location.pathname === item.to);

  return (
    <nav
      ref={navRef}
      className="sticky top-0 z-50 bg-[#111111] border-b border-white/10"
    >
      <div className="flex h-12 items-center px-6">
        {/* ── Left: Logo + Team Badge + Nav Links ── */}
        <div className="flex items-center gap-0">
          {/* Logo */}
          <Link
            to="/"
            className="mr-4 flex items-center py-1"
          >
            <span className="nav-logo">HEAD COACH</span>
            <span className="nav-logo nav-logo-number" style={{ color: team?.primary_color ?? '#2188FF' }}>26</span>
          </Link>

          {/* Team Badge */}
          {team && (
            <Link
              to="/my-team"
              className="mr-3 rounded px-1 py-1 transition-colors hover:bg-white/5"
            >
              <TeamBadge
                abbreviation={team.abbreviation}
                primaryColor={team.primary_color}
                secondaryColor={team.secondary_color}
                size="sm"
              />
            </Link>
          )}

          {/* Desktop nav menus */}
          <div className="hidden items-center md:flex">
            {navMenus.map((menu) => {
              const visibleItems = filterItems(menu.items);
              if (visibleItems.length === 0) return null;

              const isOpen = openMenu === menu.id;
              const isActive = visibleItems.some(
                (item) => location.pathname === item.to
              );

              return (
                <div key={menu.id} className="relative">
                  <button
                    onClick={() => toggleMenu(menu.id)}
                    className={`flex items-center gap-1 px-3 py-3.5 text-[13px] font-semibold tracking-wide transition-colors ${
                      isActive
                        ? 'text-white border-b-2 border-[#2188FF]'
                        : isOpen
                          ? 'text-white bg-white/5'
                          : 'text-[#999] hover:text-white'
                    }`}
                  >
                    {menu.label}
                    <ChevronDown
                      className={`h-3 w-3 transition-transform ${isOpen ? 'rotate-180' : ''}`}
                    />
                  </button>

                  {isOpen && (
                    <div className="absolute left-0 top-full z-50 min-w-[200px] overflow-hidden rounded-b-lg border border-white/10 border-t-0 bg-[#1a1a1a] shadow-xl">
                      {visibleItems.map((item) => (
                        item.teamsSubmenu ? (
                          <div
                            key={item.to}
                            className="relative group/teams"
                            onMouseEnter={() => setTeamsHover(true)}
                            onMouseLeave={() => setTeamsHover(false)}
                          >
                            <NavLink
                              to={item.to}
                              className={({ isActive }) =>
                                `flex items-center justify-between px-4 py-2.5 text-[13px] font-medium transition-colors ${
                                  isActive
                                    ? 'bg-[#2188FF]/10 text-[#2188FF]'
                                    : 'text-[#ccc] hover:bg-white/5 hover:text-white'
                                }`
                              }
                            >
                              {item.label}
                              <ChevronRight className="h-3 w-3 opacity-50" />
                            </NavLink>

                            {/* Teams flyout submenu — overlaps parent by 4px so mouse doesn't leave */}
                            {teamsHover && allTeams.length > 0 && (
                              <div className="absolute left-[calc(100%-4px)] top-0 z-50 pl-1">
                                <div className="w-[260px] max-h-[70vh] overflow-y-auto rounded-lg border border-white/10 bg-[#1a1a1a] shadow-xl">
                                  {allTeams.map((t) => (
                                    <Link
                                      key={t.id}
                                      to={`/team/${t.id}`}
                                      className="flex items-center gap-2.5 px-4 py-2 text-[13px] text-[#ccc] hover:bg-white/5 hover:text-white transition-colors"
                                      onClick={() => { setOpenMenu(null); setTeamsHover(false); }}
                                    >
                                      <TeamBadge
                                        abbreviation={t.abbreviation}
                                        primaryColor={t.primary_color}
                                        secondaryColor={t.secondary_color}
                                        size="xs"
                                      />
                                      <span>{t.city} {t.name}</span>
                                    </Link>
                                  ))}
                                </div>
                              </div>
                            )}
                          </div>
                        ) : (
                          <NavLink
                            key={item.to}
                            to={item.to}
                            className={dropdownLinkClass}
                          >
                            {item.label}
                          </NavLink>
                        )
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>

        {/* ── Right: Actions + Utility Icons + Profile ── */}
        <div className="ml-auto flex items-center gap-1">
          {/* Cmd+K search */}
          <PlayerSearch />

          {/* Single action button: Sim → Advance */}
          {league && (() => {
            const phase = league.phase;
            const week = league.current_week ?? 0;
            const btnClass = "hidden h-8 items-center gap-1.5 rounded px-4 text-[12px] font-bold uppercase tracking-wide bg-[#2188FF] text-white hover:bg-[#2188FF]/90 disabled:opacity-50 sm:inline-flex";

            if (phase === 'preseason') {
              return (
                <button onClick={handleAdvance} disabled={advance.isPending} className={btnClass}>
                  {advance.isPending ? 'Starting...' : 'Start Season'}
                </button>
              );
            }
            if (phase === 'offseason') {
              return (
                <button onClick={handleAdvance} disabled={advance.isPending} className={btnClass}>
                  {advance.isPending ? 'Starting...' : 'New Season'}
                </button>
              );
            }
            // Regular season or playoffs
            if (weekSimmed || justSimmed) {
              return (
                <button onClick={handleAdvance} disabled={advance.isPending} className={btnClass}>
                  {advance.isPending ? 'Advancing...' : `Advance to Week ${week + 1}`}
                </button>
              );
            }
            if (!simDisabled) {
              return (
                <button onClick={handleSim} disabled={sim.isPending} className={btnClass}>
                  {sim.isPending ? 'Simming...' : `Sim Week ${week}`}
                </button>
              );
            }
            return null;
          })()}

          {/* ── Profile avatar dropdown ── */}
          <div className="relative hidden md:block ml-2">
            <button
              onClick={() => toggleMenu('profile')}
              className={`flex items-center gap-2 rounded px-2 py-1.5 transition-colors ${
                isProfileOpen || profileActive
                  ? 'bg-white/10'
                  : 'hover:bg-white/5'
              }`}
            >
              <div className="relative">
                {user?.avatar_url ? (
                  <img src={user.avatar_url} alt="" className="h-7 w-7 rounded-full object-cover" />
                ) : (
                  <div className="flex h-7 w-7 items-center justify-center rounded-full bg-white/10">
                    <User className="h-3.5 w-3.5 text-white/60" />
                  </div>
                )}
                {unreadCount > 0 && (
                  <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-[#E3342F] border border-[#111111]" />
                )}
              </div>
              <span className="max-w-[90px] truncate text-[11px] font-medium text-white/60">
                {coach?.name ?? user?.username}
              </span>
              <ChevronDown
                className={`h-3 w-3 text-white/40 transition-transform ${isProfileOpen ? 'rotate-180' : ''}`}
              />
            </button>

            {isProfileOpen && (
              <div className="absolute right-0 top-full z-50 min-w-[220px] overflow-hidden rounded-b-lg border border-white/10 border-t-0 bg-[#1a1a1a] shadow-xl">
                {/* Coach info header — links to profile */}
                <Link
                  to="/profile"
                  className="flex items-center gap-3 border-b border-white/10 px-4 py-3 hover:bg-white/5 transition-colors"
                >
                  <div
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                    style={{ backgroundColor: team?.primary_color ?? '#3B82F6' }}
                  >
                    {(user?.display_name ?? coach?.name ?? user?.username ?? 'U')
                      .split(' ')
                      .map((w: string) => w[0])
                      .join('')
                      .toUpperCase()
                      .slice(0, 2)}
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-white truncate">
                      {user?.display_name ?? coach?.name ?? user?.username}
                    </p>
                    {team && (
                      <p className="text-[11px] text-white/50">
                        Head Coach, {team.city} {team.name}
                      </p>
                    )}
                    {league && (
                      <p className="text-[10px] font-semibold text-[#2188FF] mt-0.5">
                        {league.phase === 'regular' ? `Week ${league.current_week}` : league.phase.toUpperCase()}
                      </p>
                    )}
                  </div>
                </Link>

                {/* Profile links */}
                {filterProfileItems(profileItems).map((item, idx) => (
                  <div key={item.to}>
                    {item.separator && idx > 0 && (
                      <div className="border-t border-white/10" />
                    )}
                    <NavLink
                      to={item.to}
                      className={({ isActive }) =>
                        `flex items-center gap-3 px-4 py-2.5 text-[13px] font-medium transition-colors ${
                          isActive
                            ? 'bg-[#2188FF]/10 text-[#2188FF]'
                            : 'text-[#ccc] hover:bg-white/5 hover:text-white'
                        }`
                      }
                    >
                      <item.icon className="h-4 w-4 opacity-60" />
                      {item.label}
                    </NavLink>
                  </div>
                ))}

                {/* Notifications */}
                <div className="border-t border-white/10">
                  <NavLink
                    to="/notifications"
                    className={({ isActive }) =>
                      `flex items-center justify-between px-4 py-2.5 text-[13px] font-medium transition-colors ${
                        isActive ? 'bg-[#2188FF]/10 text-[#2188FF]' : 'text-[#ccc] hover:bg-white/5 hover:text-white'
                      }`
                    }
                  >
                    <div className="flex items-center gap-3">
                      <Bell className="h-4 w-4 opacity-60" />
                      Notifications
                    </div>
                    {unreadCount > 0 && (
                      <span className="flex h-5 w-5 items-center justify-center rounded-full bg-[#E3342F] text-[10px] font-bold text-white">
                        {unreadCount > 9 ? '9+' : unreadCount}
                      </span>
                    )}
                  </NavLink>
                </div>

                {/* Theme toggle */}
                <button
                  onClick={toggleTheme}
                  className="flex w-full items-center gap-3 px-4 py-2.5 text-[13px] font-medium text-[#ccc] hover:bg-white/5 hover:text-white transition-colors"
                >
                  {theme === 'dark' ? <Sun className="h-4 w-4 opacity-60" /> : <Moon className="h-4 w-4 opacity-60" />}
                  {theme === 'dark' ? 'Light Mode' : 'Dark Mode'}
                </button>

                {/* Logout */}
                <div className="border-t border-white/10">
                  <button
                    onClick={() => logout.mutate()}
                    className="flex w-full items-center gap-3 px-4 py-2.5 text-[13px] font-medium text-[#E3342F] transition-colors hover:bg-[#E3342F]/10"
                  >
                    <LogOut className="h-4 w-4 opacity-60" />
                    Log Out
                  </button>
                </div>
              </div>
            )}
          </div>

          {/* Mobile hamburger */}
          <button
            onClick={() => setMobileOpen((v) => !v)}
            className="flex h-8 w-8 items-center justify-center rounded text-white/60 transition-colors hover:bg-white/5 md:hidden"
          >
            {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
        </div>
      </div>

      {/* ── Mobile drawer ── */}
      {mobileOpen && (
        <div className="fixed inset-0 top-12 z-40 md:hidden">
          <div className="absolute inset-0 bg-black/60" onClick={() => setMobileOpen(false)} />
          <div className="absolute left-0 top-0 h-full w-72 overflow-y-auto bg-[#1a1a1a] shadow-xl">
            {/* User info */}
            <div className="flex items-center gap-3 border-b border-white/10 px-5 py-4">
              <div className="flex h-9 w-9 items-center justify-center rounded-full bg-white/10">
                <User className="h-4 w-4 text-white/60" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-white">
                  {coach?.name ?? user?.username}
                </p>
                {league && (
                  <p className="text-[11px] font-semibold text-[#2188FF]">
                    {league.phase === 'regular' ? `Week ${league.current_week}` : league.phase.toUpperCase()}
                  </p>
                )}
              </div>
            </div>

            {/* Dashboard link */}
            <NavLink to="/" className={mobileLinkClass}>
              Dashboard
            </NavLink>

            {/* Mobile action button */}
            {league && (
              <div className="border-b border-white/10 px-5 py-3">
                {(() => {
                  const phase = league.phase;
                  const week = league.current_week ?? 0;
                  const cls = "w-full h-10 rounded bg-[#2188FF] text-sm font-bold uppercase tracking-wide text-white disabled:opacity-50";
                  if (phase === 'preseason') {
                    return <button onClick={handleAdvance} disabled={advance.isPending} className={cls}>{advance.isPending ? 'Starting...' : 'Start Season'}</button>;
                  }
                  if (phase === 'offseason') {
                    return <button onClick={handleAdvance} disabled={advance.isPending} className={cls}>{advance.isPending ? 'Starting...' : 'New Season'}</button>;
                  }
                  if (weekSimmed || justSimmed) {
                    return <button onClick={handleAdvance} disabled={advance.isPending} className={cls}>{advance.isPending ? 'Advancing...' : `Advance to Week ${week + 1}`}</button>;
                  }
                  if (!simDisabled) {
                    return <button onClick={handleSim} disabled={sim.isPending} className={cls}>{sim.isPending ? 'Simming...' : `Sim Week ${week}`}</button>;
                  }
                  return null;
                })()}
              </div>
            )}

            {/* Nav sections */}
            {navMenus.map((menu) => {
              const visibleItems = filterItems(menu.items);
              if (visibleItems.length === 0) return null;
              return (
                <div key={menu.id}>
                  <div className="px-5 pb-1 pt-4">
                    <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-white/30">
                      {menu.label}
                    </span>
                  </div>
                  {visibleItems.map((item) => (
                    <NavLink key={item.to} to={item.to} className={mobileLinkClass}>
                      {item.label}
                    </NavLink>
                  ))}
                </div>
              );
            })}

            {/* Personal / Profile section on mobile */}
            <div>
              <div className="px-5 pb-1 pt-4">
                <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-white/30">
                  Personal
                </span>
              </div>
              {filterProfileItems(profileItems).map((item) => (
                <NavLink key={item.to} to={item.to} className={mobileLinkClass}>
                  {item.label}
                </NavLink>
              ))}
            </div>

            {/* Logout */}
            <div className="border-t border-white/10 px-5 py-4">
              <button
                onClick={() => logout.mutate()}
                className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#E3342F] transition-colors hover:bg-[#E3342F]/10"
              >
                <LogOut className="h-4 w-4" />
                Log Out
              </button>
            </div>
          </div>
        </div>
      )}
    </nav>
  );
}
