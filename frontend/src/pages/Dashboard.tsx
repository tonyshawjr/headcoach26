import { Link } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import {
  useSchedule, useArticles, useRoster, useSimulateWeek,
  useAdvanceWeek, useStandings, useCapSpace, useActivity,
} from '@/hooks/useApi';
import { GameCard } from '@/components/cards/GameCard';
import { ArticleCard } from '@/components/cards/ArticleCard';
import { TeamBadge } from '@/components/TeamBadge';
import Onboarding from '@/components/Onboarding';
import {
  ClipboardList, Play, FastForward, GraduationCap,
  UserPlus, CalendarDays, ChevronRight, TrendingUp,
  DollarSign, Heart, Shield, Briefcase, AlertTriangle,
} from 'lucide-react';
import { motion } from 'framer-motion';
import { toast } from 'sonner';
import type { Game, StandingsTeam } from '@/api/client';
import { useCoachingStaff, useDepthChart } from '@/hooks/useApi';

/* ═══════════════════════════════════════════════════
   Coach's Agenda — the "what should I do next" prompt
   Full width, top of page, most important element
   ═══════════════════════════════════════════════════ */

function CoachAgenda() {
  const { team, league } = useAuthStore();
  const { data: schedule } = useSchedule(league?.id);
  const sim = useSimulateWeek(league?.id ?? 0);
  const advance = useAdvanceWeek(league?.id ?? 0);

  const phase = league?.phase ?? 'preseason';
  const week = league?.current_week ?? 0;

  let myNextGame: Game | null = null;
  let weekSimmed = false;
  if (schedule && team && week > 0) {
    const weekGames = schedule[String(week)] ?? [];
    const myWeekGame = weekGames.find((g) => g.home_team_id === team.id || g.away_team_id === team.id);
    if (myWeekGame && !myWeekGame.is_simulated) {
      myNextGame = myWeekGame;
    }
    weekSimmed = weekGames.length > 0 && weekGames.every((g) => g.is_simulated);
  }

  let opponentName = '';
  if (myNextGame && team) {
    const opp = myNextGame.home_team_id === team.id ? myNextGame.away_team : myNextGame.home_team;
    opponentName = `${opp?.city ?? ''} ${opp?.name ?? ''}`.trim();
  }

  let lastResult = '';
  if (schedule && team) {
    const allGames = Object.values(schedule).flat();
    const mySimmed = allGames
      .filter((g) => g.is_simulated && (g.home_team_id === team.id || g.away_team_id === team.id))
      .sort((a, b) => b.week - a.week);
    if (mySimmed.length > 0) {
      const g = mySimmed[0];
      const isHome = g.home_team_id === team.id;
      const myScore = isHome ? g.home_score : g.away_score;
      const theirScore = isHome ? g.away_score : g.home_score;
      const won = (myScore ?? 0) > (theirScore ?? 0);
      lastResult = won ? `Won ${myScore}-${theirScore}` : `Lost ${theirScore}-${myScore}`;
    }
  }

  const handleSim = async () => {
    try {
      const result = await sim.mutateAsync();
      toast.success(`Week ${result.week} complete — ${result.results.length} games played`);
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Simulation failed');
    }
  };

  const handleAdvance = async () => {
    try {
      const result = await advance.mutateAsync();
      toast.success(`Advanced to ${result.phase} — Week ${result.week}`);
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Advance failed');
    }
  };

  let message = '';
  let action: React.ReactNode = null;

  if (phase === 'preseason') {
    message = "Welcome to the preseason, Coach. Build your roster and set your staff, then start the season when you're ready.";
    action = (
      <div className="flex flex-wrap gap-3 mt-4">
        <button onClick={handleAdvance} disabled={advance.isPending} className="btn-primary">
          <FastForward className="h-4 w-4" />
          {advance.isPending ? 'Starting...' : 'Start Season'}
        </button>
        <Link to="/my-team" className="btn-secondary">
          <ClipboardList className="h-4 w-4" />
          View Roster
        </Link>
      </div>
    );
  } else if (phase === 'regular' || phase === 'playoffs') {
    if (weekSimmed) {
      message = `Week ${week} is in the books.${lastResult ? ` ${lastResult}.` : ''} Ready to move on?`;
      const nextLabel = phase === 'regular' && week >= 18
        ? 'Start Playoffs'
        : phase === 'playoffs' && week >= 22
          ? 'Enter Offseason'
          : `Go to Week ${week + 1}`;
      action = (
        <div className="flex flex-wrap gap-3 mt-4">
          <button onClick={handleAdvance} disabled={advance.isPending} className="btn-primary">
            <FastForward className="h-4 w-4" />
            {advance.isPending ? 'Advancing...' : nextLabel}
          </button>
          <Link to="/schedule" className="btn-secondary">
            <CalendarDays className="h-4 w-4" />
            View Results
          </Link>
        </div>
      );
    } else if (myNextGame) {
      message = `Week ${week} is here. Your ${team?.name} play the ${opponentName} this week.`;
      action = (
        <div className="flex flex-wrap gap-3 mt-4">
          <Link to={`/game-plan/${myNextGame.id}`} className="btn-primary">
            <ClipboardList className="h-4 w-4" />
            Set Game Plan
          </Link>
          <button onClick={handleSim} disabled={sim.isPending} className="btn-secondary">
            <Play className="h-4 w-4" />
            {sim.isPending ? 'Playing...' : `Play Week ${week}`}
          </button>
        </div>
      );
    } else {
      message = `Week ${week} — no game for your team this week.`;
      action = (
        <div className="flex flex-wrap gap-3 mt-4">
          <button onClick={handleSim} disabled={sim.isPending} className="btn-secondary">
            <Play className="h-4 w-4" />
            {sim.isPending ? 'Playing...' : `Play Week ${week}`}
          </button>
        </div>
      );
    }
  } else if (phase === 'offseason') {
    message = "The season is over. Hit the Draft Room and Free Agency to retool for next year.";
    action = (
      <div className="flex flex-wrap gap-3 mt-4">
        <Link to="/draft" className="btn-primary">
          <GraduationCap className="h-4 w-4" />
          Draft Room
        </Link>
        <Link to="/free-agency" className="btn-secondary">
          <UserPlus className="h-4 w-4" />
          Free Agency
        </Link>
        <button onClick={handleAdvance} disabled={advance.isPending} className="btn-secondary">
          <FastForward className="h-4 w-4" />
          {advance.isPending ? 'Starting...' : 'Begin New Season'}
        </button>
      </div>
    );
  }

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 sm:p-6">
      <p className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-2">
        Coach&apos;s Agenda
      </p>
      <p className="text-[var(--text-primary)] leading-relaxed">{message}</p>
      {action}
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   Next Game — full width matchup card
   ═══════════════════════════════════════════════════ */

function NextGameCard({ game, myTeamId, phase }: { game: Game | null; myTeamId?: number; phase: string }) {
  if (!game) {
    const messages: Record<string, string> = {
      preseason: 'Your schedule begins once the season starts.',
      offseason: 'The season is over. Focus on the Draft and Free Agency.',
      regular: 'No upcoming game on the schedule.',
      playoffs: 'No upcoming playoff game.',
    };
    return (
      <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
        <CalendarDays className="mx-auto mb-3 h-6 w-6 text-[var(--text-muted)]" />
        <p className="text-[var(--text-secondary)]">
          {messages[phase] ?? 'No game scheduled.'}
        </p>
      </div>
    );
  }

  const home = game.home_team;
  const away = game.away_team;
  const isHome = game.home_team_id === myTeamId;

  return (
    <Link to={`/game-plan/${game.id}`} className="block group">
      <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden transition-colors hover:bg-[var(--bg-elevated)]">
        <div className="p-6 sm:p-8">
          <p className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-6 text-center">
            {game.game_type === 'playoff' ? 'Playoff Game' : `Week ${game.week}`}
            {game.weather && game.weather !== 'clear' && game.weather !== 'dome' ? ` — ${game.weather}` : ''}
          </p>

          <div className="flex items-center justify-center gap-6 sm:gap-12">
            <div className="flex flex-col items-center gap-3 min-w-0">
              <TeamBadge abbreviation={away?.abbreviation} primaryColor={away?.primary_color} secondaryColor={away?.secondary_color} size="lg" />
              <div className="text-center">
                <p className="font-semibold text-[var(--text-primary)]">{away?.city} {away?.name}</p>
                <p className="text-sm text-[var(--text-secondary)]">{away?.wins ?? 0}-{away?.losses ?? 0}</p>
              </div>
            </div>

            <span className="text-xl font-bold text-[var(--text-muted)]">{isHome ? 'VS' : '@'}</span>

            <div className="flex flex-col items-center gap-3 min-w-0">
              <TeamBadge abbreviation={home?.abbreviation} primaryColor={home?.primary_color} secondaryColor={home?.secondary_color} size="lg" />
              <div className="text-center">
                <p className="font-semibold text-[var(--text-primary)]">{home?.city} {home?.name}</p>
                <p className="text-sm text-[var(--text-secondary)]">{home?.wins ?? 0}-{home?.losses ?? 0}</p>
              </div>
            </div>
          </div>

          <div className="mt-6 flex items-center justify-center gap-1.5 text-[var(--accent-blue)] group-hover:opacity-80 transition-opacity">
            <ClipboardList className="h-4 w-4" />
            <span className="text-sm font-semibold">Set Game Plan</span>
            <ChevronRight className="h-4 w-4" />
          </div>
        </div>
      </div>
    </Link>
  );
}

/* ═══════════════════════════════════════════════════
   Team Snapshot — compact vital stats
   ═══════════════════════════════════════════════════ */

function TeamSnapshot() {
  const { team, coach } = useAuthStore();
  const { data: roster } = useRoster(team?.id);
  const { data: capInfo } = useCapSpace(team?.id);

  const injuredCount = roster?.active?.filter((p) => p.injury).length ?? 0;
  const rosterSize = (roster?.active?.length ?? 0);

  const capRemaining = capInfo?.cap_remaining ?? 0;
  const capTotal = capInfo?.total_cap ?? 1;
  const capPct = Math.round((capRemaining / capTotal) * 100);

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-5">
      <div className="flex items-center gap-3 mb-4">
        <TeamBadge
          abbreviation={team?.abbreviation}
          primaryColor={team?.primary_color}
          secondaryColor={team?.secondary_color}
          size="md"
        />
        <div className="min-w-0">
          <p className="font-bold text-[var(--text-primary)] truncate">{team?.city} {team?.name}</p>
          <p className="text-sm font-semibold text-[var(--text-secondary)]">
            {team?.wins ?? 0}-{team?.losses ?? 0}{team?.ties ? `-${team.ties}` : ''}
            {team?.streak ? <span className="ml-2 text-xs font-normal text-[var(--text-muted)]">{team.streak}</span> : ''}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <SnapshotStat icon={<TrendingUp className="h-3.5 w-3.5" />} label="Rating" value={String(team?.overall_rating ?? 0)} />
        <SnapshotStat icon={<Heart className="h-3.5 w-3.5" />} label="Morale" value={String(team?.morale ?? 0)} />
        <SnapshotStat icon={<DollarSign className="h-3.5 w-3.5" />} label="Cap Space" value={`$${(capRemaining / 1_000_000).toFixed(1)}M`} sub={`${capPct}% free`} />
        <SnapshotStat icon={<AlertTriangle className="h-3.5 w-3.5" />} label="Injuries" value={String(injuredCount)} sub={`${rosterSize} roster`} />
        <SnapshotStat icon={<Shield className="h-3.5 w-3.5" />} label="Job Security" value={String(coach?.job_security ?? 0)} />
        <SnapshotStat icon={<Briefcase className="h-3.5 w-3.5" />} label="Influence" value={String(coach?.influence ?? 0)} />
      </div>

      <div className="mt-4 grid grid-cols-2 gap-2">
        <Link to="/my-team" className="text-center rounded-lg border border-[var(--border)] px-3 py-2 text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-colors">
          Roster
        </Link>
        <Link to="/salary-cap" className="text-center rounded-lg border border-[var(--border)] px-3 py-2 text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-colors">
          Salary Cap
        </Link>
      </div>
    </div>
  );
}

