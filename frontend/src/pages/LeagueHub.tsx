import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useArticles, useSocial, usePowerRankings } from '@/hooks/useApi';
import { ArticleCard } from '@/components/cards/ArticleCard';
import { SocialPostCard } from '@/components/cards/SocialPostCard';
import { weekLabel, weekLabelShort } from '@/lib/weekLabel';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { TeamLogo } from '@/components/TeamLogo';
import { Newspaper, ChevronLeft, ChevronRight } from 'lucide-react';
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

type TabValue = 'news' | 'recaps' | 'scouting' | 'features' | 'columns' | 'morning_blitz' | 'social' | 'rankings';

const tabs: { key: TabValue; label: string }[] = [
  { key: 'news', label: 'All News' },
  { key: 'recaps', label: 'Recaps' },
  { key: 'scouting', label: 'Scouting Report' },
  { key: 'features', label: 'Features' },
  { key: 'columns', label: 'Columns' },
  { key: 'morning_blitz', label: 'Morning Blitz' },
  { key: 'social', label: 'GridironX' },
  { key: 'rankings', label: 'Power Rankings' },
];

export default function LeagueHub() {
  const league = useAuthStore((s) => s.league);
  const [activeTab, setActiveTab] = useState<TabValue>('news');

  // Pagination for All News
  const [newsPage, setNewsPage] = useState(1);

  // Week selector for Recaps
  const currentWeek = league?.current_week ?? 1;
  const [recapWeek, setRecapWeek] = useState(currentWeek);

  // Pagination for filtered tabs
  const [featurePage, setFeaturePage] = useState(1);
  const [columnPage, setColumnPage] = useState(1);
  const [blitzPage, setBlitzPage] = useState(1);
  const [scoutPage, setScoutPage] = useState(1);

  // API calls — use server-side type/week filtering
  const { data: allNewsResp, isLoading: newsLoading, isError: newsError } = useArticles(league?.id, { page: newsPage });
  const { data: recapsResp, isLoading: recapsLoading } = useArticles(league?.id, { type: 'game_recap', week: recapWeek, page: 1 });
  const { data: scoutResp, isLoading: scoutLoading } = useArticles(league?.id, { type: 'draft_coverage', page: scoutPage });
  const { data: featuresResp, isLoading: featuresLoading } = useArticles(league?.id, { type: 'feature', page: featurePage });
  const { data: columnsResp, isLoading: columnsLoading } = useArticles(league?.id, { type: 'column', page: columnPage });
  const { data: blitzResp, isLoading: blitzLoading } = useArticles(league?.id, { type: 'morning_blitz', page: blitzPage });
  const { data: social, isLoading: socialLoading } = useSocial(league?.id);
  const { data: rankings } = usePowerRankings(league?.id);

  const allNews = allNewsResp?.articles ?? [];
  const recaps = recapsResp?.articles ?? [];
  const scoutArticles = scoutResp?.articles ?? [];
  const features = featuresResp?.articles ?? [];
  const columns = columnsResp?.articles ?? [];
  const blitz = blitzResp?.articles ?? [];

  const statusText = newsLoading
    ? 'Loading articles...'
    : newsError
      ? 'Error loading articles'
      : `${allNewsResp?.total ?? 0} articles`;

  // Week navigation for recaps
  const canGoPrevWeek = recapWeek > 1;
  const canGoNextWeek = recapWeek < Math.min(currentWeek, 22);

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

        {/* ═══ All News ═══ */}
        {activeTab === 'news' && (
          <Section title="All News" delay={0.05}>
            {newsLoading ? (
              <LoadingSkeleton />
            ) : allNews.length === 0 ? (
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
                    {allNews.map((a) => <ArticleCard key={a.id} article={a} />)}
                  </div>
                  <Pagination
                    page={newsPage}
                    totalPages={allNewsResp?.pages ?? 1}
                    onPrev={() => setNewsPage((p) => p - 1)}
                    onNext={() => setNewsPage((p) => p + 1)}
                  />
                </MainColumn>
                <SidebarColumn>
                  {rankings && rankings.length > 0 && (
                    <SidePanel title="Power Rankings" action={{ label: 'Full Rankings', to: '#' }} delay={0.1}>
                      <div className="divide-y divide-[var(--border)] -mx-4 -my-4">
                        {rankings.slice(0, 5).map((r) => (
                          <div key={r.rank} className="flex items-center gap-3 px-4 py-2.5">
                            <span className="w-6 text-center font-stat text-xs text-[var(--text-muted)]">{r.rank}</span>
                            <TeamLogo
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

        {/* ═══ Recaps — with week selector ═══ */}
        {activeTab === 'recaps' && (
          <Section title="Game Recaps" delay={0.05}>
            {/* Week selector */}
            <div className="flex items-center justify-center gap-4 mb-6">
              <button
                onClick={() => setRecapWeek((w) => w - 1)}
                disabled={!canGoPrevWeek}
                className="flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--border)] text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] disabled:opacity-30 transition-colors"
              >
                <ChevronLeft className="h-4 w-4" />
              </button>
              <div className="text-center min-w-[160px]">
                <p className="text-sm font-bold text-[var(--text-primary)]">{weekLabel(recapWeek)}</p>
                <p className="text-[10px] text-[var(--text-muted)] uppercase tracking-wider">
                  {recapWeek === currentWeek ? 'Current' : ''}
                </p>
              </div>
              <button
                onClick={() => setRecapWeek((w) => w + 1)}
                disabled={!canGoNextWeek}
                className="flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--border)] text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] disabled:opacity-30 transition-colors"
              >
                <ChevronRight className="h-4 w-4" />
              </button>
            </div>

            {/* Week quick-jump pills */}
            <div className="flex flex-wrap justify-center gap-1.5 mb-6">
              {Array.from({ length: Math.min(currentWeek, 22) }, (_, i) => i + 1).map((w) => (
                <button
                  key={w}
                  onClick={() => setRecapWeek(w)}
                  className={`h-7 min-w-[32px] rounded px-2 text-[11px] font-semibold transition-colors ${
                    w === recapWeek
                      ? 'bg-[var(--accent-blue)] text-white'
                      : 'bg-[var(--bg-elevated)] text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                  }`}
                >
                  {w <= 18 ? w : weekLabelShort(w)}
                </button>
              ))}
            </div>

            {recapsLoading ? (
              <LoadingSkeleton />
            ) : recaps.length === 0 ? (
              <EmptyBlock
                icon={Newspaper}
                title={`No recaps for ${weekLabel(recapWeek)}`}
                description="Recaps are generated after games are simulated."
              />
            ) : (
              <div className="space-y-3">
                {recaps.map((a) => <ArticleCard key={a.id} article={a} />)}
              </div>
            )}
          </Section>
        )}

        {/* ═══ Scouting Report ═══ */}
        {activeTab === 'scouting' && (
          <Section title="Scouting Report" delay={0.05}>
            {scoutLoading ? (
              <LoadingSkeleton />
            ) : scoutArticles.length === 0 ? (
              <EmptyBlock icon={Newspaper} title="No scouting reports yet" description="Jake Morrison and Nina Charles will start filing draft coverage once the offseason begins and a new draft class is generated." />
            ) : (
              <>
                <div className="space-y-3">
                  {scoutArticles.map((a) => <ArticleCard key={a.id} article={a} />)}
                </div>
                <Pagination
                  page={scoutPage}
                  totalPages={scoutResp?.pages ?? 1}
                  onPrev={() => setScoutPage((p) => p - 1)}
                  onNext={() => setScoutPage((p) => p + 1)}
                />
              </>
            )}
          </Section>
        )}

        {/* ═══ Features ═══ */}
        {activeTab === 'features' && (
          <Section title="Features" delay={0.05}>
            {featuresLoading ? (
              <LoadingSkeleton />
            ) : features.length === 0 ? (
              <EmptyBlock icon={Newspaper} title="No feature articles yet" description="Feature stories appear as the season progresses — midseason reports, playoff previews, draft prospect profiles, and more." />
            ) : (
              <>
                <div className="space-y-3">
                  {features.map((a) => <ArticleCard key={a.id} article={a} />)}
                </div>
                <Pagination
                  page={featurePage}
                  totalPages={featuresResp?.pages ?? 1}
                  onPrev={() => setFeaturePage((p) => p - 1)}
                  onNext={() => setFeaturePage((p) => p + 1)}
                />
              </>
            )}
          </Section>
        )}

        {/* ═══ Columns ═══ */}
        {activeTab === 'columns' && (
          <Section title="Columns" delay={0.05}>
            {columnsLoading ? (
              <LoadingSkeleton />
            ) : columns.length === 0 ? (
              <EmptyBlock icon={Newspaper} title="No columns yet" description="Weekly opinion columns from Terry Hollis, Dana Reeves, and Marcus Bell appear as the season progresses." />
            ) : (
              <>
                <div className="space-y-3">
                  {columns.map((a) => <ArticleCard key={a.id} article={a} />)}
                </div>
                <Pagination
                  page={columnPage}
                  totalPages={columnsResp?.pages ?? 1}
                  onPrev={() => setColumnPage((p) => p - 1)}
                  onNext={() => setColumnPage((p) => p + 1)}
                />
              </>
            )}
          </Section>
        )}

        {/* ═══ Morning Blitz ═══ */}
        {activeTab === 'morning_blitz' && (
          <Section title="Morning Blitz" delay={0.05}>
            {blitzLoading ? (
              <LoadingSkeleton />
            ) : blitz.length === 0 ? (
              <EmptyBlock icon={Newspaper} title="No Morning Blitz yet" description="Weekly roundups covering the biggest wins, upsets, and storylines across the league." />
            ) : (
              <>
                <div className="space-y-3">
                  {blitz.map((a) => <ArticleCard key={a.id} article={a} />)}
                </div>
                <Pagination
                  page={blitzPage}
                  totalPages={blitzResp?.pages ?? 1}
                  onPrev={() => setBlitzPage((p) => p - 1)}
                  onNext={() => setBlitzPage((p) => p + 1)}
                />
              </>
            )}
          </Section>
        )}

        {/* ═══ GridironX (Social) ═══ */}
        {activeTab === 'social' && (
          <Section title="GridironX" delay={0.05}>
            {socialLoading ? (
              <p className="text-sm text-[var(--text-secondary)]">Loading social posts...</p>
            ) : (!social || social.length === 0) ? (
              <EmptyBlock icon={Newspaper} title="No posts yet" description="GridironX posts appear as games are played." />
            ) : (
              <div className="space-y-3">
                {(social ?? []).map((p) => <SocialPostCard key={p.id} post={p} />)}
              </div>
            )}
          </Section>
        )}

        {/* ═══ Power Rankings ═══ */}
        {activeTab === 'rankings' && (
          <Section title="Power Rankings" delay={0.05}>
            {rankings && rankings.length > 0 ? (
              <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
                <CardContent className="p-0">
                  <div className="divide-y divide-[var(--border)]">
                    {rankings.map((r) => (
                      <div key={r.rank} className="flex items-center gap-4 px-4 py-3">
                        <span className="w-8 text-center font-display text-lg text-[var(--text-muted)]">{r.rank}</span>
                        <TeamLogo
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

/* ── Shared Components ─────────────────────────────── */

function LoadingSkeleton() {
  return (
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
  );
}

function Pagination({ page, totalPages, onPrev, onNext }: { page: number; totalPages: number; onPrev: () => void; onNext: () => void }) {
  if (totalPages <= 1) return null;
  return (
    <div className="flex justify-center gap-2 mt-4">
      <Button size="sm" variant="outline" disabled={page <= 1} onClick={onPrev}>Prev</Button>
      <span className="text-sm text-[var(--text-secondary)] self-center">Page {page} of {totalPages}</span>
      <Button size="sm" variant="outline" disabled={page >= totalPages} onClick={onNext}>Next</Button>
    </div>
  );
}
