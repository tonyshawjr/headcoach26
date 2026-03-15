import { useOffseasonStatus, useAdvanceWeek } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { toast } from 'sonner';
import {
  Trophy, UserPlus, GraduationCap, ClipboardList, Shield,
  TrendingUp, Scissors, Star, Calendar, ChevronRight,
  FastForward, FileText, Search, ArrowRightLeft,
  Check, Lock, Loader2,
} from 'lucide-react';

/* ─── Phase icon mapping ─── */
function phaseIcon(id: string) {
  const map: Record<string, React.ElementType> = {
    awards: Trophy,
    franchise_tag: Shield,
    re_sign: ClipboardList,
    combine: Search,
    free_agency_1: UserPlus,
    free_agency_2: UserPlus,
    free_agency_3: UserPlus,
    free_agency_4: UserPlus,
    pre_draft: FileText,
    draft: GraduationCap,
    udfa: Star,
    roster_cuts: Scissors,
    development: TrendingUp,
    hall_of_fame: Trophy,
    new_season: Calendar,
  };
  return map[id] ?? Calendar;
}

/* ─── Phase accent color ─── */
function phaseAccent(id: string): string {
  if (id.startsWith('free_agency')) return '#2188FF';
  if (id === 'draft' || id === 'pre_draft') return '#8b5cf6';
  if (id === 'awards' || id === 'hall_of_fame') return 'var(--accent-gold)';
  if (id === 're_sign' || id === 'franchise_tag') return '#f59e0b';
  if (id === 'combine') return '#06b6d4';
  if (id === 'udfa') return '#10b981';
  if (id === 'roster_cuts') return '#ef4444';
  if (id === 'development') return '#22c55e';
  if (id === 'new_season') return '#22c55e';
  return 'var(--accent-blue)';
}

/* ─── Available actions per phase ─── */
function phaseActions(phaseId: string | null): { label: string; to: string; icon: React.ElementType; accent: string }[] {
  switch (phaseId) {
    case 'franchise_tag':
      return [
        { label: 'View My Roster', to: '/my-team', icon: ClipboardList, accent: '#f59e0b' },
      ];
    case 're_sign':
      return [
        { label: 'Contract Planner', to: '/contract-planner', icon: ClipboardList, accent: '#f59e0b' },
        { label: 'My Roster', to: '/my-team', icon: ClipboardList, accent: 'var(--accent-blue)' },
      ];
    case 'combine':
      return [
        { label: 'Scout Prospects', to: '/draft', icon: Search, accent: '#06b6d4' },
        { label: 'My Roster', to: '/my-team', icon: ClipboardList, accent: 'var(--accent-blue)' },
      ];
    case 'free_agency_1':
    case 'free_agency_2':
    case 'free_agency_3':
    case 'free_agency_4':
      return [
        { label: 'Free Agent Market', to: '/free-agency', icon: UserPlus, accent: '#2188FF' },
        { label: 'Scout Prospects', to: '/draft', icon: Search, accent: '#8b5cf6' },
        { label: 'Salary Cap', to: '/salary-cap', icon: ClipboardList, accent: '#f59e0b' },
      ];
    case 'pre_draft':
      return [
        { label: 'Draft Room', to: '/draft', icon: GraduationCap, accent: '#8b5cf6' },
        { label: 'Trade Picks', to: '/trades', icon: ArrowRightLeft, accent: '#f59e0b' },
        { label: 'Free Agents', to: '/free-agency', icon: UserPlus, accent: '#2188FF' },
      ];
    case 'draft':
      return [
        { label: 'Enter Draft Room', to: '/draft', icon: GraduationCap, accent: '#8b5cf6' },
      ];
    case 'udfa':
      return [
        { label: 'Browse UDFAs', to: '/free-agency', icon: Star, accent: '#10b981' },
        { label: 'My Roster', to: '/my-team', icon: ClipboardList, accent: 'var(--accent-blue)' },
      ];
    case 'roster_cuts':
      return [
        { label: 'Manage Roster', to: '/my-team', icon: Scissors, accent: '#ef4444' },
        { label: 'Free Agents', to: '/free-agency', icon: UserPlus, accent: '#2188FF' },
      ];
    case 'development':
    case 'hall_of_fame':
      return [
        { label: 'Offseason Report', to: '/offseason-report', icon: TrendingUp, accent: '#22c55e' },
      ];
    case 'new_season':
      return [
        { label: 'View Schedule', to: '/schedule', icon: Calendar, accent: '#22c55e' },
        { label: 'Set Depth Chart', to: '/my-team?tab=depth', icon: ClipboardList, accent: 'var(--accent-blue)' },
      ];
    default:
      return [];
  }
}

