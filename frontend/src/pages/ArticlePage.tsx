import { useParams, Link } from 'react-router-dom';
import { useArticle, useArticles } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { ArticleHeroImage } from '@/components/ArticleHeroImage';
import { EmptyState } from '@/components/ui/empty-state';
import { FileText, ArrowLeft } from 'lucide-react';
import { weekLabel } from '@/lib/weekLabel';
import type { Article } from '@/api/client';

const typeConfig: Record<string, { label: string; color: string }> = {
  game_recap: { label: 'RECAP', color: 'var(--accent-blue)' },
  playoff_recap: { label: 'PLAYOFFS', color: 'var(--accent-red)' },
  power_rankings: { label: 'RANKINGS', color: 'var(--accent-gold)' },
  feature: { label: 'FEATURE', color: 'var(--accent-red)' },
  column: { label: 'COLUMN', color: '#8b5cf6' },
  morning_blitz: { label: 'BLITZ', color: 'var(--accent-gold)' },
  draft_coverage: { label: 'DRAFT', color: '#10b981' },
  trade_story: { label: 'TRADE', color: '#f97316' },
  free_agency: { label: 'FREE AGENCY', color: '#06b6d4' },
  awards: { label: 'AWARDS', color: 'var(--accent-gold)' },
  milestone: { label: 'MILESTONE', color: '#8b5cf6' },
};

export default function ArticlePage() {
  const { id } = useParams<{ id: string }>();
  const league = useAuthStore((s) => s.league);
  const { data: article, isLoading, isError } = useArticle(Number(id));
  const { data: recentResp } = useArticles(league?.id, { page: 1 });

  if (isLoading) {
    return (
      <div className="mx-auto max-w-5xl">
        <div className="animate-pulse space-y-6">
          <div className="h-[300px] rounded-xl bg-[var(--bg-elevated)]" />
          <div className="h-8 w-3/4 rounded bg-[var(--bg-elevated)]" />
          <div className="h-3 w-32 rounded bg-[var(--bg-elevated)]" />
          <div className="space-y-3 mt-8">
            <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
            <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
            <div className="h-3 w-2/3 rounded bg-[var(--bg-elevated)]" />
          </div>
        </div>
      </div>
    );
  }

  if (isError || !article) {
    return (
      <EmptyState
        icon={FileText}
        title="Article not found"
        description="This article may have been removed or doesn't exist."
        showBack
        actionLabel="The Hub"
        actionHref="/league-hub"
      />
    );
  }

  const config = typeConfig[article.type] ?? { label: (article.type ?? 'NEWS').toUpperCase(), color: 'var(--text-muted)' };
  const wk = article.week != null ? weekLabel(article.week) : null;

  // Get related articles (same type or same week, excluding current)
  const allRecent = recentResp?.articles ?? [];
  const related = allRecent
    .filter((a) => a.id !== article.id)
    .filter((a) => a.type === article.type || a.week === article.week)
    .slice(0, 5);

  // If not enough related, fill with any recent
  const sidebar = related.length >= 3
    ? related
    : [
        ...related,
        ...allRecent.filter((a) => a.id !== article.id && !related.find((r) => r.id === a.id)).slice(0, 5 - related.length),
      ];

  return (
    <div className="mx-auto max-w-5xl">
      {/* Back link */}
      <Link to="/league-hub" className="inline-flex items-center gap-1.5 text-sm text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors mb-4">
        <ArrowLeft className="h-4 w-4" />
        Back to The Hub
      </Link>

      <div className="grid gap-8 lg:grid-cols-[1fr_280px]">
        {/* Main article */}
        <div>
          {/* Hero banner */}
          <div className="relative overflow-hidden rounded-xl min-h-[200px] sm:min-h-[260px] flex flex-col justify-end mb-8">
            <ArticleHeroImage
              teamId={article.team_id}
              articleType={article.type}
              articleId={article.id}
            />
            <div className="relative z-10 p-6 sm:p-8">
              <span
                className="inline-block rounded px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.15em] mb-3"
                style={{ backgroundColor: config.color, color: '#fff' }}
              >
                {config.label}
              </span>
              {wk && (
                <span className="ml-2 text-[11px] text-white/50">{wk}</span>
              )}
            </div>
          </div>

          {/* Headline */}
          <h1 className="font-display text-3xl sm:text-4xl leading-[1.1] tracking-tight text-[var(--text-primary)] mb-4">
            {article.headline}
          </h1>

          {/* Byline */}
          <div className="flex items-center gap-3 mb-8 pb-6 border-b border-[var(--border)]">
            {article.author_name && (
              <p className="text-sm font-semibold text-[var(--text-primary)]">
                By {article.author_name}
              </p>
            )}
            {wk && (
              <span className="text-xs text-[var(--text-muted)]">{wk}</span>
            )}
          </div>

          {/* Article body */}
          <div className="prose-custom">
            {(article.body ?? '').split('\n\n').map((paragraph: string, i: number) => {
              // Handle markdown-style bold
              const formatted = paragraph.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
              // Handle lines that start with a number and period (mock draft picks, etc.)
              const isListItem = /^\d+\./.test(paragraph.trim());

              if (isListItem) {
                return (
                  <div
                    key={i}
                    className="text-[15px] leading-relaxed text-[var(--text-secondary)] mb-4 pl-2 border-l-2 border-[var(--border)]"
                    dangerouslySetInnerHTML={{ __html: formatted.replace(/\n/g, '<br/>') }}
                  />
                );
              }

              return (
                <p
                  key={i}
                  className="text-[15px] leading-[1.8] text-[var(--text-secondary)] mb-5"
                  dangerouslySetInnerHTML={{ __html: formatted }}
                />
              );
            })}
          </div>
        </div>

        {/* Sidebar — more stories */}
        <aside className="hidden lg:block">
          <div className="sticky top-20">
            <h3 className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)] mb-4">
              More Stories
            </h3>
            <div className="space-y-0">
              {sidebar.map((a) => (
                <SidebarArticle key={a.id} article={a} />
              ))}
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}

function SidebarArticle({ article }: { article: Article }) {
  const config = typeConfig[article.type] ?? { label: 'NEWS', color: 'var(--text-muted)' };

  return (
    <Link to={`/article/${article.id}`} className="group flex gap-3 py-3 border-b border-[var(--border)] last:border-0">
      {/* Accent bar */}
      <div className="w-[3px] shrink-0 rounded-full" style={{ backgroundColor: config.color }} />

      <div className="min-w-0 flex-1">
        <span
          className="text-[8px] font-bold uppercase tracking-[0.15em]"
          style={{ color: config.color }}
        >
          {config.label}
        </span>
        <h4 className="text-[13px] font-semibold leading-snug text-[var(--text-primary)] group-hover:text-[var(--accent-blue)] transition-colors line-clamp-2 mt-0.5">
          {article.headline}
        </h4>
        <div className="mt-1 flex items-center gap-2 text-[10px] text-[var(--text-muted)]">
          {article.author_name && <span>{article.author_name}</span>}
          {article.week != null && (
            <>
              <span className="opacity-40">&bull;</span>
              <span>{weekLabel(article.week)}</span>
            </>
          )}
        </div>
      </div>
    </Link>
  );
}
