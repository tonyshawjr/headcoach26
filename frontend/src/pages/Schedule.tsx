import { useMemo, useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useSchedule } from '@/hooks/useApi';
import { GameCard } from '@/components/cards/GameCard';
import { PlayoffBracket } from '@/components/PlayoffBracket';
import { PageLayout, PageHeader, SportsTabs, Section, EmptyBlock } from '@/components/ui/sports-ui';
import { motion } from 'framer-motion';
import { CalendarDays } from 'lucide-react';

export default function Schedule() {
  const { league, team } = useAuthStore();
  const { data: schedule, isLoading } = useSchedule(league?.id);
  const [view, setView] = useState<'all' | 'my' | 'bracket'>('my');

  // Detect whether playoff games exist in the schedule data
  const { hasPlayoffGames, playoffGames } = useMemo(() => {
    if (!schedule) return { hasPlayoffGames: false, playoffGames: [] };

    const allGames = Object.values(schedule).flat();
    const pGames = allGames.filter(
      (g) => (g.game_type && g.game_type !== 'regular') || g.week >= 19
    );
    return { hasPlayoffGames: pGames.length > 0, playoffGames: pGames };
  }, [schedule]);

  // Build tabs array (conditionally include bracket)
  const tabs = useMemo(() => {
    const t = [
      { key: 'my', label: 'My Games' },
      { key: 'all', label: 'All Games' },
    ];
    if (hasPlayoffGames) {
      t.push({ key: 'bracket', label: 'Bracket' });
    }
    return t;
  }, [hasPlayoffGames]);

  // Build meta string for PageHeader
  const metaLine = league
    ? `Week ${league.current_week} / ${league.phase === 'regular' ? 'Regular Season' : league.phase === 'playoffs' ? 'Playoffs' : league.phase ?? 'Season'}`
    : undefined;

  if (isLoading) {
    return (
      <PageLayout>
        <PageHeader title="Schedule" icon={CalendarDays} meta={metaLine} />
        <EmptyBlock title="Loading schedule..." description="Fetching games from the league office." />
      </PageLayout>
    );
  }

  const weeks = schedule ? Object.entries(schedule).sort(([a], [b]) => Number(a) - Number(b)) : [];

  return (
    <PageLayout>
      <PageHeader
        title="Schedule"
        icon={CalendarDays}
        meta={metaLine}
        actions={
          <SportsTabs
            tabs={tabs}
            activeTab={view}
            onChange={(key) => setView(key as typeof view)}
            variant="pills"
          />
        }
      />

      {/* Playoff Bracket view */}
      {view === 'bracket' && hasPlayoffGames && (
        <motion.div
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.3 }}
        >
          <PlayoffBracket games={playoffGames} userTeamId={team?.id} />
        </motion.div>
      )}

      {/* Schedule list view */}
      {view !== 'bracket' && (
        <div className="space-y-8">
          {weeks.map(([weekNum, games], wi) => {
            const filtered = view === 'my' && team
              ? games.filter((g) => g.home_team_id === team.id || g.away_team_id === team.id)
              : games;

            if (filtered.length === 0) return null;

            const isCurrent = Number(weekNum) === league?.current_week;

            // Determine the week label for playoff weeks
            const weekNumber = Number(weekNum);
            const isPlayoffWeek = filtered.some(
              (g) => (g.game_type && g.game_type !== 'regular') || weekNumber >= 19
            );
            const playoffLabel = isPlayoffWeek
              ? weekNumber === 19
                ? 'Wild Card'
                : weekNumber === 20
                  ? 'Divisional'
                  : weekNumber === 21
                    ? 'Conference Championship'
                    : weekNumber === 22
                      ? 'The Big Game'
                      : `Week ${weekNum}`
              : null;

            const weekTitle = playoffLabel ?? `Week ${weekNum}`;
            const sectionAccent = isCurrent
              ? 'var(--accent-blue)'
              : isPlayoffWeek
                ? 'var(--accent-gold)'
                : undefined;

            return (
              <Section
                key={weekNum}
                title={isCurrent ? `${weekTitle} — Current` : weekTitle}
                accentColor={sectionAccent}
                delay={wi * 0.04}
              >
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
              </Section>
            );
          })}
        </div>
      )}
    </PageLayout>
  );
}
