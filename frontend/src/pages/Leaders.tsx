import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PlayerPhoto } from '@/components/PlayerPhoto';
import { useAuthStore } from '@/stores/authStore';
import { useLeaders } from '@/hooks/useApi';
import {
  PageLayout,
  PageHeader,
  SportsTabs,
  Section,
  EmptyBlock,
} from '@/components/ui/sports-ui';
import { BarChart3, TrendingUp, Trophy } from 'lucide-react';

const categoryLabels: Record<string, string> = {
  pass_yards: 'Passing Yards',
  rush_yards: 'Rushing Yards',
  rec_yards: 'Receiving Yards',
  pass_tds: 'Passing TDs',
  rush_tds: 'Rushing TDs',
  rec_tds: 'Receiving TDs',
  receptions: 'Receptions',
  tackles: 'Tackles',
  sacks: 'Sacks',
  interceptions_def: 'Interceptions',
  passer_rating: 'Passer Rating',
  yards_per_carry: 'Yards Per Carry',
  tackles_per_game: 'Tackles Per Game',
};

/** Medal colors for top-3 emphasis */
const medalColors = [
  'var(--accent-gold, #D4A843)',   // 1st — gold
  '#A8A8A8',                        // 2nd — silver
  '#CD7F32',                        // 3rd — bronze
];

function LeaderEntry({
  player,
  rank,
  isTopThree,
}: {
  player: any;
  rank: number;
  isTopThree: boolean;
}) {
  const navigate = useNavigate();

  return (
    <div
      className={`
        flex items-center gap-3 px-4 py-2.5 border-b border-[var(--border)] last:border-b-0
        transition-colors cursor-pointer hover:bg-[var(--bg-elevated)]
        ${isTopThree ? 'bg-[var(--bg-elevated)]/30' : ''}
      `}
      onClick={() => player.player_id && navigate(`/players/${player.player_id}`)}
    >
      {/* Rank */}
      {isTopThree ? (
        <div
          className="flex h-6 w-6 items-center justify-center rounded-full shrink-0"
          style={{
            backgroundColor: `${medalColors[rank - 1]}18`,
            border: `2px solid ${medalColors[rank - 1]}`,
          }}
        >
          <span
            className="font-stat text-[11px] font-bold"
            style={{ color: medalColors[rank - 1] }}
          >
            {rank}
          </span>
        </div>
      ) : (
        <span className="w-6 text-center font-stat text-xs text-[var(--text-muted)] shrink-0">
          {rank}
        </span>
      )}

      {/* Photo */}
      <PlayerPhoto imageUrl={player.image_url} firstName={player.first_name} lastName={player.last_name} size={32} />

      {/* Position badge */}
      <span className="inline-flex items-center justify-center w-9 h-6 rounded text-[10px] font-bold uppercase bg-[var(--bg-elevated)] text-[var(--text-secondary)] border border-[var(--border)] shrink-0">
        {player.position}
      </span>

      {/* Name */}
      <span
        className={`
          flex-1 min-w-0 truncate
          ${isTopThree
            ? 'text-[13px] font-semibold text-[var(--text-primary)]'
            : 'text-[13px] font-medium text-[var(--text-primary)]'
          }
        `}
      >
        {player.first_name} {player.last_name}
      </span>

      {/* Team */}
      {player.team && (
        <span className="text-[11px] text-[var(--text-muted)] shrink-0 hidden sm:block">
          {player.team}
        </span>
      )}

      {/* Stat value — prominent for top 3 */}
      <span
        className={`
          text-right font-stat shrink-0
          ${isTopThree
            ? 'text-base font-bold text-[var(--text-primary)]'
            : 'text-sm text-[var(--text-primary)]'
          }
        `}
      >
        {player.total}
      </span>
    </div>
  );
}

