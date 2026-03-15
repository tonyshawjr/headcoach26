import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useArticles, useSocial, usePowerRankings } from '@/hooks/useApi';
import { ArticleCard } from '@/components/cards/ArticleCard';
import { SocialPostCard } from '@/components/cards/SocialPostCard';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { TeamBadge } from '@/components/TeamBadge';
import { Newspaper } from 'lucide-react';
import {
  PageLayout,
  PageHeader,
  Section,
  SportsTabs,
  ContentGrid,
  MainColumn,
  SidebarColumn,
  SidePanel,
  EmptyBlock,
} from '@/components/ui/sports-ui';

type TabValue = 'news' | 'recaps' | 'features' | 'columns' | 'morning_blitz' | 'social' | 'rankings';

const tabs: { key: TabValue; label: string }[] = [
  { key: 'news', label: 'All News' },
  { key: 'recaps', label: 'Recaps' },
  { key: 'features', label: 'Features' },
  { key: 'columns', label: 'Columns' },
  { key: 'morning_blitz', label: 'Morning Blitz' },
  { key: 'social', label: 'GridironX' },
  { key: 'rankings', label: 'Power Rankings' },
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

  const statusText = articlesLoading
    ? 'Loading articles...'
    : articlesError
      ? 'Error loading articles'
      : `${articles.length} articles loaded`;

  return (
    <PageLayout>
      <PageHeader
        title="League Hub"
        subtitle={`News, social media, and rankings — ${statusText}`}
        icon={Newspaper}
      />

      <SportsTabs
        tabs={tabs}
        activeTab={activeTab}
        onChange={(key) => setActiveTab(key as TabValue)}
        variant="underline"
      />

      <div className="mt-6">
        {/* Loading state for article tabs */}
        {activeTab !== 'social' && activeTab !== 'rankings' && articlesLoading && (
          <Section title="Loading">
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
          </Section>
        )}
        {activeTab === 'social' && socialLoading && (
          <p className="text-sm text-[var(--text-secondary)]">Loading social posts...</p>
        )}

        {/* All News */}
        {activeTab === 'news' && !articlesLoading && (
          <Section title="All News" delay={0.05}>
            {articles.length === 0 ? (
              <EmptyBlock
                icon={Newspaper}
                title="No articles yet"
                description="Play some games to generate news coverage, recaps, and columns."
                action={{ label: 'Go to Schedule', to: '/schedule' }}
              />
            ) : (
              <ContentGrid layout="main-sidebar">
                <MainColumn>
                  <div className="space-y-3">
                    {articles.map((a) => <ArticleCard key={a.id} article={a} />)}
                  </div>
                  {articlesResp && articlesResp.pages > 1 && (
                    <div className="flex justify-center gap-2">
                      <Button size="sm" variant="outline" disabled={articlePage <= 1} onClick={() => setArticlePage((p) => p - 1)}>Prev</Button>
                      <span className="text-sm text-[var(--text-secondary)] self-center">Page {articlePage} of {articlesResp.pages}</span>
                      <Button size="sm" variant="outline" disabled={articlePage >= articlesResp.pages} onClick={() => setArticlePage((p) => p + 1)}>Next</Button>
                    </div>
                  )}
                </MainColumn>
                <SidebarColumn>
                  {rankings && rankings.length > 0 && (
                    <SidePanel title="Power Rankings" action={{ label: 'Full Rankings', to: '#' }} delay={0.1}>
                      <div className="divide-y divide-[var(--border)] -mx-4 -my-4">
                        {rankings.slice(0, 5).map((r) => (
                          <div key={r.rank} className="flex items-center gap-3 px-4 py-2.5">
                            <span className="w-6 text-center font-stat text-xs text-[var(--text-muted)]">{r.rank}</span>
                            <TeamBadge
                              abbreviation={r.team.abbreviation}
                              primaryColor={r.team.primary_color}
                              secondaryColor={r.team.secondary_color}
                              size="sm"
                            />
                            <div className="flex-1 min-w-0">
                              <p className="text-xs font-semibold truncate">{r.team.city} {r.team.name}</p>
                              <p className="text-[10px] text-[var(--text-muted)]">{r.team.wins}-{r.team.losses}</p>
                            </div>
                          </div>
                        ))}
                      </div>
                    </SidePanel>
                  )}
                </SidebarColumn>
              </ContentGrid>
            )}
          </Section>
        )}

        {/* Recaps */}
        {activeTab === 'recaps' && !articlesLoading && (
          <Section title="Game Recaps" delay={0.05}>
            {filteredArticles('game_recap').length === 0 ? (
              <EmptyBlock icon={Newspaper} title="No game recaps yet" description="Recaps are generated after games are simulated." />
            ) : (
              <div className="space-y-3">
                {filteredArticles('game_recap').map((a) => <ArticleCard key={a.id} article={a} />)}
              </div>
            )}
          </Section>
        )}

        {/* Features */}
        {activeTab === 'features' && !articlesLoading && (
          <Section title="Features" delay={0.05}>
            {filteredArticles('feature').length === 0 ? (
              <EmptyBlock icon={Newspaper} title="No feature articles yet" description="Feature stories appear as the season progresses." />
            ) : (
              <div className="space-y-3">
                {filteredArticles('feature').map((a) => <ArticleCard key={a.id} article={a} />)}
              </div>
            )}
          </Section>
        )}

        {/* Columns */}
        {activeTab === 'columns' && !articlesLoading && (
          <Section title="Columns" delay={0.05}>
            {filteredArticles('column').length === 0 ? (
              <EmptyBlock icon={Newspaper} title="No columns yet" description="Columnist opinions appear as the season progresses." />
            ) : (
              <div className="space-y-3">
                {filteredArticles('column').map((a) => <ArticleCard key={a.id} article={a} />)}
              </div>
            )}
          </Section>
        )}

        {/* Morning Blitz */}
        {activeTab === 'morning_blitz' && !articlesLoading && (
          <Section title="Morning Blitz" delay={0.05}>
            {filteredArticles('morning_blitz').length === 0 ? (
              <EmptyBlock icon={Newspaper} title="No Morning Blitz yet" description="Morning Blitz shows appear as the season progresses." />
            ) : (
              <div className="space-y-3">
                {filteredArticles('morning_blitz').map((a) => <ArticleCard key={a.id} article={a} />)}
              </div>
            )}
          </Section>
        )}

        {/* Social / GridironX */}
        {activeTab === 'social' && !socialLoading && (
          <Section title="GridironX" delay={0.05}>
            {(!social || social.length === 0) ? (
              <EmptyBlock icon={Newspaper} title="No posts yet" description="GridironX posts appear as games are played." />
            ) : (
              <div className="space-y-3">
                {(social ?? []).map((p) => <SocialPostCard key={p.id} post={p} />)}
              </div>
            )}
          </Section>
        )}

        {/* Power Rankings */}
        {activeTab === 'rankings' && (
          <Section title="Power Rankings" delay={0.05}>
            {rankings && rankings.length > 0 ? (
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
              <EmptyBlock
                icon={Newspaper}
                title="No rankings yet"
                description="Power rankings are generated after games are simulated."
                action={{ label: 'Go to Schedule', to: '/schedule' }}
              />
            )}
          </Section>
        )}
      </div>
    </PageLayout>
  );
}
