import { useLegacy, useAwards } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { motion } from 'framer-motion';
import {
  Award as AwardIcon, Trophy, Calendar,
} from 'lucide-react';
import {
  RadialBarChart, RadialBar, PolarAngleAxis,
} from 'recharts';
import {
  PageLayout, PageHeader, Section, StatCard, DataTable,
  ContentGrid, SidePanel, EmptyBlock,
} from '@/components/ui/sports-ui';

function getLegacyZone(score: number) {
  if (score >= 90) return { label: 'Legendary', color: '#D4A017' };
  if (score >= 75) return { label: 'Elite', color: '#2188FF' };
  if (score >= 60) return { label: 'Great', color: '#22C55E' };
  if (score >= 40) return { label: 'Good', color: '#D4A017' };
  if (score >= 20) return { label: 'Rising', color: '#F97316' };
  return { label: 'Newcomer', color: '#8B949E' };
}

function LegacyGauge({ value }: { value: number }) {
  const zone = getLegacyZone(value);
  const data = [{ value, fill: zone.color }];

  return (
    <div className="relative flex flex-col items-center">
      <RadialBarChart
        width={200}
        height={200}
        innerRadius={72}
        outerRadius={95}
        data={data}
        startAngle={225}
        endAngle={-45}
        barSize={16}
      >
        <PolarAngleAxis type="number" domain={[0, 100]} angleAxisId={0} tick={false} />
        <RadialBar
          dataKey="value"
          cornerRadius={10}
          background={{ fill: 'rgba(48,54,61,0.5)' }}
          animationDuration={1200}
        />
      </RadialBarChart>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="font-display text-4xl" style={{ color: zone.color }}>
          {value}
        </span>
        <span className="text-xs font-medium uppercase tracking-wider" style={{ color: zone.color }}>
          {zone.label}
        </span>
      </div>
    </div>
  );
}

function playoffBadgeClass(result: string) {
  if (!result || result === 'none' || result === '--') return null;
  const map: Record<string, string> = {
    champion: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    runner_up: 'bg-gray-400/20 text-gray-300 border-gray-400/30',
    conference_finals: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
    divisional: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
    wild_card: 'bg-green-500/20 text-green-400 border-green-500/30',
    missed: 'bg-red-500/20 text-red-400 border-red-500/30',
  };
  return map[result] ?? '';
}

interface SeasonRow {
  year: number;
  team_name: string;
  wins: number;
  losses: number;
  ties: number;
  playoff_result: string;
  notable_event: string;
}

interface AwardRow {
  id: number;
  name: string;
  season_year: number;
  category: string;
  recipient: string;
  description: string;
}

