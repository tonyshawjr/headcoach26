import { useState } from 'react';
import { Link } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { CheckCircle2, Circle, X, ClipboardList, Users, UserCog, CalendarDays, Rocket } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface OnboardingProps {
  hasRoster: boolean;
  hasDepthChart: boolean;
  hasStaff: boolean;
  hasGamePlan: boolean;
}

interface OnboardingStep {
  label: string;
  description: string;
  href: string;
  icon: React.ElementType;
  completed: boolean;
}

export default function Onboarding({ hasRoster, hasDepthChart, hasStaff, hasGamePlan }: OnboardingProps) {
  const [dismissed, setDismissed] = useState(() => {
    return localStorage.getItem('onboarding-dismissed') === 'true';
  });

  if (dismissed) return null;

  const handleDismiss = () => {
    localStorage.setItem('onboarding-dismissed', 'true');
    setDismissed(true);
  };

  const allComplete = hasRoster && hasDepthChart && hasStaff && hasGamePlan;

  const steps: OnboardingStep[] = [
    {
      label: 'Set up your roster',
      description: 'Import or review your team players',
      href: '/roster-import',
      icon: Users,
      completed: hasRoster,
    },
    {
      label: 'Configure your depth chart',
      description: 'Assign starters and backups at each position',
      href: '/my-team?tab=depth',
      icon: ClipboardList,
      completed: hasDepthChart,
    },
    {
      label: 'Hire coaching staff',
      description: 'Build your team of coordinators and assistants',
      href: '/coaching-staff',
      icon: UserCog,
      completed: hasStaff,
    },
    {
      label: 'Set your first game plan',
      description: 'Choose offensive and defensive schemes',
      href: '/schedule',
      icon: CalendarDays,
      completed: hasGamePlan,
    },
  ];

  const completedCount = steps.filter((s) => s.completed).length;

  return (
    <AnimatePresence>
      <motion.div
        initial={{ opacity: 0, y: -12 }}
        animate={{ opacity: 1, y: 0 }}
        exit={{ opacity: 0, y: -12 }}
        transition={{ duration: 0.35 }}
      >
        <div className="relative overflow-hidden rounded-lg border border-[var(--border)] bg-[var(--bg-surface)]">
          {/* Top accent bar */}
          <div className="h-[2px] w-full bg-gradient-to-r from-[var(--accent-blue)] via-[var(--accent-blue)] to-transparent" />

          <div className="p-5">
            {/* Header row */}
            <div className="mb-4 flex items-start justify-between">
              <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-blue)]">
                  Getting Started
                </p>
                <h3 className="mt-1 font-display text-lg tracking-tight">
                  Welcome, Coach!
                </h3>
                <p className="mt-0.5 text-xs text-[var(--text-muted)]">
                  Complete these steps to get your team ready for game day.
                </p>
              </div>
              <Button
                variant="ghost"
                size="sm"
                onClick={handleDismiss}
                className="h-7 w-7 p-0 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                aria-label="Dismiss onboarding"
              >
                <X className="h-4 w-4" />
              </Button>
            </div>

            {/* Progress bar */}
            <div className="mb-4">
              <div className="mb-1.5 flex items-center justify-between">
                <span className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                  Progress
                </span>
                <span className="text-[10px] font-semibold text-[var(--accent-blue)]">
                  {completedCount}/{steps.length}
                </span>
              </div>
              <div className="h-1.5 w-full overflow-hidden rounded-full bg-[var(--bg-primary)]">
                <motion.div
                  className="h-full rounded-full bg-[var(--accent-blue)]"
                  initial={{ width: 0 }}
                  animate={{ width: `${(completedCount / steps.length) * 100}%` }}
                  transition={{ duration: 0.5, ease: 'easeOut' }}
                />
              </div>
            </div>

            {/* Checklist */}
            <div className="space-y-1">
              {steps.map((step, i) => {
                const Icon = step.icon;
                return (
                  <motion.div
                    key={step.label}
                    initial={{ opacity: 0, x: -8 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ duration: 0.3, delay: 0.1 + i * 0.05 }}
                  >
                    <Link
                      to={step.href}
                      className={`flex items-center gap-3 rounded-md px-3 py-2.5 transition-colors ${
                        step.completed
                          ? 'opacity-60 hover:opacity-80'
                          : 'hover:bg-[var(--bg-primary)]'
                      }`}
                    >
                      {step.completed ? (
                        <CheckCircle2 className="h-5 w-5 shrink-0 text-green-500" />
                      ) : (
                        <Circle className="h-5 w-5 shrink-0 text-[var(--border)]" />
                      )}
                      <Icon className="h-4 w-4 shrink-0 text-[var(--text-muted)]" />
                      <div className="min-w-0 flex-1">
                        <p className={`text-sm font-medium ${step.completed ? 'line-through text-[var(--text-muted)]' : ''}`}>
                          {step.label}
                        </p>
                        <p className="text-[11px] text-[var(--text-muted)]">{step.description}</p>
                      </div>
                    </Link>
                  </motion.div>
                );
              })}

              {/* Final step */}
              <motion.div
                initial={{ opacity: 0, x: -8 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.3, delay: 0.1 + steps.length * 0.05 }}
                className="flex items-center gap-3 rounded-md px-3 py-2.5"
              >
                {allComplete ? (
                  <CheckCircle2 className="h-5 w-5 shrink-0 text-green-500" />
                ) : (
                  <Circle className="h-5 w-5 shrink-0 text-[var(--border)]" />
                )}
                <Rocket className="h-4 w-4 shrink-0 text-[var(--text-muted)]" />
                <div className="min-w-0 flex-1">
                  <p className={`text-sm font-medium ${allComplete ? 'text-green-500' : 'text-[var(--text-muted)]'}`}>
                    You&apos;re ready! Start the season
                  </p>
                  <p className="text-[11px] text-[var(--text-muted)]">
                    {allComplete ? 'All set -- hit Start Season on the agenda above!' : 'Complete the steps above first'}
                  </p>
                </div>
              </motion.div>
            </div>

            {/* Dismiss button at bottom */}
            <div className="mt-4 border-t border-[var(--border)] pt-3 text-center">
              <button
                onClick={handleDismiss}
                className="text-xs text-[var(--text-muted)] hover:text-[var(--text-secondary)] transition-colors"
              >
                Dismiss and don&apos;t show again
              </button>
            </div>
          </div>
        </div>
      </motion.div>
    </AnimatePresence>
  );
}
