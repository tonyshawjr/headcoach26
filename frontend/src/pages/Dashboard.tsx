import { Link } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import {
  useSchedule, useArticles, useRoster, useSimulateWeek,
  useAdvanceWeek, useStandings, useCapSpace,
} from '@/hooks/useApi';
import { TeamBadge } from '@/components/TeamBadge';
import Onboarding from '@/components/Onboarding';
import {
  ClipboardList, Play, FastForward, GraduationCap,
  UserPlus, CalendarDays, ChevronRight,
} from 'lucide-react';
import { toast } from 'sonner';
import type { Game, Article, StandingsTeam } from '@/api/client';
import { useCoachingStaff, useDepthChart } from '@/hooks/useApi';
import { ArticleHeroImage } from '@/components/ArticleHeroImage';

/* ═══════════════════════════════════════════════════
   Coach's Agenda — slim inline action bar
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
    if (myWeekGame && !myWeekGame.is_simulated) myNextGame = myWeekGame;
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

  const btnPrimary = "inline-flex items-center gap-2 rounded-lg bg-[var(--accent-blue)] px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-opacity disabled:opacity-50";
  const btnSecondary = "inline-flex items-center gap-2 rounded-lg border border-[var(--border)] px-5 py-2.5 text-sm font-medium text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-colors disabled:opacity-50";

  let message = '';
  let actions: React.ReactNode = null;

  if (phase === 'preseason') {
    message = "Preseason — Build your roster and staff, then start the season.";
    actions = (
      <>
        <button onClick={handleAdvance} disabled={advance.isPending} className={btnPrimary}>
          <FastForward className="h-4 w-4" />
          {advance.isPending ? 'Starting...' : 'Start Season'}
        </button>
        <Link to="/my-team" className={btnSecondary}>
          <ClipboardList className="h-4 w-4" />
          View Roster
        </Link>
      </>
    );
  } else if (phase === 'regular' || phase === 'playoffs') {
    if (weekSimmed) {
      message = `Week ${week} complete.${lastResult ? ` ${lastResult}.` : ''} Ready to move on?`;
      const nextLabel = phase === 'regular' && week >= 18 ? 'Start Playoffs' : phase === 'playoffs' && week >= 22 ? 'Enter Offseason' : `Go to Week ${week + 1}`;
      actions = (
        <>
          <button onClick={handleAdvance} disabled={advance.isPending} className={btnPrimary}>
            <FastForward className="h-4 w-4" />
            {advance.isPending ? 'Advancing...' : nextLabel}
          </button>
          <Link to="/schedule" className={btnSecondary}>
            <CalendarDays className="h-4 w-4" />
            Results
          </Link>
        </>
      );
    } else if (myNextGame) {
      message = `Week ${week} — ${team?.name} vs ${opponentName}`;
      actions = (
        <>
          <Link to={`/game-plan/${myNextGame.id}`} className={btnPrimary}>
            <ClipboardList className="h-4 w-4" />
            Game Plan
          </Link>
          <button onClick={handleSim} disabled={sim.isPending} className={btnSecondary}>
            <Play className="h-4 w-4" />
            {sim.isPending ? 'Playing...' : 'Sim'}
          </button>
        </>
      );
    } else {
      message = `Week ${week} — bye week`;
      actions = (
        <button onClick={handleSim} disabled={sim.isPending} className={btnSecondary}>
          <Play className="h-4 w-4" />
          {sim.isPending ? 'Playing...' : `Sim Week ${week}`}
        </button>
      );
    }
  } else if (phase === 'offseason') {
    message = "Offseason — Draft and sign free agents.";
    actions = (
      <>
        <Link to="/draft" className={btnPrimary}>
          <GraduationCap className="h-4 w-4" />
          Draft
        </Link>
        <Link to="/free-agency" className={btnSecondary}>
          <UserPlus className="h-4 w-4" />
          Free Agency
        </Link>
        <button onClick={handleAdvance} disabled={advance.isPending} className={btnSecondary}>
          <FastForward className="h-4 w-4" />
          {advance.isPending ? 'Starting...' : 'New Season'}
        </button>
      </>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] px-5 py-3">
      <p className="text-sm text-[var(--text-primary)] font-medium flex-1 min-w-0">{message}</p>
      <div className="flex flex-wrap items-center gap-2">{actions}</div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   Hero Article — large featured story
   ═══════════════════════════════════════════════════ */

