import { useAuthStore } from '@/stores/authStore';
import { useLeagueHistory } from '@/hooks/useApi';
import { PageLayout, PageHeader, EmptyBlock } from '@/components/ui/sports-ui';
import { Badge } from '@/components/ui/badge';
import { motion } from 'framer-motion';
import { Clock, Trophy, Star, Award } from 'lucide-react';

export default function LeagueHistory() {
  const league = useAuthStore((s) => s.league);
  const { data: history, isLoading } = useLeagueHistory(league?.id);

  if (isLoading) {
    return (
      <PageLayout>
        <PageHeader title="League History" icon={Clock} accentColor="var(--accent-blue)" />
        <div className="flex h-64 items-center justify-center">
          <div className="text-center">
            <Clock className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
            <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading history...</p>
          </div>
        </div>
      </PageLayout>
    );
  }

  const seasons = history ?? [];

  return (
    <PageLayout>
      <PageHeader
        title="League History"
        icon={Clock}
        accentColor="var(--accent-blue)"
        subtitle="Timeline of past seasons"
      />

      {seasons.length === 0 ? (
        <EmptyBlock
          icon={Clock}
          title="No Completed Seasons Yet"
          description="Complete your first season to start building the history books."
        />
      ) : (
        <div className="relative">
          {/* Timeline Line */}
          <div className="absolute left-6 top-0 bottom-0 w-px bg-[var(--border)]" />

          <div className="space-y-4">
            {seasons.map((season: any, i: number) => (
              <motion.div
                key={season.year}
                initial={{ opacity: 0, x: -20 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.3, delay: i * 0.06 }}
                className="relative pl-14"
              >
                {/* Timeline Dot */}
                <div className={`absolute left-[18px] top-5 h-4 w-4 rounded-full border-2 ${
                  season.champion
                    ? 'border-[var(--accent-gold)] bg-[var(--accent-gold)]/20'
                    : season.is_current
                    ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/20'
                    : 'border-[var(--border)] bg-[var(--bg-surface)]'
                }`} />

                <div className={`rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden ${
                  season.is_current ? 'ring-1 ring-[var(--accent-blue)]/30' : ''
                }`}>
                  {/* Accent bar for champions */}
                  {season.champion && (
                    <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, var(--accent-gold), transparent 60%)' }} />
                  )}
                  <div className="p-5">
                    <div className="flex items-start justify-between gap-4">
                      <div className="flex-1">
                        <div className="flex items-center gap-2 mb-2">
                          <span className="font-display text-2xl">{season.year}</span>
                          {season.is_current && (
                            <Badge className="bg-[var(--accent-blue)]/10 text-[var(--accent-blue)] border-[var(--accent-blue)]/20 text-[10px]">
                              Current
                            </Badge>
                          )}
                        </div>

                        <div className="space-y-1.5">
                          {season.champion && (
                            <div className="flex items-center gap-2 text-sm">
                              <Trophy className="h-4 w-4 text-[var(--accent-gold)]" />
                              <span className="text-[var(--text-muted)]">Champion:</span>
                              <span className="font-semibold text-[var(--accent-gold)]">{season.champion}</span>
                            </div>
                          )}
                          {season.mvp && (
                            <div className="flex items-center gap-2 text-sm">
                              <Star className="h-4 w-4 text-yellow-400" />
                              <span className="text-[var(--text-muted)]">MVP:</span>
                              <span className="font-semibold">{season.mvp}</span>
                              {season.mvp_team && (
                                <span className="text-xs text-[var(--text-muted)]">({season.mvp_team})</span>
                              )}
                            </div>
                          )}
                          {season.coach_of_year && (
                            <div className="flex items-center gap-2 text-sm">
                              <Award className="h-4 w-4 text-purple-400" />
                              <span className="text-[var(--text-muted)]">Coach of the Year:</span>
                              <span className="font-semibold">{season.coach_of_year}</span>
                            </div>
                          )}
                          {!season.champion && !season.mvp && !season.is_current && (
                            <p className="text-xs text-[var(--text-muted)]">No award data for this season</p>
                          )}
                          {season.is_current && !season.champion && (
                            <p className="text-xs text-[var(--text-muted)]">Season in progress...</p>
                          )}
                        </div>
                      </div>

                      {season.champion && (
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-[var(--accent-gold)]/10">
                          <Trophy className="h-6 w-6 text-[var(--accent-gold)]" />
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </motion.div>
            ))}
          </div>
        </div>
      )}
    </PageLayout>
  );
}
