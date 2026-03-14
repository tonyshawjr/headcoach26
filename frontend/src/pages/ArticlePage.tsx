import { useParams } from 'react-router-dom';
import { useArticle } from '@/hooks/useApi';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { FileText } from 'lucide-react';

export default function ArticlePage() {
  const { id } = useParams<{ id: string }>();
  const { data: article, isLoading, isError } = useArticle(Number(id));

  if (isLoading) {
    return (
      <div className="mx-auto max-w-3xl">
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="p-8">
            <div className="animate-pulse space-y-4">
              <div className="h-4 w-24 rounded bg-[var(--bg-elevated)]" />
              <div className="h-8 w-3/4 rounded bg-[var(--bg-elevated)]" />
              <div className="h-3 w-32 rounded bg-[var(--bg-elevated)]" />
              <div className="mt-6 space-y-3">
                <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
                <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
                <div className="h-3 w-2/3 rounded bg-[var(--bg-elevated)]" />
              </div>
            </div>
          </CardContent>
        </Card>
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
        actionLabel="League Hub"
        actionHref="/league-hub"
      />
    );
  }

  return (
    <div className="mx-auto max-w-3xl">
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardContent className="p-8">
          <div className="mb-4 flex items-center gap-2">
            {article.type && <Badge variant="outline">{article.type.replace(/_/g, ' ')}</Badge>}
            {article.week != null && <span className="text-xs text-[var(--text-muted)]">Week {article.week}</span>}
          </div>
          <h1 className="font-display text-2xl leading-tight">{article.headline}</h1>
          {article.author_name && <p className="mt-2 text-xs text-[var(--text-secondary)]">By {article.author_name}</p>}
          <div className="mt-6 space-y-4 text-sm leading-relaxed text-[var(--text-secondary)]">
            {(article.body ?? '').split('\n\n').map((p: string, i: number) => (
              <p key={i}>{p}</p>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