const typeConfig: Record<string, { label: string; color: string }> = {
  game_recap: { label: 'RECAP', color: 'var(--accent-blue)' },
  power_rankings: { label: 'RANKINGS', color: 'var(--accent-gold)' },
  feature: { label: 'FEATURE', color: 'var(--accent-red)' },
  column: { label: 'COLUMN', color: '#8b5cf6' },
  morning_blitz: { label: 'BLITZ', color: 'var(--accent-gold)' },
};

function HeroArticle({ article }: { article: Article }) {
  const config = typeConfig[article.type] ?? { label: 'NEWS', color: 'var(--accent-blue)' };

  return (
    <Link to={`/article/${article.id}`} className="group block h-full">
      <div className="relative overflow-hidden rounded-xl bg-[var(--bg-surface)] border border-[var(--border)] h-full min-h-[320px] flex flex-col justify-end">
        {/* Dynamic team-color gradient + pattern + player photo */}
        <ArticleHeroImage
          teamId={article.team_id}
          articleType={article.type}
          articleId={article.id}
        />

        {/* Content at bottom */}
        <div className="relative z-10 p-6 sm:p-8">
          <span
            className="inline-block rounded px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.15em] mb-3"
            style={{ backgroundColor: config.color, color: '#fff' }}
          >
            {config.label}
          </span>

          <h2 className="font-display text-2xl sm:text-3xl leading-tight text-white tracking-tight">
            {article.headline}
          </h2>

          <p className="mt-3 text-sm text-white/60 line-clamp-2 max-w-[600px]">
            {(article.body ?? '').substring(0, 200)}...
          </p>

          <div className="mt-4 flex items-center gap-3 text-[11px] text-white/40">
            {article.author_name && (
              <span className="font-semibold text-white/60">{article.author_name}</span>
            )}
            {article.week != null && <span>Week {article.week}</span>}
          </div>
        </div>
      </div>
    </Link>
  );
}

