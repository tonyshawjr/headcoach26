import { useAchievements } from '@/hooks/useApi';
import { PageLayout, PageHeader, EmptyBlock } from '@/components/ui/sports-ui';
import { motion } from 'framer-motion';
import {
  Award, Trophy, Flame, Target, Crown, ArrowLeftRight,
  TrendingUp, Swords, Medal, Lock,
} from 'lucide-react';

const ICON_MAP: Record<string, React.ElementType> = {
  Trophy,
  Flame,
  Target,
  Crown,
  ArrowLeftRight,
  TrendingUp,
  Swords,
  Medal,
};

export default function Achievements() {
  const { data: achievements, isLoading } = useAchievements();

  if (isLoading) {
    return (
      <PageLayout>
        <PageHeader title="Achievements" icon={Award} accentColor="var(--accent-gold)" />
        <div className="flex h-64 items-center justify-center">
          <div className="text-center">
            <Award className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-gold)]" />
            <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading achievements...</p>
          </div>
        </div>
      </PageLayout>
    );
  }

  const items = achievements ?? [];
  const unlockedCount = items.filter((a) => a.unlocked).length;

  return (
    <PageLayout>
      <PageHeader
        title="Achievements"
        icon={Award}
        accentColor="var(--accent-gold)"
        subtitle={`${unlockedCount} of ${items.length} unlocked`}
      />

      {/* Progress Bar */}
      <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-4 mb-6">
        <div className="flex items-center justify-between mb-2">
          <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">
            Progress
          </span>
          <span className="font-stat text-sm text-[var(--accent-gold)]">
            {items.length > 0 ? Math.round((unlockedCount / items.length) * 100) : 0}%
          </span>
        </div>
        <div className="h-2 overflow-hidden rounded-full bg-white/10">
          <motion.div
            className="h-full rounded-full bg-gradient-to-r from-[var(--accent-gold)] to-yellow-500"
            initial={{ width: 0 }}
            animate={{ width: items.length > 0 ? `${(unlockedCount / items.length) * 100}%` : '0%' }}
            transition={{ duration: 0.8, delay: 0.3 }}
          />
        </div>
      </div>

      {/* Achievement Grid */}
      {items.length > 0 ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {items.map((achievement, i) => {
            const Icon = ICON_MAP[achievement.icon] ?? Trophy;

            return (
              <motion.div
                key={achievement.id}
                initial={{ opacity: 0, scale: 0.9 }}
                animate={{ opacity: 1, scale: 1 }}
                transition={{ duration: 0.3, delay: i * 0.05 }}
              >
                <div className={`relative overflow-hidden rounded-lg border border-[var(--border)] transition-all ${
                  achievement.unlocked
                    ? 'bg-[var(--bg-surface)] hover:border-[var(--accent-gold)]/40'
                    : 'bg-[var(--bg-surface)] opacity-60'
                }`}>
                  {/* Top accent stripe for unlocked */}
                  {achievement.unlocked && (
                    <div className="h-[3px] w-full bg-gradient-to-r from-[var(--accent-gold)] to-yellow-500" />
                  )}

                  <div className="p-5">
                    <div className="flex items-start gap-4">
                      {/* Icon */}
                      <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${
                        achievement.unlocked
                          ? 'bg-[var(--accent-gold)]/10 border border-[var(--accent-gold)]/30'
                          : 'bg-white/5 border border-white/10'
                      }`}>
                        {achievement.unlocked ? (
                          <Icon className="h-6 w-6 text-[var(--accent-gold)]" />
                        ) : (
                          <Lock className="h-5 w-5 text-[var(--text-muted)]" />
                        )}
                      </div>

                      {/* Text */}
                      <div className="flex-1 min-w-0">
                        <h3 className={`font-display text-sm ${
                          achievement.unlocked ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'
                        }`}>
                          {achievement.name}
                        </h3>
                        <p className={`text-xs mt-0.5 ${
                          achievement.unlocked ? 'text-[var(--text-secondary)]' : 'text-[var(--text-muted)]'
                        }`}>
                          {achievement.desc}
                        </p>
                      </div>
                    </div>

                    {/* Unlocked indicator */}
                    {achievement.unlocked && (
                      <div className="absolute top-3 right-3">
                        <div className="flex h-5 w-5 items-center justify-center rounded-full bg-green-500/20">
                          <div className="h-2 w-2 rounded-full bg-green-400" />
                        </div>
                      </div>
                    )}
                  </div>

                  {/* Bottom accent bar for unlocked */}
                  {achievement.unlocked && (
                    <div className="h-0.5 bg-gradient-to-r from-[var(--accent-gold)] to-yellow-500" />
                  )}
                </div>
              </motion.div>
            );
          })}
        </div>
      ) : (
        <EmptyBlock
          icon={Award}
          title="No Achievements Available"
          description="Achievements will appear as you progress through your franchise."
        />
      )}
    </PageLayout>
  );
}