/* ─── Dedupe phases by week for timeline display ─── */
type TimelineWeek = { week: number; label: string; phases: string[]; short: string };
function buildTimeline(phases: { id: string; week: number; label: string; short: string }[]): TimelineWeek[] {
  const byWeek = new Map<number, TimelineWeek>();
  for (const p of phases) {
    const existing = byWeek.get(p.week);
    if (existing) {
      existing.phases.push(p.id);
    } else {
      byWeek.set(p.week, { week: p.week, label: p.label, phases: [p.id], short: p.short });
    }
  }
  return Array.from(byWeek.values()).sort((a, b) => a.week - b.week);
}

export default function OffseasonHub() {
  const { league } = useAuthStore();
  const { data: status, isLoading } = useOffseasonStatus();
  const advance = useAdvanceWeek(league?.id ?? 0);

  const handleAdvance = async () => {
    try {
      const result = await advance.mutateAsync();
      if (result.phase === 'preseason') {
        toast.success('Welcome to the new season!');
      } else {
        const phaseName = (result.offseason?.phase as string) ?? 'next phase';
        toast.success(result.message ?? `Advanced to ${phaseName}`);
      }
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Advance failed');
    }
  };

  if (isLoading || !status) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Loader2 className="h-6 w-6 animate-spin text-[var(--text-muted)]" />
      </div>
    );
  }

  const currentPhase = status.offseason_phase;
  const weekNum = status.week_number || 0;
  const totalWeeks = status.total_weeks || 13;
  const timeline = buildTimeline(status.phases);
  const actions = phaseActions(currentPhase);
  const accent = phaseAccent(currentPhase ?? '');
  const CurrentIcon = phaseIcon(currentPhase ?? '');

  return (
    <div className="space-y-6">
      {/* ─── Header ─── */}
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div
              className="flex h-10 w-10 items-center justify-center rounded-lg"
              style={{ backgroundColor: `${accent}15` }}
            >
              <CurrentIcon className="h-5 w-5" style={{ color: accent }} />
            </div>
            <div>
              <h1 className="font-display text-2xl">Offseason</h1>
              <p className="text-sm text-[var(--text-secondary)]">
                Week {weekNum} of {totalWeeks} — {status.week_label}
              </p>
            </div>
          </div>
          <button
            onClick={handleAdvance}
            disabled={advance.isPending}
            className="inline-flex h-10 items-center gap-2 rounded-lg bg-[#2188FF] px-5 text-sm font-bold text-white hover:bg-[#2188FF]/90 disabled:opacity-50 transition-colors"
          >
            <FastForward className="h-4 w-4" />
            {advance.isPending ? 'Advancing...' : 'Advance Week'}
          </button>
        </div>
      </motion.div>

      {/* ─── Progress bar ─── */}
      <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4">
        <div className="flex items-center justify-between mb-2">
          <span className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
            Progress
          </span>
          <span className="text-xs text-[var(--text-muted)]">
            {weekNum}/{totalWeeks}
          </span>
        </div>
        <div className="h-2 rounded-full bg-[var(--bg-elevated)] overflow-hidden">
          <motion.div
            className="h-full rounded-full"
            style={{ backgroundColor: accent }}
            initial={{ width: 0 }}
            animate={{ width: `${Math.max(5, (weekNum / totalWeeks) * 100)}%` }}
            transition={{ duration: 0.6, ease: 'easeOut' }}
          />
        </div>
      </div>

      {/* ─── Current Phase Card ─── */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3, delay: 0.1 }}
        className="rounded-xl border-2 bg-[var(--bg-surface)] p-6"
        style={{ borderColor: `${accent}40` }}
      >
        <div className="flex items-start gap-4">
          <div
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl"
            style={{ backgroundColor: `${accent}20` }}
          >
            <CurrentIcon className="h-6 w-6" style={{ color: accent }} />
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-1">
              <span
                className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full"
                style={{ backgroundColor: `${accent}20`, color: accent }}
              >
                Week {weekNum}
              </span>
            </div>
            <h2 className="font-display text-lg mb-1">{status.week_label}</h2>
            <p className="text-sm text-[var(--text-secondary)]">{status.summary}</p>

            {/* Pending actions */}
            {status.pending_actions.length > 0 && (
              <div className="mt-3 space-y-1">
                {status.pending_actions.map((pa, i) => (
                  <div key={i} className="flex items-center gap-2 text-sm text-[var(--accent-gold)]">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-[var(--accent-gold)]/15 text-[10px] font-bold">
                      {pa.count}
                    </span>
                    {pa.message}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Action links */}
        {actions.length > 0 && (
          <div className="mt-5 grid gap-2 sm:grid-cols-3">
            {actions.map((action, i) => {
              const Icon = action.icon;
              return (
                <Link
                  key={i}
                  to={action.to}
                  className="flex items-center gap-3 rounded-lg border border-[var(--border)] px-4 py-3 hover:bg-[var(--bg-elevated)] transition-colors group"
                >
                  <div
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                    style={{ backgroundColor: `${action.accent}15` }}
                  >
                    <Icon className="h-4 w-4" style={{ color: action.accent }} />
                  </div>
                  <span className="text-sm font-semibold text-[var(--text-primary)]">{action.label}</span>
                  <ChevronRight className="h-4 w-4 text-[var(--text-muted)] ml-auto opacity-0 group-hover:opacity-100 transition-opacity" />
                </Link>
              );
            })}
          </div>
        )}
      </motion.div>

      {/* ─── Timeline ─── */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3, delay: 0.2 }}
        className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden"
      >
        <div className="px-5 py-3 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
          <h3 className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">
            Offseason Timeline
          </h3>
        </div>
        <div className="divide-y divide-[var(--border)]">
          {timeline.map((tw) => {
            const isCurrent = tw.week === weekNum;
            const isComplete = tw.week < weekNum;
            const isFuture = tw.week > weekNum;
            const weekAccent = phaseAccent(tw.phases[0]);
            const WeekIcon = phaseIcon(tw.phases[0]);

            return (
              <div
                key={tw.week}
                className={`flex items-center gap-4 px-5 py-3.5 transition-colors ${
                  isCurrent ? 'bg-[var(--bg-elevated)]' : ''
                } ${isFuture ? 'opacity-50' : ''}`}
              >
                {/* Status indicator */}
                <div className="flex h-8 w-8 shrink-0 items-center justify-center">
                  {isComplete ? (
                    <div className="flex h-7 w-7 items-center justify-center rounded-full bg-green-500/20">
                      <Check className="h-4 w-4 text-green-400" />
                    </div>
                  ) : isCurrent ? (
                    <div
                      className="flex h-7 w-7 items-center justify-center rounded-full ring-2"
                      style={{ backgroundColor: `${weekAccent}20`, ringColor: weekAccent }}
                    >
                      <WeekIcon className="h-3.5 w-3.5" style={{ color: weekAccent }} />
                    </div>
                  ) : (
                    <div className="flex h-7 w-7 items-center justify-center rounded-full bg-[var(--bg-elevated)]">
                      <Lock className="h-3 w-3 text-[var(--text-muted)]" />
                    </div>
                  )}
                </div>

                {/* Week info */}
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <span className={`text-[10px] font-bold uppercase tracking-wider ${
                      isCurrent ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'
                    }`}>
                      Week {tw.week}
                    </span>
                    {isCurrent && (
                      <span
                        className="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full"
                        style={{ backgroundColor: `${weekAccent}20`, color: weekAccent }}
                      >
                        Current
                      </span>
                    )}
                  </div>
                  <p className={`text-sm ${
                    isCurrent ? 'font-semibold text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'
                  }`}>
                    {tw.label}
                  </p>
                </div>

                {/* Week number badge */}
                {isComplete && (
                  <span className="text-xs text-green-400 font-medium">Done</span>
                )}
                {isCurrent && (
                  <span className="text-xs font-bold" style={{ color: weekAccent }}>In Progress</span>
                )}
              </div>
            );
          })}
        </div>
      </motion.div>
    </div>
  );
}
