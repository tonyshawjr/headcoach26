import { Link } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useSchedule, useArticles, useRoster, useSimulateWeek, useAdvanceWeek } from '@/hooks/useApi';
import { StatCard } from '@/components/cards/StatCard';
import { GameCard } from '@/components/cards/GameCard';
import { ArticleCard } from '@/components/cards/ArticleCard';
import { TeamBadge } from '@/components/TeamBadge';
import { Trophy, TrendingUp, Shield, Heart, ClipboardList, Play, FastForward, GraduationCap, UserPlus, CalendarDays } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { motion } from 'framer-motion';
import { toast } from 'sonner';
import type { Game } from '@/api/client';

function CoachAgenda() {
  const { team, league } = useAuthStore();
  const { data: schedule } = useSchedule(league?.id);
  const sim = useSimulateWeek(league?.id ?? 0);
  const advance = useAdvanceWeek(league?.id ?? 0);

  const phase = league?.phase ?? 'preseason';
  const week = league?.current_week ?? 0;

  // Find my next game and check sim status for current week
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

  // Find opponent name for my next game
  let opponentName = '';
  if (myNextGame && team) {
    const opp = myNextGame.home_team_id === team.id ? myNextGame.away_team : myNextGame.home_team;
    opponentName = `${opp?.city ?? ''} ${opp?.name ?? ''}`.trim();
  }

  // Find last game result
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
      toast.success(`Week ${result.week} simulated — ${result.results.length} games completed`);
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

  // Determine what to show
  let message = '';
  let action: React.ReactNode = null;

  if (phase === 'preseason') {
    message = "Welcome to the preseason, Coach. Build your roster and set your staff, then start the season when you're ready.";
    action = (
      <div className="flex flex-wrap gap-2">
        <Button size="sm" onClick={handleAdvance} disabled={advance.isPending} className="gap-1.5 bg-[var(--accent-blue)] text-white hover:bg-[var(--accent-blue)]/90">
          <FastForward className="h-3.5 w-3.5" />
          {advance.isPending ? 'Starting...' : 'Start Season'}
        </Button>
        <Link to="/my-team">
          <Button size="sm" variant="outline" className="gap-1.5">
            <ClipboardList className="h-3.5 w-3.5" />
            View Roster
          </Button>
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
        <div className="flex flex-wrap gap-2">
          <Button size="sm" onClick={handleAdvance} disabled={advance.isPending} className="gap-1.5 bg-[var(--accent-blue)] text-white hover:bg-[var(--accent-blue)]/90">
            <FastForward className="h-3.5 w-3.5" />
            {advance.isPending ? 'Advancing...' : nextLabel}
          </Button>
          <Link to="/schedule">
            <Button size="sm" variant="outline" className="gap-1.5">
              <CalendarDays className="h-3.5 w-3.5" />
              View Results
            </Button>
          </Link>
        </div>
      );
    } else if (myNextGame) {
      message = `Week ${week} is here. Your ${team?.name} play the ${opponentName} this week. Set your game plan, then simulate.`;
      action = (
        <div className="flex flex-wrap gap-2">
          <Link to={`/game-plan/${myNextGame.id}`}>
            <Button size="sm" className="gap-1.5 bg-[var(--accent-blue)] text-white hover:bg-[var(--accent-blue)]/90">
              <ClipboardList className="h-3.5 w-3.5" />
              Set Game Plan
            </Button>
          </Link>
          <Button size="sm" variant="outline" onClick={handleSim} disabled={sim.isPending} className="gap-1.5">
            <Play className="h-3.5 w-3.5" />
            {sim.isPending ? 'Simulating...' : `Sim Week ${week}`}
          </Button>
        </div>
      );
    } else {
      message = `Week ${week} — no game for your team this week. Simulate or advance when ready.`;
      action = (
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" onClick={handleSim} disabled={sim.isPending} className="gap-1.5">
            <Play className="h-3.5 w-3.5" />
            {sim.isPending ? 'Simulating...' : `Sim Week ${week}`}
          </Button>
        </div>
      );
    }
  } else if (phase === 'offseason') {
    message = "The season is over. Hit the Draft Room and Free Agency to retool for next year.";
    action = (
      <div className="flex flex-wrap gap-2">
        <Link to="/draft">
          <Button size="sm" className="gap-1.5 bg-[var(--accent-blue)] text-white hover:bg-[var(--accent-blue)]/90">
            <GraduationCap className="h-3.5 w-3.5" />
            Draft Room
          </Button>
        </Link>
        <Link to="/free-agency">
          <Button size="sm" variant="outline" className="gap-1.5">
            <UserPlus className="h-3.5 w-3.5" />
            Free Agency
          </Button>
        </Link>
        <Button size="sm" variant="outline" onClick={handleAdvance} disabled={advance.isPending} className="gap-1.5">
          <FastForward className="h-3.5 w-3.5" />
          {advance.isPending ? 'Starting...' : 'Begin New Season'}
        </Button>
      </div>
    );
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: -8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay: 0.05 }}
    >
      <div
        className="relative overflow-hidden rounded-lg border border-amber-500/20 p-4"
        style={{
          background: 'linear-gradient(135deg, rgba(245,158,11,0.06), var(--bg-surface), var(--bg-surface))',
        }}
      >
        <div className="absolute left-0 top-0 h-full w-[3px] bg-amber-500" />
        <div className="pl-2">
          <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-500 mb-1.5">
            Coach&apos;s Agenda
          </p>
          <p className="text-sm text-[var(--text-secondary)] mb-3">{message}</p>
          {action}
        </div>
      </div>
    </motion.div>
  );
}

