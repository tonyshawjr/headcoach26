import { useAuthStore } from '@/stores/authStore';
import { useLogout, useSimulateWeek, useAdvanceWeek, useSchedule } from '@/hooks/useApi';
import { LogOut, Play, FastForward, User, Radio, Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { toast } from 'sonner';

function useNavLabels() {
  const league = useAuthStore((s) => s.league);
  const { data: schedule } = useSchedule(league?.id);

  const phase = league?.phase ?? 'preseason';
  const week = league?.current_week ?? 0;

  // Check if current week has unsimulated games
  const weekGames = schedule?.[String(week)] ?? [];
  const hasUnsimmed = weekGames.some((g) => !g.is_simulated);
  const weekSimmed = weekGames.length > 0 && !hasUnsimmed;

  // Sim button
  let simLabel = 'Sim Week';
  let simDisabled = false;
  let simTooltip = '';

  if (phase === 'preseason' || week === 0) {
    simLabel = 'Start Season First';
    simDisabled = true;
    simTooltip = 'Click "Start Season" to begin the regular season schedule';
  } else if (weekSimmed) {
    simLabel = `Week ${week} Done`;
    simDisabled = true;
    simTooltip = `All games for Week ${week} are complete. Advance to the next week.`;
  } else if (hasUnsimmed) {
    simLabel = `Sim Week ${week}`;
    simDisabled = false;
    simTooltip = `Simulate all games for Week ${week}`;
  } else {
    simLabel = 'No Games';
    simDisabled = true;
    simTooltip = 'No games scheduled for this week';
  }

  // Advance button
  let advLabel = 'Advance';
  let advTooltip = '';

  if (phase === 'preseason') {
    advLabel = 'Start Season';
    advTooltip = 'Begin the regular season — advance to Week 1';
  } else if (phase === 'regular') {
    if (week >= 18) {
      advLabel = 'Start Playoffs';
      advTooltip = 'The regular season is over — advance to the playoffs';
    } else {
      advLabel = `Go to Week ${week + 1}`;
      advTooltip = `Advance to Week ${week + 1} of the regular season`;
    }
  } else if (phase === 'playoffs') {
    if (week >= 22) {
      advLabel = 'Enter Offseason';
      advTooltip = 'The season is over — enter the offseason';
    } else {
      advLabel = 'Next Playoff Round';
      advTooltip = 'Advance to the next round of the playoffs';
    }
  } else if (phase === 'offseason') {
    advLabel = 'Begin New Season';
    advTooltip = 'Start a new season from the preseason';
  }

  return { simLabel, simDisabled, simTooltip, advLabel, advTooltip, weekSimmed };
}

export function Navbar() {
  const { coach, league, user } = useAuthStore();
  const logout = useLogout();
  const sim = useSimulateWeek(league?.id ?? 0);
  const advance = useAdvanceWeek(league?.id ?? 0);
  const { simLabel, simDisabled, simTooltip, advLabel, advTooltip, weekSimmed } = useNavLabels();

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

  return (
    <header className="flex h-12 items-center justify-between border-b border-[var(--border)] bg-[var(--bg-surface)]/80 px-6 backdrop-blur-sm">
      {/* Left: League info chyron */}
      <div className="flex items-center gap-3">
        {league && (
          <div className="flex items-center gap-2.5">
            <div className="flex items-center gap-1.5">
              <Radio className="h-3 w-3 text-[var(--accent-red)] animate-pulse" />
              <span className="text-[10px] font-bold uppercase tracking-widest text-[var(--accent-red)]">
                Live
              </span>
            </div>
            <div className="h-4 w-px bg-[var(--border)]" />
            <span className="text-sm font-semibold tracking-tight">{league.name}</span>
            <div className="rounded bg-[var(--bg-elevated)] px-2 py-0.5">
              <span className="text-xs font-semibold text-[var(--accent-blue)]">
                {league.phase === 'regular' ? `WEEK ${league.current_week}` : league.phase.toUpperCase()}
              </span>
            </div>
          </div>
        )}
      </div>

      {/* Right: Actions + User */}
      <div className="flex items-center gap-2">
        {league && (
          <>
            <Button
              size="sm"
              onClick={handleSim}
              disabled={sim.isPending || simDisabled}
              title={simTooltip}
              className={`h-7 gap-1.5 px-3 text-xs font-semibold ${
                simDisabled
                  ? 'bg-[var(--bg-elevated)] text-[var(--text-muted)] cursor-not-allowed'
                  : 'bg-[var(--accent-blue)] text-white hover:bg-[var(--accent-blue)]/90'
              }`}
            >
              {weekSimmed ? (
                <Check className="h-3 w-3" />
              ) : (
                <Play className="h-3 w-3" />
              )}
              {sim.isPending ? 'Simulating...' : simLabel}
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={handleAdvance}
              disabled={advance.isPending}
              title={advTooltip}
              className="h-7 gap-1.5 px-3 text-xs text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
            >
              <FastForward className="h-3 w-3" />
              {advance.isPending ? 'Advancing...' : advLabel}
            </Button>
          </>
        )}

        <div className="ml-3 flex items-center gap-2 border-l border-[var(--border)] pl-3">
          <div className="flex h-6 w-6 items-center justify-center rounded-full bg-[var(--bg-elevated)] ring-1 ring-[var(--border)]">
            <User className="h-3 w-3 text-[var(--text-secondary)]" />
          </div>
          <span className="text-xs font-medium text-[var(--text-secondary)]">{coach?.name ?? user?.username}</span>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => logout.mutate()}
            className="h-6 w-6 p-0 text-[var(--text-muted)] hover:text-[var(--accent-red)]"
          >
            <LogOut className="h-3 w-3" />
          </Button>
        </div>
      </div>
    </header>
  );
}