function SnapshotStat({ icon, label, value, sub }: { icon: React.ReactNode; label: string; value: string; sub?: string }) {
  return (
    <div className="flex items-start gap-2 rounded-lg bg-[var(--bg-elevated)] px-3 py-2.5">
      <span className="mt-0.5 text-[var(--text-muted)]">{icon}</span>
      <div className="min-w-0">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">{label}</p>
        <p className="text-sm font-bold text-[var(--text-primary)] leading-tight">{value}</p>
        {sub && <p className="text-[10px] text-[var(--text-muted)]">{sub}</p>}
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   Division Standings Snapshot
   ═══════════════════════════════════════════════════ */

function StandingsSnapshot() {
  const { team, league } = useAuthStore();
  const { data: standingsData } = useStandings(league?.id);

  if (!standingsData || !team) return null;

  const myConf = team.conference;
  const myDiv = team.division;

  // Find my division from standings
  const confDivs = standingsData.divisions?.[myConf];
  const divTeams: StandingsTeam[] = confDivs?.[myDiv] ?? [];

  if (divTeams.length === 0) return null;

  const sorted = [...divTeams].sort((a, b) => (b.win_pct ?? 0) - (a.win_pct ?? 0));

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-5">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
          {myDiv}
        </h3>
        <Link to="/standings" className="text-[10px] font-semibold text-[var(--accent-blue)] hover:opacity-80 transition-opacity uppercase tracking-wider">
          Full Standings
        </Link>
      </div>

      <div className="space-y-0">
        {/* Header */}
        <div className="flex items-center gap-2 px-2 py-1 text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
          <span className="flex-1">Team</span>
          <span className="w-6 text-center">W</span>
          <span className="w-6 text-center">L</span>
          <span className="w-10 text-center">Pct</span>
        </div>

        {sorted.map((t, i) => {
          const isMe = t.id === team.id;
          return (
            <div
              key={t.id}
              className={`flex items-center gap-2 rounded-md px-2 py-1.5 text-sm ${
                isMe ? 'bg-[var(--accent-blue)]/8 font-semibold' : ''
              }`}
            >
              <span className="w-4 text-xs text-[var(--text-muted)]">{i + 1}</span>
              <TeamBadge
                abbreviation={t.abbreviation}
                primaryColor={t.primary_color}
                secondaryColor={t.secondary_color}
                size="xs"
              />
              <span className={`flex-1 text-[13px] truncate ${isMe ? 'text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}`}>
                {t.abbreviation}
              </span>
              <span className={`w-6 text-center font-stat text-xs ${isMe ? 'text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}`}>{t.wins}</span>
              <span className={`w-6 text-center font-stat text-xs ${isMe ? 'text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}`}>{t.losses}</span>
              <span className={`w-10 text-center font-stat text-xs ${isMe ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`}>
                {(t.win_pct ?? 0).toFixed(3).replace(/^0/, '')}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   Score Strip — this week's scores (inline version)
   ═══════════════════════════════════════════════════ */

function WeekScores() {
  const { team, league } = useAuthStore();
  const { data: schedule } = useSchedule(league?.id);

  const currentWeek = league?.current_week ?? 0;
  const weekGames: Game[] = schedule?.[String(currentWeek)] ?? [];
  const simmedGames = weekGames.filter((g) => g.is_simulated);

  if (simmedGames.length === 0) return null;

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
      <div className="flex items-center justify-between px-5 pt-4 pb-2">
        <h3 className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
          Week {currentWeek} Scores
        </h3>
        <Link to="/schedule" className="text-[10px] font-semibold text-[var(--accent-blue)] hover:opacity-80 transition-opacity uppercase tracking-wider">
          All Scores
        </Link>
      </div>
      <div className="flex overflow-x-auto gap-2 px-4 pb-4 pt-1" style={{ scrollbarWidth: 'none' }}>
        {simmedGames.map((game) => {
          const isMyGame = team && (game.home_team_id === team.id || game.away_team_id === team.id);
          return (
            <Link
              key={game.id}
              to={`/box-score/${game.id}`}
              className={`shrink-0 rounded-lg border px-3 py-2 transition-colors hover:bg-[var(--bg-elevated)] ${
                isMyGame ? 'border-[var(--accent-blue)]/40 bg-[var(--accent-blue)]/5' : 'border-[var(--border)]'
              }`}
            >
              <MiniScore game={game} />
            </Link>
          );
        })}
      </div>
    </div>
  );
}

function MiniScore({ game }: { game: Game }) {
  const awayWon = (game.away_score ?? 0) > (game.home_score ?? 0);
  const homeWon = (game.home_score ?? 0) > (game.away_score ?? 0);

  return (
    <div className="flex flex-col gap-0.5 min-w-[80px]">
      <div className="flex items-center justify-between gap-3">
        <span className={`text-[11px] font-semibold ${awayWon ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`}>
          {game.away_team?.abbreviation ?? 'AWY'}
        </span>
        <span className={`font-stat text-xs ${awayWon ? 'text-[var(--text-primary)] font-bold' : 'text-[var(--text-muted)]'}`}>
          {game.away_score}
        </span>
      </div>
      <div className="flex items-center justify-between gap-3">
        <span className={`text-[11px] font-semibold ${homeWon ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`}>
          {game.home_team?.abbreviation ?? 'HME'}
        </span>
        <span className={`font-stat text-xs ${homeWon ? 'text-[var(--text-primary)] font-bold' : 'text-[var(--text-muted)]'}`}>
          {game.home_score}
        </span>
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   League Activity — recent transactions
   ═══════════════════════════════════════════════════ */

function LeagueActivity() {
  const { data: activity } = useActivity();

  // Activity from commissioner endpoint shows team activity, not transactions
  // For now show team activity if available
  if (!activity || activity.length === 0) return null;

  // Show top 5 most active teams as a quick "league pulse"
  const active = [...activity]
    .filter((a) => a.games_played > 0)
    .sort((a, b) => b.games_played - a.games_played)
    .slice(0, 5);

  if (active.length === 0) return null;

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-5">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
          League Activity
        </h3>
        <Link to="/league-hub" className="text-[10px] font-semibold text-[var(--accent-blue)] hover:opacity-80 transition-opacity uppercase tracking-wider">
          League Hub
        </Link>
      </div>
      <div className="space-y-2">
        {active.map((a) => (
          <div key={a.team_id} className="flex items-center gap-2 text-sm">
            <span className="text-xs font-bold text-[var(--text-muted)] w-8">{a.abbreviation}</span>
            <span className="flex-1 text-[var(--text-secondary)] text-xs truncate">
              {a.team_name} — {a.games_played} game{a.games_played !== 1 ? 's' : ''} played
              {a.plans_missed > 0 && `, ${a.plans_missed} plan${a.plans_missed !== 1 ? 's' : ''} missed`}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   Last Game Result — compact score + link
   ═══════════════════════════════════════════════════ */

function LastGameResult() {
  const { team, league } = useAuthStore();
  const { data: schedule } = useSchedule(league?.id);

  if (!schedule || !team) return null;

  const allGames = Object.values(schedule).flat();
  const mySimmed = allGames
    .filter((g) => g.is_simulated && (g.home_team_id === team.id || g.away_team_id === team.id))
    .sort((a, b) => b.week - a.week);

  if (mySimmed.length === 0) return null;

  const game = mySimmed[0];

  return (
    <div>
      <h2 className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-3">Last Game</h2>
      <GameCard game={game} myTeamId={team.id} />
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   Dashboard — Main Layout
   ═══════════════════════════════════════════════════ */

export default function Dashboard() {
  const { team, league } = useAuthStore();
  const { data: schedule } = useSchedule(league?.id);
  const { data: articlesResp } = useArticles(league?.id, { page: 1 });
  const { data: roster } = useRoster(team?.id);
  const { data: staffData } = useCoachingStaff();
  const { data: depthChart } = useDepthChart(team?.id);

  const phase = league?.phase ?? 'preseason';

  // Onboarding state
  const showOnboarding = typeof window !== 'undefined' && localStorage.getItem('onboarding-dismissed') !== 'true';
  const hasRoster = (roster?.active?.length ?? 0) > 0;
  const hasDepthChart = depthChart ? Object.values(depthChart).flat().length >= 10 : false;
  const hasStaff = (staffData?.length ?? 0) > 0;
  const allGamesForPlan = schedule ? Object.values(schedule).flat() : [];
  const hasGamePlan = allGamesForPlan.some((g) => g.is_simulated);

  // Find next game
  let nextGame: Game | null = null;
  if (schedule && team) {
    const allGames = Object.values(schedule).flat();
    const myGames = allGames.filter((g) => g.home_team_id === team.id || g.away_team_id === team.id);
    const sorted = myGames.sort((a, b) => a.week - b.week);
    nextGame = sorted.find((g) => !g.is_simulated) ?? null;
  }

  const articles = articlesResp?.articles?.slice(0, 4) ?? [];

  return (
    <div className="space-y-6">
      {/* Onboarding (conditional) */}
      {showOnboarding && (
        <Onboarding
          hasRoster={hasRoster}
          hasDepthChart={hasDepthChart}
          hasStaff={hasStaff}
          hasGamePlan={hasGamePlan}
        />
      )}

      {/* 1. Coach's Agenda — full width */}
      <CoachAgenda />

      {/* 2. Next Game — full width */}
      <NextGameCard game={nextGame} myTeamId={team?.id} phase={phase} />

      {/* 3. Two columns: Team Snapshot + Standings */}
      <div className="grid gap-6 md:grid-cols-2">
        <TeamSnapshot />
        <StandingsSnapshot />
      </div>

      {/* 4. Score Strip — horizontal scroll of this week's scores */}
      <WeekScores />

      {/* 5. Two columns: News + Activity & Last Game */}
      <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
        {/* Left: Latest News */}
        <div>
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-bold text-[var(--text-primary)]">Latest News</h2>
            <Link to="/league-hub" className="text-sm font-medium text-[var(--accent-blue)] hover:opacity-80 transition-opacity">
              All News
            </Link>
          </div>

          {articles.length > 0 ? (
            <div className="space-y-3">
              {articles.map((a, i) => (
                <motion.div
                  key={a.id}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.3, delay: i * 0.05 }}
                >
                  <ArticleCard article={a} />
                </motion.div>
              ))}
            </div>
          ) : (
            <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
              <p className="text-[var(--text-secondary)]">
                Headlines will appear once games are played.
              </p>
            </div>
          )}
        </div>

        {/* Right: Activity + Last Game */}
        <div className="space-y-6">
          <LeagueActivity />
          <LastGameResult />
        </div>
      </div>
    </div>
  );
}
