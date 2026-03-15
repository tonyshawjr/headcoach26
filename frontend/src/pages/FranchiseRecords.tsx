import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useRecords } from '@/hooks/useApi';
import {
  PageLayout, PageHeader, Section, SportsTabs, DataTable, EmptyBlock,
} from '@/components/ui/sports-ui';
import { Badge } from '@/components/ui/badge';
import { motion } from 'framer-motion';
import { BarChart3, Trophy, Users, Zap } from 'lucide-react';

type RecordTab = 'single_season' | 'career' | 'team';

export default function FranchiseRecords() {
  const league = useAuthStore((s) => s.league);
  const { data, isLoading } = useRecords(league?.id);
  const [tab, setTab] = useState<RecordTab>('single_season');

  if (isLoading) {
    return (
      <PageLayout>
        <PageHeader title="Franchise Records" icon={BarChart3} accentColor="var(--accent-gold)" />
        <div className="flex h-64 items-center justify-center">
          <div className="text-center">
            <BarChart3 className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
            <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading records...</p>
          </div>
        </div>
      </PageLayout>
    );
  }

  if (!data) {
    return (
      <PageLayout>
        <PageHeader title="Franchise Records" icon={BarChart3} accentColor="var(--accent-gold)" />
        <EmptyBlock
          icon={BarChart3}
          title="No Records Yet"
          description="Play some games first to start setting records."
        />
      </PageLayout>
    );
  }

  return (
    <PageLayout>
      <PageHeader
        title="Franchise Records"
        icon={BarChart3}
        accentColor="var(--accent-gold)"
        subtitle="All-time records across the league"
        actions={
          <SportsTabs
            tabs={[
              { key: 'single_season', label: 'Single Season', icon: Zap },
              { key: 'career', label: 'Career', icon: Trophy },
              { key: 'team', label: 'Team', icon: Users },
            ]}
            activeTab={tab}
            onChange={(k) => setTab(k as RecordTab)}
            variant="pills"
            accentColor="var(--accent-gold)"
          />
        }
      />

      {/* Single Season Records */}
      {tab === 'single_season' && data.single_season && (
        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.3 }}
          className="grid gap-4 md:grid-cols-2"
        >
          {Object.entries(data.single_season).map(([stat, catData]) => (
            <Section key={stat} title={(catData as any).label} accentColor="var(--accent-gold)">
              <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
                <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, var(--accent-gold), transparent)' }} />
                <div className="p-4 space-y-2">
                  {(catData as any).records.map((r: any, i: number) => (
                    <div key={`${r.player_id}-${r.season_year}`} className="flex items-center gap-3 text-sm">
                      <span className={`w-5 text-center text-xs font-bold ${i === 0 ? 'text-[var(--accent-gold)]' : 'text-[var(--text-muted)]'}`}>
                        {i + 1}
                      </span>
                      <span className="flex-1 truncate">{r.first_name} {r.last_name}</span>
                      <Badge variant="outline" className="text-[10px]">{r.position}</Badge>
                      <span className="text-xs text-[var(--text-muted)]">{r.season_year}</span>
                      <span className="w-16 text-right font-stat font-semibold">{r.total}</span>
                    </div>
                  ))}
                  {(catData as any).records.length === 0 && (
                    <p className="text-xs text-[var(--text-muted)]">No records yet.</p>
                  )}
                </div>
              </div>
            </Section>
          ))}
        </motion.div>
      )}

      {/* Career Records */}
      {tab === 'career' && data.career && (
        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.3 }}
          className="grid gap-4 md:grid-cols-2"
        >
          {Object.entries(data.career).map(([stat, catData]) => (
            <Section key={stat} title={(catData as any).label} accentColor="var(--accent-gold)">
              <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
                <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, var(--accent-gold), transparent)' }} />
                <div className="p-4 space-y-2">
                  {(catData as any).records.map((r: any, i: number) => (
                    <div key={r.player_id} className="flex items-center gap-3 text-sm">
                      <span className={`w-5 text-center text-xs font-bold ${i === 0 ? 'text-[var(--accent-gold)]' : 'text-[var(--text-muted)]'}`}>
                        {i + 1}
                      </span>
                      <span className="flex-1 truncate">{r.first_name} {r.last_name}</span>
                      <Badge variant="outline" className="text-[10px]">{r.position}</Badge>
                      <span className="text-xs text-[var(--text-muted)]">{r.team}</span>
                      <span className="w-16 text-right font-stat font-semibold">{r.total}</span>
                    </div>
                  ))}
                  {(catData as any).records.length === 0 && (
                    <p className="text-xs text-[var(--text-muted)]">No records yet.</p>
                  )}
                </div>
              </div>
            </Section>
          ))}
        </motion.div>
      )}

      {/* Team Records */}
      {tab === 'team' && (
        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.3 }}
          className="space-y-6"
        >
          {/* Most Wins */}
          <Section title="Most Wins (Current Season)" accentColor="var(--accent-gold)">
            {data.team_wins && data.team_wins.length > 0 ? (
              <DataTable
                columns={[
                  {
                    key: 'rank',
                    label: '#',
                    width: 'w-10',
                    stat: true,
                    render: (_: any, i: number) => (
                      <span className={`font-stat ${i === 0 ? 'text-[var(--accent-gold)] font-bold' : 'text-[var(--text-muted)]'}`}>
                        {i + 1}
                      </span>
                    ),
                  },
                  {
                    key: 'team',
                    label: 'Team',
                    render: (t: any) => (
                      <div className="flex items-center gap-2">
                        <div className="h-3 w-3 rounded-full" style={{ backgroundColor: t.primary_color }} />
                        <span className="font-semibold">{t.city} {t.name}</span>
                      </div>
                    ),
                  },
                  {
                    key: 'record',
                    label: 'Record',
                    stat: true,
                    render: (t: any) => (
                      <span className="font-stat">{t.wins}-{t.losses}{t.ties > 0 ? `-${t.ties}` : ''}</span>
                    ),
                  },
                ]}
                data={data.team_wins}
                accentColor="var(--accent-gold)"
                rowKey={(t: any) => `${t.city}-${t.name}`}
                leaderboard
              />
            ) : (
              <p className="text-xs text-[var(--text-muted)]">No team records yet.</p>
            )}
          </Section>

          {/* Biggest Blowouts */}
          <Section title="Biggest Blowouts" accentColor="var(--accent-red)">
            {data.biggest_blowouts && data.biggest_blowouts.length > 0 ? (
              <DataTable
                columns={[
                  {
                    key: 'rank',
                    label: '#',
                    width: 'w-10',
                    stat: true,
                    render: (_: any, i: number) => (
                      <span className="font-stat text-[var(--text-muted)]">{i + 1}</span>
                    ),
                  },
                  {
                    key: 'matchup',
                    label: 'Matchup',
                    render: (g: any) => (
                      <span className="font-semibold">{g.away_team} @ {g.home_team}</span>
                    ),
                  },
                  {
                    key: 'score',
                    label: 'Score',
                    stat: true,
                    render: (g: any) => (
                      <span className="font-stat">{g.away_score}-{g.home_score}</span>
                    ),
                  },
                  {
                    key: 'margin',
                    label: 'Margin',
                    render: (g: any) => (
                      <Badge className="bg-red-500/10 text-red-400 border-red-500/20 text-xs">
                        +{g.margin}
                      </Badge>
                    ),
                  },
                  {
                    key: 'week',
                    label: 'Week',
                    render: (g: any) => (
                      <span className="text-xs text-[var(--text-muted)]">Wk {g.week}, {g.season_year}</span>
                    ),
                  },
                ]}
                data={data.biggest_blowouts}
                accentColor="var(--accent-red)"
                rowKey={(g: any) => `${g.away_team}-${g.home_team}-${g.week}-${g.season_year}`}
              />
            ) : (
              <p className="text-xs text-[var(--text-muted)]">No games played yet.</p>
            )}
          </Section>
        </motion.div>
      )}
    </PageLayout>
  );
}
