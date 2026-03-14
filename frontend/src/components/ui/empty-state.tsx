import type { LucideIcon } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';

interface EmptyStateProps {
  icon: LucideIcon;
  title: string;
  description: string;
  actionLabel?: string;
  actionHref?: string;
  onAction?: () => void;
  showBack?: boolean;
}

export function EmptyState({
  icon: Icon,
  title,
  description,
  actionLabel,
  actionHref,
  onAction,
  showBack = false,
}: EmptyStateProps) {
  const navigate = useNavigate();

  return (
    <div className="flex min-h-[300px] items-center justify-center p-8">
      <div className="text-center max-w-sm">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-[var(--bg-elevated)]">
          <Icon className="h-6 w-6 text-[var(--text-muted)]" />
        </div>
        <h3 className="font-display text-base text-[var(--text-primary)]">{title}</h3>
        <p className="mt-1 text-sm text-[var(--text-secondary)]">{description}</p>
        <div className="mt-4 flex items-center justify-center gap-3">
          {showBack && (
            <button
              onClick={() => navigate(-1)}
              className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-3 py-1.5 text-sm text-[var(--text-secondary)] transition-colors hover:bg-[var(--bg-elevated)]"
            >
              <ArrowLeft className="h-3.5 w-3.5" />
              Go Back
            </button>
          )}
          {actionLabel && (actionHref || onAction) && (
            <button
              onClick={() => {
                if (onAction) onAction();
                else if (actionHref) navigate(actionHref);
              }}
              className="inline-flex items-center gap-1.5 rounded-lg bg-[var(--accent-blue)] px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-[var(--accent-blue)]/90"
            >
              {actionLabel}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