function HeroPlaceholder() {
  const { league } = useAuthStore();
  const phase = league?.phase ?? 'preseason';

  const messages: Record<string, string> = {
    preseason: 'Start the season to generate game recaps and news.',
    regular: 'Play games to see headlines and stories here.',
    playoffs: 'Playoff coverage will appear here.',
    offseason: 'Offseason news will appear once moves are made.',
  };

  return (
    <div className="relative overflow-hidden rounded-xl bg-[var(--bg-surface)] border border-[var(--border)] h-full min-h-[320px] flex flex-col items-center justify-center">
      <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-black/20 to-transparent" />
      <div className="relative z-10 text-center px-6">
        <CalendarDays className="mx-auto mb-4 h-10 w-10 text-[var(--text-muted)]" />
        <p className="font-display text-xl text-white/80">{messages[phase] ?? 'No stories yet.'}</p>
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   Side Article — vertical accent bar + headline
   ═══════════════════════════════════════════════════ */

function SideArticle({ article }: { article: Article }) {
  const config = typeConfig[article.type] ?? { label: 'NEWS', color: 'var(--text-muted)' };

  return (
    <Link to={`/article/${article.id}`} className="group flex gap-4 py-4 border-b border-[var(--border)] last:border-0">
      {/* Vertical accent bar + rotated type label */}
      <div className="flex flex-col items-center gap-1 shrink-0">
        <div className="w-[3px] flex-1 rounded-full" style={{ backgroundColor: config.color }} />
        <span
          className="text-[8px] font-bold uppercase tracking-[0.2em] whitespace-nowrap"
          style={{ color: config.color, writingMode: 'vertical-lr', transform: 'rotate(180deg)' }}
        >
          {config.label}
        </span>
        <div className="w-[3px] flex-1 rounded-full" style={{ backgroundColor: config.color }} />
      </div>

      {/* Text */}
      <div className="min-w-0 flex-1">
        <h3 className="text-[14px] font-bold leading-snug text-[var(--text-primary)] line-clamp-3">
          {article.headline}
        </h3>
        <div className="mt-2 flex items-center gap-2 text-[10px] text-[var(--text-muted)]">
          {article.author_name && <span className="font-semibold">{article.author_name}</span>}
          {article.week != null && (
            <>
              <span className="opacity-40">&bull;</span>
              <span>Week {article.week}</span>
            </>
          )}
        </div>
      </div>
    </Link>
  );
}

/* ═══════════════════════════════════════════════════
   Team Snapshot — compact 3x2 stat grid
   ═══════════════════════════════════════════════════ */

function TeamSnapshot() {
  const { team, coach } = useAuthStore();
  const { data: roster } = useRoster(team?.id);
  const { data: capInfo } = useCapSpace(team?.id);

  const injuredCount = roster?.active?.filter((p) => p.injury).length ?? 0;
  const capRemaining = capInfo?.cap_remaining ?? 0;

  const stats = [
    { label: 'Record', value: `${team?.wins ?? 0}-${team?.losses ?? 0}${team?.ties ? `-${team.ties}` : ''}` },
    { label: 'Rating', value: String(team?.overall_rating ?? 0) },
    { label: 'Morale', value: String(team?.morale ?? 0) },
    { label: 'Cap Space', value: `$${(capRemaining / 1_000_000).toFixed(1)}M` },
    { label: 'Injuries', value: String(injuredCount) },
    { label: 'Job Security', value: String(coach?.job_security ?? 0) },
  ];

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4">
      <div className="flex items-center gap-3 mb-3">
        <TeamBadge abbreviation={team?.abbreviation} primaryColor={team?.primary_color} secondaryColor={team?.secondary_color} size="sm" />
        <p className="font-display text-sm text-[var(--text-primary)] tracking-tight">{team?.city} {team?.name}</p>
      </div>
      <div className="grid grid-cols-3 gap-2">
        {stats.map((s) => (
          <div key={s.label} className="text-center rounded-lg bg-[var(--bg-elevated)] px-2 py-2">
            <p className="text-[9px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">{s.label}</p>
            <p className="text-sm font-bold text-[var(--text-primary)] font-stat">{s.value}</p>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   Division Standings mini-table
   ═══════════════════════════════════════════════════ */

function StandingsSnapshot() {
  const { team, league } = useAuthStore();
  const { data: standingsData } = useStandings(league?.id);

  if (!standingsData || !team) return null;

  const confDivs = standingsData.divisions?.[team.conference];
  const divTeams: StandingsTeam[] = confDivs?.[team.division] ?? [];
  if (divTeams.length === 0) return null;

  const sorted = [...divTeams].sort((a, b) => (b.win_pct ?? 0) - (a.win_pct ?? 0));

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-[10px] font-semibold uppercase tracking-[0.15em] text-[var(--text-muted)]">{team.division}</h3>
        <Link to="/standings" className="text-[10px] font-semibold text-[var(--accent-blue)] hover:opacity-80 transition-opacity uppercase tracking-wider">
          Full Standings
        </Link>
      </div>
      <div className="space-y-0">
        {sorted.map((t, i) => {
          const isMe = t.id === team.id;
          return (
            <div key={t.id} className={`flex items-center gap-2 py-1.5 px-2 rounded text-[12px] ${isMe ? 'bg-[var(--accent-blue)]/8' : ''}`}>
              <span className="w-3 text-[10px] text-[var(--text-muted)] font-stat">{i + 1}</span>
              <TeamBadge abbreviation={t.abbreviation} primaryColor={t.primary_color} secondaryColor={t.secondary_color} size="xs" />
              <span className={`flex-1 font-medium ${isMe ? 'text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}`}>{t.abbreviation}</span>
              <span className="font-stat text-[var(--text-secondary)]">{t.wins}-{t.losses}</span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════
   Next Game preview — compact card
   ═══════════════════════════════════════════════════ */

function NextGamePreview({ game, myTeamId }: { game: Game; myTeamId?: number }) {
  const home = game.home_team;
  const away = game.away_team;
  const isHome = game.home_team_id === myTeamId;

  return (
    <Link to={`/game-plan/${game.id}`} className="block group">
      <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4 transition-colors hover:bg-[var(--bg-elevated)]">
        <p className="text-[10px] font-semibold uppercase tracking-[0.15em] text-[var(--text-muted)] mb-3 text-center">
          {game.game_type === 'playoff' ? 'Playoff' : `Week ${game.week}`}
        </p>
        <div className="flex items-center justify-center gap-4">
          <div className="flex flex-col items-center gap-1.5">
            <TeamBadge abbreviation={away?.abbreviation} primaryColor={away?.primary_color} secondaryColor={away?.secondary_color} size="md" />
            <span className="text-[11px] font-bold text-[var(--text-primary)]">{away?.abbreviation}</span>
            <span className="text-[10px] text-[var(--text-muted)]">{away?.wins ?? 0}-{away?.losses ?? 0}</span>
          </div>
          <span className="text-sm font-bold text-[var(--text-muted)]">{isHome ? 'VS' : '@'}</span>
          <div className="flex flex-col items-center gap-1.5">
            <TeamBadge abbreviation={home?.abbreviation} primaryColor={home?.primary_color} secondaryColor={home?.secondary_color} size="md" />
            <span className="text-[11px] font-bold text-[var(--text-primary)]">{home?.abbreviation}</span>
            <span className="text-[10px] text-[var(--text-muted)]">{home?.wins ?? 0}-{home?.losses ?? 0}</span>
          </div>
        </div>
        <div className="mt-3 flex items-center justify-center gap-1 text-[var(--accent-blue)] text-[11px] font-semibold group-hover:opacity-80">
          <ClipboardList className="h-3 w-3" />
          Game Plan
          <ChevronRight className="h-3 w-3" />
        </div>
      </div>
    </Link>
  );
}

/* ═══════════════════════════════════════════════════
   Dashboard — Editorial Layout
   ═══════════════════════════════════════════════════ */

export default function Dashboard() {
  const { team, league } = useAuthStore();
  const { data: schedule } = useSchedule(league?.id);
  const { data: articlesResp } = useArticles(league?.id, { page: 1 });
  const { data: roster } = useRoster(team?.id);
  const { data: staffData } = useCoachingStaff();
  const { data: depthChart } = useDepthChart(team?.id);

  const phase = league?.phase ?? 'preseason';

  // Onboarding
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
    nextGame = myGames.sort((a, b) => a.week - b.week).find((g) => !g.is_simulated) ?? null;
  }

  const articles = articlesResp?.articles ?? [];
  const heroArticle = articles[0] ?? null;
  const sideArticles = articles.slice(1, 6);

  return (
    <div className="space-y-5">
      {/* Onboarding */}
      {showOnboarding && (
        <Onboarding hasRoster={hasRoster} hasDepthChart={hasDepthChart} hasStaff={hasStaff} hasGamePlan={hasGamePlan} />
      )}

      {/* 1. Coach's Agenda — slim bar */}
      <CoachAgenda />

      {/* 2. Editorial: Hero article + side articles */}
      <div className="grid gap-5 lg:grid-cols-[1fr_320px] items-stretch">
        <div className="flex flex-col">
          {heroArticle ? <HeroArticle article={heroArticle} /> : <HeroPlaceholder />}
        </div>

        <div className="flex flex-col">
          <div className="flex items-center justify-between mb-1">
            <h2 className="text-[10px] font-semibold uppercase tracking-[0.15em] text-[var(--text-muted)]">Latest</h2>
            <Link to="/league-hub" className="text-[10px] font-semibold text-[var(--accent-blue)] hover:opacity-80 transition-opacity uppercase tracking-wider">
              All News
            </Link>
          </div>
          {sideArticles.length > 0 ? (
            <div>
              {sideArticles.map((a) => (
                <SideArticle key={a.id} article={a} />
              ))}
            </div>
          ) : (
            <div className="flex-1 flex items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6">
              <p className="text-sm text-[var(--text-muted)]">More stories coming soon.</p>
            </div>
          )}
        </div>
      </div>

      {/* 3. Bottom row: Team Snapshot + Standings + Next Game */}
      <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <TeamSnapshot />
        <StandingsSnapshot />
        {nextGame ? (
          <NextGamePreview game={nextGame} myTeamId={team?.id} />
        ) : (
          <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 flex flex-col items-center justify-center">
            <CalendarDays className="mb-2 h-6 w-6 text-[var(--text-muted)]" />
            <p className="text-sm text-[var(--text-secondary)] text-center">
              {phase === 'offseason' ? 'Season over' : phase === 'preseason' ? 'Start the season' : 'No upcoming game'}
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