export default function Leaders() {
  const league = useAuthStore((s) => s.league);
  const [tab, setTab] = useState<'standard' | 'advanced'>('standard');
  const type = tab === 'advanced' ? 'advanced' : undefined;
  const { data, isLoading, isError } = useLeaders(league?.id, type);

  const tabItems = [
    { key: 'standard', label: 'Standard', icon: BarChart3 },
    { key: 'advanced', label: 'Advanced', icon: TrendingUp },
  ];

  if (isLoading) {
    return (
      <PageLayout>
        <PageHeader
          title="League Leaders"
          subtitle="Current season stat leaders"
          icon={Trophy}
        />
        <div className="grid gap-5 md:grid-cols-2">
          {[1, 2, 3, 4].map((i) => (
            <div
              key={i}
              className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden"
            >
              <div className="h-[2px] w-full bg-[var(--accent-blue)]/20" />
              <div className="p-5">
                <div className="animate-pulse space-y-3">
                  <div className="h-4 w-32 rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-3/4 rounded bg-[var(--bg-elevated)]" />
                </div>
              </div>
            </div>
          ))}
        </div>
      </PageLayout>
    );
  }

  if (isError || !data || Object.keys(data).length === 0) {
    return (
      <PageLayout>
        <PageHeader
          title="League Leaders"
          subtitle="Current season stat leaders"
          icon={Trophy}
        />
        <EmptyBlock
          icon={BarChart3}
          title="No stats available yet"
          description="Play some games to see league leaders in passing, rushing, receiving, and more."
          action={{ label: 'Go to Schedule', to: '/schedule' }}
        />
      </PageLayout>
    );
  }

  return (
    <PageLayout>
      <PageHeader
        title="League Leaders"
        subtitle={
          tab === 'advanced'
            ? 'Calculated advanced statistics'
            : 'Current season stat leaders'
        }
        icon={Trophy}
        actions={
          <SportsTabs
            tabs={tabItems}
            activeTab={tab}
            onChange={(key) => setTab(key as 'standard' | 'advanced')}
            variant="pills"
          />
        }
      />

      <div className="grid gap-5 md:grid-cols-2">
        {Object.entries(data).map(([cat, catData], idx) => {
          const label = (catData as any)?.label ?? categoryLabels[cat] ?? cat;
          const players: any[] = Array.isArray(catData)
            ? catData
            : (catData as any)?.players ?? [];

          return (
            <Section
              key={cat}
              title={label}
              accentColor={
                tab === 'advanced' ? 'var(--accent-purple, #9333ea)' : 'var(--accent-blue)'
              }
              delay={idx * 0.04}
            >
              <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
                {/* Top accent stripe */}
                <div
                  className="h-[2px] w-full"
                  style={{
                    background: `linear-gradient(90deg, ${
                      tab === 'advanced'
                        ? 'var(--accent-purple, #9333ea)'
                        : 'var(--accent-blue)'
                    }, transparent 70%)`,
                  }}
                />

                {/* Column header */}
                <div className="flex items-center gap-3 px-4 py-2 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
                  <span className="w-6 text-center text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--text-muted)]">
                    #
                  </span>
                  <span className="w-9 text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--text-muted)]">
                    Pos
                  </span>
                  <span className="flex-1 text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--text-muted)]">
                    Player
                  </span>
                  <span className="text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--text-muted)] hidden sm:block w-10">
                    Team
                  </span>
                  <span className="text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--text-muted)] text-right w-14">
                    Total
                  </span>
                </div>

                {/* Player rows */}
                {players.map((l: any, i: number) => (
                  <LeaderEntry
                    key={l.player_id}
                    player={l}
                    rank={i + 1}
                    isTopThree={i < 3}
                  />
                ))}

                {players.length === 0 && (
                  <div className="px-4 py-8 text-center">
                    <p className="text-xs text-[var(--text-muted)]">No data yet.</p>
                  </div>
                )}
              </div>
            </Section>
          );
        })}
      </div>
    </PageLayout>
  );
}
