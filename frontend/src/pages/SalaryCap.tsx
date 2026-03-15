import { useAuthStore } from '@/stores/authStore';
import { useCapSpace } from '@/hooks/useApi';
import { useNavigate } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { TeamBadge } from '@/components/TeamBadge';
import {
  PageLayout,
  PageHeader,
  Section,
  StatCard,
  DataTable,
  ContentGrid,
  MainColumn,
  SidebarColumn,
  SidePanel,
  StatRow,
  RatingBadge,
} from '@/components/ui/sports-ui';
import { DollarSign, AlertTriangle } from 'lucide-react';

const OFFENSE_POSITIONS = ['QB', 'RB', 'FB', 'WR', 'TE', 'OT', 'OG', 'C'];
const DEFENSE_POSITIONS = ['DE', 'DT', 'LB', 'CB', 'S'];
const SPECIAL_POSITIONS = ['K', 'P', 'LS'];

function formatMoney(amount: number): string {
  if (amount >= 1_000_000) {
    return `$${(amount / 1_000_000).toFixed(1)}M`;
  }
  if (amount >= 1_000) {
    return `$${(amount / 1_000).toFixed(0)}K`;
  }
  return `$${amount.toLocaleString()}`;
}

function formatMoneyFull(amount: number): string {
  return `$${amount.toLocaleString()}`;
}

function capHealthColor(pctFree: number): string {
  if (pctFree >= 20) return 'text-green-400';
  if (pctFree >= 10) return 'text-yellow-400';
  return 'text-red-400';
}

function capBarColor(pctFree: number): string {
  if (pctFree >= 20) return 'bg-green-500';
  if (pctFree >= 10) return 'bg-yellow-500';
  return 'bg-red-500';
}

function capAccentColor(pctFree: number): string {
  if (pctFree >= 20) return '#22c55e';
  if (pctFree >= 10) return '#eab308';
  return '#ef4444';
}

