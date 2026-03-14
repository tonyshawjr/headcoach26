import { Card } from '@/components/ui/card';
import { useNavigate } from 'react-router-dom';
import type { Article } from '@/api/client';

const typeConfig: Record<string, { label: string; color: string }> = {
  game_recap: { label: 'RECAP', color: 'var(--accent-blue)' },
  power_rankings: { label: 'RANKINGS', color: 'var(--accent-gold)' },
  feature: { label: 'FEATURE', color: 'var(--accent-red)' },
  column: { label: 'COLUMN', color: '#8b5cf6' },
  morning_blitz: { label: 'BLITZ', color: 'var(--accent-gold)' },
};

export function ArticleCard({ article }: { article: Article }) {
  const navigate = useNavigate();
  const config = typeConfig[article.type] ?? { label: (article.type ?? 'NEWS').toUpperCase(), color: 'var(--text-muted)' };

  return (
    <Card
      className="group cursor-pointer overflow-hidden border-[var(--border)] bg-[var(--bg-surface)] transition-all hover:bg-[var(--bg-elevated)]"
      onClick={() => navigate(`/article/${article.id}`)}
    >
      <div className="flex">
        {/* Left accent bar */}
        <div className="w-[3px] shrink-0" style={{ backgroundColor: config.color }} />

        <div className="flex-1 p-4">
          <div className="flex items-center gap-2.5 mb-1.5">
            <span
              className="text-[10px] font-bold uppercase tracking-[0.15em]"
              style={{ color: config.color }}
            >
              {config.label}
            </span>
            {article.week != null && (
              <>
                <div className="h-1 w-1 rounded-full bg-[var(--text-muted)]" />
                <span className="text-[10px] font-medium text-[var(--text-muted)]">Week {article.week}</span>
              </>
            )}
          </div>
          <h3 className="font-display text-[15px] leading-snug tracking-tight group-hover:text-[var(--accent-blue)] transition-colors">
            {article.headline}
          </h3>
          <p className="mt-1.5 text-xs leading-relaxed text-[var(--text-secondary)] line-clamp-2">
            {(article.body ?? '').substring(0, 180)}...
          </p>
          {article.author_name && (
            <p className="mt-2 text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
              {article.author_name}
            </p>
          )}
        </div>
      </div>
    </Card>
  );
}