export default function Dashboard() {
  const { team, coach, league } = useAuthStore();
  const { data: schedule } = useSchedule(league?.id);
  const { data: articlesResp } = useArticles(league?.id, { page: 1 });
  const { data: roster } = useRoster(team?.id);

  const phase = league?.phase ?? 'preseason';

  // Find the next unplayed game for our team
  let nextGame: Game | null = null;
  let lastGame: Game | null = null;
  if (schedule && team) {
    const allGames = Object.values(schedule).flat();
    const myGames = allGames.filter((g) => g.home_team_id === team.id || g.away_team_id === team.id);
    const sorted = myGames.sort((a, b) => a.week - b.week);
    nextGame = sorted.find((g) => !g.is_simulated) ?? null;
    lastGame = [...sorted].reverse().find((g) => g.is_simulated) ?? null;
  }

  const injuredCount = roster?.active?.filter((p) => p.injury).length ?? 0;
  const articles = articlesResp?.articles?.slice(0, 5) ?? [];

  return (
    <div className="space-y-6">
      {/* Hero header with team branding */}
      <motion.div
        initial={{ opacity: 0, y: -12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4 }}
      >
        <div
          className="relative overflow-hidden rounded-lg border border-[var(--border)] p-6"
          style={{
            background: `linear-gradient(135deg, ${team?.primary_color ?? '#2188FF'}12, var(--bg-surface), var(--bg-surface))`,
          }}
        >
          {/* Accent stripe */}
          <div
            className="absolute left-0 top-0 h-full w-[3px]"
            style={{ backgroundColor: team?.primary_color ?? 'var(--accent-blue)' }}
          />
          <div className="flex items-center gap-4">
            <TeamBadge
              abbreviation={team?.abbreviation}
              primaryColor={team?.primary_color}
              secondaryColor={team?.secondary_color}
              size="lg"
            />
            <div>
              <h1 className="font-display text-2xl tracking-tight">{team?.city} {team?.name}</h1>
              <p className="text-sm text-[var(--text-secondary)]">
                HC {coach?.name}
                <span className="mx-2 text-[var(--text-muted)]">/</span>
                <span className="text-[var(--accent-blue)]">
                  {league?.phase === 'regular' ? `Week ${league?.current_week}` : league?.phase}
                </span>
              </p>
            </div>
          </div>
        </div>
      </motion.div>

      {/* Coach's Agenda — the "journey" card */}
      <CoachAgenda />

      {/* Stat Cards */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {[
          { title: 'Record', value: `${team?.wins ?? 0}-${team?.losses ?? 0}`, subtitle: team?.streak || undefined, icon: Trophy, color: team?.primary_color ?? 'var(--accent-blue)' },
          { title: 'Influence', value: coach?.influence ?? 0, subtitle: `Job Security: ${coach?.job_security ?? 0}`, icon: TrendingUp, trend: (coach && coach.influence >= 50 ? 'up' : 'down') as 'up' | 'down' },
          { title: 'Team Rating', value: team?.overall_rating ?? 0, subtitle: `Morale: ${team?.morale ?? 0}`, icon: Shield, color: undefined },
          { title: 'Injuries', value: injuredCount, subtitle: `${roster?.active?.length ?? 0} active players`, icon: Heart, trend: (injuredCount > 3 ? 'down' : 'neutral') as 'down' | 'neutral' },
        ].map((props, i) => (
          <motion.div
            key={props.title}
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.35, delay: 0.1 + i * 0.06 }}
          >
            <StatCard {...props} />
          </motion.div>
        ))}
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Games Column */}
        <motion.div
          className="space-y-4"
          initial={{ opacity: 0, x: -16 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.4, delay: 0.3 }}
        >
          <div className="flex items-center gap-2">
            <div className="h-4 w-[3px] rounded-full bg-[var(--accent-blue)]" />
            <h2 className="font-display text-sm uppercase tracking-wider">Games</h2>
          </div>
          {lastGame && (
            <div>
              <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">Last Game</p>
              <GameCard game={lastGame} myTeamId={team?.id} />
            </div>
          )}
          {nextGame && (
            <div>
              <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">Next Game</p>
              <GameCard game={nextGame} myTeamId={team?.id} />
            </div>
          )}
          {!lastGame && !nextGame && (
            <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
              <p className="text-sm text-[var(--text-secondary)]">
                {phase === 'preseason'
                  ? "Your schedule starts when the season begins. Hit \"Start Season\" above when you're ready."
                  : 'No games found. Check your schedule page.'}
              </p>
            </div>
          )}
        </motion.div>

        {/* News Feed */}
        <motion.div
          className="space-y-3 lg:col-span-2"
          initial={{ opacity: 0, x: 16 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.4, delay: 0.35 }}
        >
          <div className="flex items-center gap-2">
            <div className="h-4 w-[3px] rounded-full bg-[var(--accent-red)]" />
            <h2 className="font-display text-sm uppercase tracking-wider">Latest News</h2>
          </div>
          {articles.map((a, i) => (
            <motion.div
              key={a.id}
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, delay: 0.4 + i * 0.05 }}
            >
              <ArticleCard article={a} />
            </motion.div>
          ))}
          {articles.length === 0 && (
            <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
              <p className="text-sm text-[var(--text-secondary)]">
                Headlines will appear once games are played. Get out there and make some news, Coach.
              </p>
            </div>
          )}
        </motion.div>
      </div>
    </div>
  );
}