export default function SalaryCap() {
  const team = useAuthStore((s) => s.team);
  const { data: cap, isLoading } = useCapSpace(team?.id);
  const navigate = useNavigate();

  if (isLoading) {
    return (
      <PageLayout>
        <p className="text-[var(--text-secondary)]">Loading salary cap data...</p>
      </PageLayout>
    );
  }

  if (!cap) {
    return (
      <PageLayout>
        <p className="text-[var(--text-secondary)]">No cap data available.</p>
      </PageLayout>
    );
  }

  const pctUsed = cap.total_cap > 0 ? (cap.cap_used / cap.total_cap) * 100 : 0;
  const pctFree = 100 - pctUsed;

  // Group contracts by position group
  const offenseContracts = cap.contracts.filter((c) => OFFENSE_POSITIONS.includes(c.position));
  const defenseContracts = cap.contracts.filter((c) => DEFENSE_POSITIONS.includes(c.position));
  const specialContracts = cap.contracts.filter((c) => SPECIAL_POSITIONS.includes(c.position));

  const groupTotal = (contracts: typeof cap.contracts) =>
    contracts.reduce((sum, c) => sum + c.cap_hit, 0);
  const groupAvg = (contracts: typeof cap.contracts) =>
    contracts.length > 0 ? Math.round(groupTotal(contracts) / contracts.length) : 0;

  const offenseTotal = groupTotal(offenseContracts);
  const defenseTotal = groupTotal(defenseContracts);
  const specialTotal = groupTotal(specialContracts);
  const maxGroupTotal = Math.max(offenseTotal, defenseTotal, specialTotal, 1);

  // Expiring contracts (years_remaining = 1)
  const expiringContracts = cap.contracts.filter((c) => c.years_remaining === 1);

  // Top contracts (sorted by cap_hit descending)
  const topContracts = [...cap.contracts].sort((a, b) => b.cap_hit - a.cap_hit);

  const teamColor = team?.primary_color ?? '#2188FF';

  return (
    <PageLayout>
      <PageHeader
        title="Salary Cap"
        subtitle={`${team?.city} ${team?.name} — Cap Management`}
        icon={DollarSign}
        accentColor={teamColor}
        actions={
          <TeamBadge
            abbreviation={team?.abbreviation}
            primaryColor={team?.primary_color}
            secondaryColor={team?.secondary_color}
            size="lg"
          />
        }
      />

      {/* Cap Overview Stat Cards */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-6">
        <StatCard
          label="Total Cap"
          value={formatMoney(cap.total_cap)}
          accentColor={teamColor}
        />
        <StatCard
          label="Cap Used"
          value={formatMoney(cap.cap_used)}
          sub={`${pctUsed.toFixed(1)}%`}
          accentColor="var(--text-muted)"
        />
        <StatCard
          label="Available"
          value={formatMoney(cap.cap_remaining)}
          sub={`${pctFree.toFixed(1)}%`}
          accentColor={capAccentColor(pctFree)}
        />
        {cap.total_dead_cap > 0 && (
          <StatCard
            label="Dead Money"
            value={formatMoney(cap.total_dead_cap)}
            accentColor="#eab308"
          />
        )}
      </div>

      {/* Cap Usage Bar */}
      <Section title="Cap Usage" accentColor={teamColor} delay={0.1}>
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] px-4 py-4">
          <div className="mb-1.5 flex justify-between text-[10px] font-semibold text-[var(--text-muted)]">
            <span>{pctUsed.toFixed(1)}% Used</span>
            <span className={capHealthColor(pctFree)}>{pctFree.toFixed(1)}% Free</span>
          </div>
          <div className="h-3 w-full overflow-hidden rounded-full bg-[var(--bg-elevated)]">
            <div
              className={`h-full rounded-full transition-all ${capBarColor(pctFree)}`}
              style={{ width: `${Math.min(pctUsed, 100)}%` }}
            />
          </div>
          {cap.total_dead_cap > 0 && (
            <div className="mt-3 flex items-center gap-2 text-xs text-[var(--text-muted)]">
              <AlertTriangle className="h-3.5 w-3.5 text-yellow-500" />
              Dead Money: {formatMoney(cap.total_dead_cap)}
            </div>
          )}
        </div>
      </Section>

      <ContentGrid layout="main-sidebar" className="mt-6">
        <MainColumn>
          {/* Top Contracts */}
          <Section title="Top Contracts" accentColor={teamColor} delay={0.2}>
            <DataTable
              columns={[
                {
                  key: 'player_name',
                  label: 'Player',
                  render: (c) => (
                    <span className="text-[13px] font-medium">{c.player_name}</span>
                  ),
                },
                {
                  key: 'position',
                  label: 'Pos',
                  render: (c) => (
                    <Badge variant="outline" className="text-[10px] font-semibold border-[var(--border)]">
                      {c.position}
                    </Badge>
                  ),
                },
                {
                  key: 'overall_rating',
                  label: 'OVR',
                  stat: true,
                  render: (c) => <RatingBadge rating={c.overall_rating} size="sm" />,
                },
                {
                  key: 'salary_annual',
                  label: 'Annual Salary',
                  stat: true,
                  align: 'right' as const,
                  render: (c) => (
                    <span className="font-stat text-sm">{formatMoneyFull(c.salary_annual)}</span>
                  ),
                },
                {
                  key: 'cap_hit',
                  label: 'Cap Hit',
                  stat: true,
                  align: 'right' as const,
                  render: (c) => (
                    <span className="font-stat text-sm">{formatMoneyFull(c.cap_hit)}</span>
                  ),
                },
                {
                  key: 'years_remaining',
                  label: 'Years Left',
                  align: 'center' as const,
                  render: (c) => (
                    <Badge
                      variant="outline"
                      className={`text-[10px] font-semibold ${
                        c.years_remaining === 1
                          ? 'bg-red-500/10 text-red-400 border-red-500/20'
                          : 'border-[var(--border)]'
                      }`}
                    >
                      {c.years_remaining}yr
                    </Badge>
                  ),
                },
              ]}
              data={topContracts}
              rowKey={(c) => c.contract_id}
              onRowClick={(c) => navigate(`/player/${c.player_id}`)}
              accentColor={teamColor}
              emptyMessage="No contracts found"
            />
          </Section>

          {/* Expiring Contracts */}
          <Section title="Expiring Contracts" accentColor="#eab308" delay={0.3}>
            {expiringContracts.length > 0 && (
              <div className="mb-2">
                <Badge variant="secondary" className="text-[10px] bg-yellow-500/10 text-yellow-400 border-yellow-500/20">
                  {expiringContracts.length} expiring
                </Badge>
              </div>
            )}
            <DataTable
              columns={[
                {
                  key: 'player_name',
                  label: 'Player',
                  render: (c) => (
                    <span className="text-[13px] font-medium">{c.player_name}</span>
                  ),
                },
                {
                  key: 'position',
                  label: 'Pos',
                  render: (c) => (
                    <Badge variant="outline" className="text-[10px] font-semibold border-[var(--border)]">
                      {c.position}
                    </Badge>
                  ),
                },
                {
                  key: 'overall_rating',
                  label: 'OVR',
                  stat: true,
                  render: (c) => <RatingBadge rating={c.overall_rating} size="sm" />,
                },
                {
                  key: 'age',
                  label: 'Age',
                  align: 'center' as const,
                  render: (c) => (
                    <span className="text-sm text-[var(--text-secondary)]">{c.age}</span>
                  ),
                },
                {
                  key: 'salary_annual',
                  label: 'Current Salary',
                  stat: true,
                  align: 'right' as const,
                  render: (c) => (
                    <span className="font-stat text-sm">{formatMoneyFull(c.salary_annual)}</span>
                  ),
                },
              ]}
              data={[...expiringContracts].sort((a, b) => b.overall_rating - a.overall_rating)}
              rowKey={(c) => c.contract_id}
              onRowClick={(c) => navigate(`/player/${c.player_id}`)}
              accentColor="#eab308"
              emptyMessage="No expiring contracts"
            />
          </Section>
        </MainColumn>

        <SidebarColumn>
          {/* Position Group Breakdown */}
          {[
            { label: 'Offense', contracts: offenseContracts, total: offenseTotal },
            { label: 'Defense', contracts: defenseContracts, total: defenseTotal },
            { label: 'Special Teams', contracts: specialContracts, total: specialTotal },
          ].map((group, i) => (
            <SidePanel
              key={group.label}
              title={group.label}
              accentColor={teamColor}
              delay={0.2 + i * 0.1}
            >
              <div className="space-y-1">
                <StatRow label="Total" value={formatMoney(group.total)} />
                <StatRow label="Players" value={group.contracts.length} />
                <StatRow label="Avg/Player" value={formatMoney(groupAvg(group.contracts))} />
              </div>
              <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-[var(--bg-elevated)]">
                <div
                  className="h-full rounded-full bg-[var(--accent-blue)] transition-all"
                  style={{ width: `${maxGroupTotal > 0 ? (group.total / maxGroupTotal) * 100 : 0}%` }}
                />
              </div>
            </SidePanel>
          ))}

          {/* Next Season Projection */}
          <SidePanel
            title="Next Season Projection"
            accentColor="#22c55e"
            delay={0.5}
          >
            <div className="space-y-4">
              <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">
                  Committed Cap (Multi-Year Deals)
                </p>
                <p className="mt-1 font-stat text-2xl text-[var(--text-secondary)]">
                  {formatMoney(cap.committed_next_year)}
                </p>
                <p className="mt-0.5 text-xs text-[var(--text-muted)]">
                  {cap.contracts.filter((c) => c.years_remaining > 1).length} players under contract
                </p>
              </div>
              <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">
                  Projected Available Cap
                </p>
                <p className={`mt-1 font-stat text-2xl ${cap.projected_cap_available > 0 ? 'text-green-400' : 'text-red-400'}`}>
                  {formatMoney(cap.projected_cap_available)}
                </p>
                <p className="mt-0.5 text-xs text-[var(--text-muted)]">
                  Before re-signing expiring players
                </p>
              </div>
            </div>
          </SidePanel>
        </SidebarColumn>
      </ContentGrid>
    </PageLayout>
  );
}