export default function Legacy() {
  const coach = useAuthStore((s) => s.coach);
  const { data: legacy, isLoading } = useLegacy();
  const { data: awards, isLoading: awardsLoading } = useAwards();

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <AwardIcon className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-gold)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading legacy...</p>
        </div>
      </div>
    );
  }

  const totalWins = legacy?.total_wins ?? 0;
  const totalLosses = legacy?.total_losses ?? 0;
  const totalTies = legacy?.total_ties ?? 0;
  const championships = legacy?.championships ?? 0;
  const playoffAppearances = legacy?.playoff_appearances ?? 0;
  const legacyScore = legacy?.legacy_score ?? 0;
  const seasons: SeasonRow[] = legacy?.seasons ?? [];
  const totalGames = totalWins + totalLosses + totalTies;
  const winPct = totalGames > 0 ? ((totalWins / totalGames) * 100).toFixed(1) : '0.0';

  const seasonColumns = [
    {
      key: 'year',
      label: 'Year',
      stat: true,
      render: (row: SeasonRow) => (
        <span className="font-stat text-sm">{row.year}</span>
      ),
    },
    {
      key: 'team_name',
      label: 'Team',
      render: (row: SeasonRow) => (
        <span className="text-sm text-[var(--text-secondary)]">{row.team_name}</span>
      ),
    },
    {
      key: 'record',
      label: 'Record',
      stat: true,
      render: (row: SeasonRow) => (
        <span className="font-stat text-sm">
          {row.wins}-{row.losses}{row.ties > 0 ? `-${row.ties}` : ''}
        </span>
      ),
    },
    {
      key: 'winpct',
      label: 'Win %',
      align: 'right' as const,
      stat: true,
      render: (row: SeasonRow) => {
        const games = row.wins + row.losses + row.ties;
        const pct = games > 0 ? ((row.wins / games) * 100).toFixed(1) : '0.0';
        return <span className="font-stat text-sm">{pct}%</span>;
      },
    },
    {
      key: 'playoff_result',
      label: 'Playoffs',
      render: (row: SeasonRow) => {
        const cls = playoffBadgeClass(row.playoff_result);
        if (cls === null) {
          return <span className="text-xs text-[var(--text-muted)]">--</span>;
        }
        return (
          <span className={`inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium ${cls}`}>
            {row.playoff_result.replace(/_/g, ' ')}
          </span>
        );
      },
    },
    {
      key: 'notable_event',
      label: 'Notable',
      render: (row: SeasonRow) => (
        <span className="text-sm text-[var(--text-secondary)] max-w-xs truncate block">
          {row.notable_event || '--'}
        </span>
      ),
    },
  ];

  return (
    <PageLayout>
      <PageHeader
        title="Legacy"
        subtitle={`${coach?.name}'s coaching career`}
        icon={AwardIcon}
        accentColor="var(--accent-gold)"
      />

      {/* Top Row: Legacy Score + Career Stats */}
      <ContentGrid layout="main-sidebar" className="mb-6">
        {/* Career Stats -- Main Column */}
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard
              label="Record"
              value={`${totalWins}-${totalLosses}${totalTies > 0 ? `-${totalTies}` : ''}`}
              sub={`${winPct}% Win Rate`}
              accentColor="var(--accent-blue)"
            />
            <StatCard
              label="Championships"
              value={championships}
              sub="Titles Won"
              accentColor="var(--accent-gold)"
            />
            <StatCard
              label="Playoffs"
              value={playoffAppearances}
              sub="Appearances"
              accentColor="#22C55E"
            />
            <StatCard
              label="Seasons"
              value={seasons.length}
              sub="Coached"
              accentColor="var(--accent-blue)"
            />
            <StatCard
              label="Total Wins"
              value={totalWins}
              sub="Career Victories"
              accentColor="#EAB308"
            />
            <StatCard
              label="Awards"
              value={(awards ?? []).length}
              sub="Accolades"
              accentColor="#A855F7"
            />
          </div>
        </div>

        {/* Legacy Gauge -- Sidebar */}
        <div>
          <SidePanel title="Legacy Score" accentColor={getLegacyZone(legacyScore).color} delay={0.1}>
            <div className="flex flex-col items-center">
              <LegacyGauge value={legacyScore} />
              <div className="flex flex-wrap gap-3 mt-4 justify-center">
                {[
                  { range: '90+', label: 'Legendary', color: '#D4A017' },
                  { range: '75-89', label: 'Elite', color: '#2188FF' },
                  { range: '60-74', label: 'Great', color: '#22C55E' },
                  { range: '40-59', label: 'Good', color: '#D4A017' },
                  { range: '<40', label: 'Rising', color: '#F97316' },
                ].map((z) => (
                  <div key={z.label} className="flex items-center gap-1.5">
                    <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: z.color }} />
                    <span className="text-[10px] text-[var(--text-muted)]">{z.range} {z.label}</span>
                  </div>
                ))}
              </div>
            </div>
          </SidePanel>
        </div>
      </ContentGrid>

      {/* Season History Table */}
      <Section title="Season History" accentColor="var(--accent-blue)" delay={0.3} className="mb-6">
        {seasons.length === 0 ? (
          <EmptyBlock
            icon={Calendar}
            title="No seasons completed yet"
            description="Complete your first season to see your coaching history."
          />
        ) : (
          <DataTable<SeasonRow>
            columns={seasonColumns}
            data={seasons}
            accentColor="var(--accent-blue)"
            rowKey={(row) => `${row.year}-${row.team_name}`}
            striped
          />
        )}
      </Section>

      {/* Awards Section */}
      <Section title="Awards" accentColor="var(--accent-gold)" delay={0.4}>
        {awardsLoading ? (
          <p className="text-sm text-[var(--text-secondary)]">Loading awards...</p>
        ) : (awards ?? []).length === 0 ? (
          <EmptyBlock
            icon={AwardIcon}
            title="No awards won yet"
            description="Lead your team to greatness to earn recognition."
          />
        ) : (
          <div className="space-y-2">
            {(awards ?? []).map((award: AwardRow, i: number) => (
              <motion.div
                key={award.id}
                initial={{ opacity: 0, x: -10 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.3, delay: i * 0.05 }}
                className="flex items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-4 py-3"
              >
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--accent-gold)]/10">
                  <Trophy className="h-4 w-4 text-[var(--accent-gold)]" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium truncate">{award.name}</p>
                  <div className="flex items-center gap-2 mt-0.5">
                    <span className="inline-flex items-center rounded border border-[var(--border)] px-1.5 py-0.5 text-[10px] font-medium text-[var(--text-secondary)]">
                      {award.season_year}
                    </span>
                    <span className="text-[10px] text-[var(--text-muted)]">
                      {award.category}
                    </span>
                  </div>
                </div>
                <div className="text-right shrink-0">
                  <p className="text-sm text-[var(--accent-gold)]">{award.recipient}</p>
                  <p className="text-[10px] text-[var(--text-muted)]">{award.description}</p>
                </div>
              </motion.div>
            ))}
          </div>
        )}
      </Section>
    </PageLayout>
  );
}
