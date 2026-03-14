import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useSchedule } from '@/hooks/useApi';
import { GameCard } from '@/components/cards/GameCard';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { motion } from 'framer-motion';

export default function Schedule() {
  const { league, team } = useAuthStore();
  const { data: schedule, isLoading } = useSchedule(league?.id);
  const [view, setView] = useState<'all' | 'my'>('my');

  if (isLoading) return <p className="text-[var(--text-secondary)]">Loading schedule...</p>;

  const weeks = schedule ? Object.entries(schedule).sort(([a], [b]) => Number(a) - Number(b)) : [];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="h-5 w-[3px] rounded-full bg-[var(--accent-blue)]" />
          <h1 className="font-display text-2xl tracking-tight">Schedule</h1>
        </div>
        <Tabs value={view} onValueChange={(v) => setView(v as 'all' | 'my')}>
          <TabsList className="bg-[var(--bg-elevated)]">
            <TabsTrigger value="my" className="text-xs font-semibold uppercase tracking-wider">My Games</TabsTrigger>
            <TabsTrigger value="all" className="text-xs font-semibold uppercase tracking-wider">All Games</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      <div className="space-y-8">
        {weeks.map(([weekNum, games], wi) => {
          const filtered = view === 'my' && team
            ? games.filter((g) => g.home_team_id === team.id || g.away_team_id === team.id)
            : games;

          if (filtered.length === 0) return null;

          const isCurrent = Number(weekNum) === league?.current_week;

          return (
            <motion.div
              key={weekNum}
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, delay: wi * 0.04 }}
            >
              {/* Week header bar */}
              <div className="mb-3 flex items-center gap-3">
                <div className={`flex items-center gap-2 rounded px-2.5 py-1 ${isCurrent ? 'bg-[var(--accent-blue)]/10' : 'bg-[var(--bg-elevated)]/50'}`}>
                  <span className={`font-display text-sm uppercase tracking-wider ${isCurrent ? 'text-[var(--accent-blue)]' : 'text-[var(--text-muted)]'}`}>
                    Week {weekNum}
                  </span>
                  {isCurrent && (
                    <span className="text-[10px] font-bold uppercase tracking-widest text-[var(--accent-blue)]">
                      Current
                    </span>
                  )}
                </div>
                <div className="h-px flex-1 bg-[var(--border)]" />
              </div>

              {/* Current week guidance banner */}
              {isCurrent && (
                <div className="mb-3 rounded-md border border-[var(--accent-blue)]/20 bg-[var(--accent-blue)]/5 px-3 py-2">
                  <p className="text-xs text-[var(--accent-blue)]">
                    {filtered.some((g) => !g.is_simulated)
                      ? 'Click your game to set a game plan, then simulate from the top bar.'
                      : "All games this week are final. Click any game for the box score."}
                  </p>
                </div>
              )}

              <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
                {filtered.map((g) => (
                  <GameCard key={g.id} game={g} myTeamId={team?.id} />
                ))}
              </div>
            </motion.div>
          );
        })}
      </div>
    </div>
  );
}
