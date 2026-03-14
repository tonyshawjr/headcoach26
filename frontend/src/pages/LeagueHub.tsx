import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useArticles, useSocial, usePowerRankings } from '@/hooks/useApi';
import { ArticleCard } from '@/components/cards/ArticleCard';
import { SocialPostCard } from '@/components/cards/SocialPostCard';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { TeamBadge } from '@/components/TeamBadge';
import { EmptyState } from '@/components/ui/empty-state';
import { Newspaper } from 'lucide-react';

type TabValue = 'news' | 'recaps' | 'features' | 'columns' | 'morning_blitz' | 'social' | 'rankings';

const tabs: { value: TabValue; label: string }[] = [
  { value: 'news', label: 'All News' },
  { value: 'recaps', label: 'Recaps' },
  { value: 'features', label: 'Features' },
  { value: 'columns', label: 'Columns' },
  { value: 'morning_blitz', label: 'Morning Blitz' },
  { value: 'social', label: 'GridironX' },
  { value: 'rankings', label: 'Power Rankings' },
];

export default function LeagueHub() {
  const league = useAuthStore((s) => s.league);
  const [articlePage, setArticlePage] = useState(1);
  const [activeTab, setActiveTab] = useState<TabValue>('news');

  const { data: articlesResp, isLoading: articlesLoading, isError: articlesError } = useArticles(league?.id, { page: articlePage });
  const { data: social, isLoading: socialLoading } = useSocial(league?.id);
  const { data: rankings } = usePowerRankings(league?.id);

  const articles = articlesResp?.articles ?? [];

  function filteredArticles(type: string) {
    return articles.filter((a) => a.type === type);
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-2xl">League Hub</h1>
        <p className="text-sm text-[var(--text-secondary)]">
          News, social media, and rankings
          {articlesLoading && ' — Loading articles...'}
          {articlesError && ' — Error loading articles'}
          {!articlesLoading && !articlesError && ` — ${articles.length} articles loaded`}
        </p>
      </div>

      {/* Tab buttons */}
      <div className="flex flex-wrap gap-1 rounded-lg bg-[var(--bg-surface)] p-1">
        {tabs.map((t) => (
          <button
            key={t.value}
            onClick={() => setActiveTab(t.value)}
            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
              activeTab === t.value
                ? 'bg-[var(--bg-elevated)] text-[var(--text-primary)] shadow-sm'
                : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Tab content */}
      <div className="space-y-3">
        {/* Loading state */}
        {(activeTab !== 'social' && activeTab !== 'rankings' && articlesLoading) && (
          <div className="space-y-3">
            {[1, 2, 3].map((i) => (
              <Card key={i} className="border-[var(--border)] bg-[var(--bg-surface)] p-4">
                <div className="animate-pulse space-y-2">
                  <div className="h-3 w-20 rounded bg-[var(--bg-elevated)]" />
                  <div className="h-5 w-3/4 rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
                </div>
              </Card>
            ))}
          </div>
        )}
        {activeTab === 'social' && socialLoading && (
          <p className="text-sm text-[var(--text-secondary)]">Loading social posts...</p>
        )}

        {/* All News */}
        {activeTab === 'news' && !articlesLoading && (
          <>
            {articles.length === 0 ? (
              <EmptyState
                icon={Newspaper}
                title="No articles yet"
                description="Play some games to generate news coverage, recaps, and columns."
                actionLabel="Go to Schedule"
                actionHref="/schedule"
              />
            ) : (
              articles.map((a) => <ArticleCard key={a.id} article={a} />)
            )}
            {articlesResp && articlesResp.pages > 1 && (
              <div className="flex justify-center gap-2">
                <Button size="sm" variant="outline" disabled={articlePage <= 1} onClick={() => setArticlePage((p) => p - 1)}>Prev</Button>
                <span className="text-sm text-[var(--text-secondary)] self-center">Page {articlePage} of {articlesResp.pages}</span>
                <Button size="sm" variant="outline" disabled={articlePage >= articlesResp.pages} onClick={() => setArticlePage((p) => p + 1)}>Next</Button>
              </div>
            )}
          </>
        )}

        {/* Recaps */}
        {activeTab === 'recaps' && !articlesLoading && (
          filteredArticles('game_recap').length === 0 ? (
            <EmptyState icon={Newspaper} title="No game recaps yet" description="Recaps are generated after games are simulated." />
          ) : (
            filteredArticles('game_recap').map((a) => <ArticleCard key={a.id} article={a} />)
          )
        )}

        {/* Features */}
        {activeTab === 'features' && !articlesLoading && (
          filteredArticles('feature').length === 0 ? (
            <EmptyState icon={Newspaper} title="No feature articles yet" description="Feature stories appear as the season progresses." />
          ) : (
            filteredArticles('feature').map((a) => <ArticleCard key={a.id} article={a} />)
          )
        )}

        {/* Columns */}
        {activeTab === 'columns' && !articlesLoading && (
          filteredArticles('column').length === 0 ? (
            <EmptyState icon={Newspaper} title="No columns yet" description="Columnist opinions appear as the season progresses." />
          ) : (
            filteredArticles('column').map((a) => <ArticleCard key={a.id} article={a} />)
          )
        )}

        {/* Morning Blitz */}
        {activeTab === 'morning_blitz' && !articlesLoading && (
          filteredArticles('morning_blitz').length === 0 ? (
            <EmptyState icon={Newspaper} title="No Morning Blitz yet" description="Morning Blitz shows appear as the season progresses." />
          ) : (
            filteredArticles('morning_blitz').map((a) => <ArticleCard key={a.id} article={a} />)
          )
        )}

        {/* Social / GridironX */}
        {activeTab === 'social' && !socialLoading && (
          (!social || social.length === 0) ? (
            <EmptyState icon={Newspaper} title="No posts yet" description="GridironX posts appear as games are played." />
          ) : (
            (social ?? []).map((p) => <SocialPostCard key={p.id} post={p} />)
          )
        )}

        {/* Power Rankings */}
        {activeTab === 'rankings' && (
          rankings && rankings.length > 0 ? (
            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="p-0">
                <div className="divide-y divide-[var(--border)]">
                  {rankings.map((r) => (
                    <div key={r.rank} className="flex items-center gap-4 px-4 py-3">
                      <span className="w-8 text-center font-display text-lg text-[var(--text-muted)]">{r.rank}</span>
                      <TeamBadge
                        abbreviation={r.team.abbreviation}
                        primaryColor={r.team.primary_color}
                        secondaryColor={r.team.secondary_color}
                        size="md"
                      />
                      <div className="flex-1">
                        <p className="text-sm font-semibold">{r.team.city} {r.team.name}</p>
                        <p className="text-xs text-[var(--text-secondary)]">{r.team.wins}-{r.team.losses}</p>
                      </div>
                      <span className="font-mono text-sm text-[var(--text-secondary)]">
                        {r.power_score?.toFixed(1)}
                      </span>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          ) : (
            <EmptyState icon={Newspaper} title="No rankings yet" description="Power rankings are generated after games are simulated." actionLabel="Go to Schedule" actionHref="/schedule" />
          )
        )}
      </div>
    </div>
  );
}
