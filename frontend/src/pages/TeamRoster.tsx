import { useState } from 'react';
import { useParams, useNavigate, Navigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useTeam, useRoster, useDepthChart } from '@/hooks/useApi';
import {
  PageLayout, Section, SportsTabs, DataTable,
  EmptyBlock, RatingBadge,
} from '@/components/ui/sports-ui';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { AcquireTradeModal } from '@/components/AcquireTradeModal';
import { Users, LayoutGrid, ArrowLeftRight } from 'lucide-react';
import type { Player, DepthChartData } from '@/api/client';

function ratingColor(r: number) {
  if (r >= 85) return 'text-green-400';
  if (r >= 75) return 'text-blue-400';
  if (r >= 65) return 'text-yellow-400';
  return 'text-red-400';
}

function DevBadge({ potential }: { potential: string }) {
  const colors: Record<string, string> = {
    elite: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25',
    high: 'bg-blue-500/15 text-blue-400 border-blue-500/25',
    average: 'bg-gray-500/15 text-gray-400 border-gray-500/25',
    limited: 'bg-red-500/15 text-red-400 border-red-500/25',
  };
  const labels: Record<string, string> = {
    elite: 'Elite', high: 'High', average: 'Average', limited: 'Limited',
    superstar: 'Elite', star: 'High', normal: 'Average', slow: 'Limited',
  };
  return (
    <Badge variant="outline" className={`text-[10px] font-semibold ${colors[potential] ?? colors.average}`}>
      {labels[potential] ?? potential}
    </Badge>
  );
}

// Position group ordering for depth chart
const DEPTH_ORDER = [
  'QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C',
  'DE', 'DT', 'LB', 'CB', 'S', 'K', 'P',
];

function DepthChartView({ depthChart, teamColor }: { depthChart: DepthChartData; teamColor: string }) {
  const offense = DEPTH_ORDER.filter(p => ['QB','RB','WR','TE','OT','OG','C'].includes(p));
  const defense = DEPTH_ORDER.filter(p => ['DE','DT','LB','CB','S'].includes(p));
  const special = DEPTH_ORDER.filter(p => ['K','P'].includes(p));

  const renderGroup = (positions: string[], label: string) => (
    <Section title={label} accentColor={teamColor}>
      <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
        <div className="h-[2px] w-full" style={{ background: `linear-gradient(90deg, ${teamColor}, ${teamColor2})` }} />
        <div className="p-4 space-y-1">
          {positions.map(pos => {
            const players = depthChart[pos] ?? [];
            if (players.length === 0) return null;
            return (
              <div key={pos} className="flex items-start gap-3 py-1.5 border-b border-[var(--border)] last:border-b-0">
                <Badge variant="outline" className="text-[10px] font-bold w-8 justify-center border-[var(--border)]">{pos}</Badge>
                <div className="flex flex-wrap gap-x-4 gap-y-0.5">
                  {players.map((p, i) => (
                    <span key={p.player_id} className={`text-sm ${i === 0 ? 'font-semibold' : 'text-[var(--text-secondary)]'}`}>
                      <span className={i === 0 ? ratingColor(p.overall_rating) : ''}>{p.overall_rating}</span>
                      {' '}{p.name}
                      {i === 0 && <span className="text-[10px] text-[var(--accent-gold)] ml-1">STARTER</span>}
                    </span>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </Section>
  );

  return (
    <div className="space-y-6">
      {renderGroup(offense, 'Offense')}
      {renderGroup(defense, 'Defense')}
      {renderGroup(special, 'Special Teams')}
    </div>
  );
}

export default function TeamRoster() {
  const { id } = useParams<{ id: string }>();
  const teamId = id ? Number(id) : undefined;
  const navigate = useNavigate();
  const myTeam = useAuthStore((s) => s.team);

  const { data: team, isLoading: teamLoading } = useTeam(teamId);
  const { data: roster, isLoading: rosterLoading } = useRoster(teamId);
  const { data: depthChart } = useDepthChart(teamId);

  const [posFilter, setPosFilter] = useState('all');
  const [activeTab, setActiveTab] = useState('roster');
  const [tradePlayerId, setTradePlayerId] = useState<number | null>(null);
  const [tradePlayerName, setTradePlayerName] = useState('');
  const [tradeModalOpen, setTradeModalOpen] = useState(false);

  if (teamId && myTeam?.id === teamId) {
    return <Navigate to="/my-team" replace />;
  }

  const isLoading = teamLoading || rosterLoading;

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-3 text-[var(--text-secondary)]">
          <div className="h-5 w-5 animate-spin rounded-full border-2 border-[var(--accent-blue)] border-t-transparent" />
          <span>Loading team...</span>
        </div>
      </div>
    );
  }

  if (!team) {
    return (
      <PageLayout>
        <EmptyBlock
          icon={Users}
          title="Team Not Found"
          description="The team you're looking for doesn't exist."
          action={{ label: 'Back to Standings', to: '/standings' }}
        />
      </PageLayout>
    );
  }

  const players = roster?.active ?? [];
  const filtered = posFilter === 'all' ? players : players.filter((p) => p.position === posFilter);
  const positions = [...new Set(players.map((p) => p.position))].sort();
  const isOpponent = myTeam?.id !== teamId;
  const teamColor = team.primary_color ?? '#2188FF';
  const teamColor2 = (team as Record<string, unknown>)?.secondary_color as string ?? teamColor;

  const rosterColumns = [
    {
      key: 'jersey_number',
      label: '#',
      width: 'w-10',
      stat: true,
      render: (p: Player) => (
        <span className="text-xs text-[var(--text-muted)]">{p.jersey_number}</span>
      ),
    },
    {
      key: 'name',
      label: 'Name',
      render: (p: Player) => (
        <span className="text-[13px] font-medium">{p.first_name} {p.last_name}</span>
      ),
    },
    {
      key: 'position',
      label: 'Pos',
      render: (p: Player) => (
        <Badge variant="outline" className="text-[10px] font-semibold border-[var(--border)]">{p.position}</Badge>
      ),
    },
    {
      key: 'age',
      label: 'Age',
      render: (p: Player) => (
        <span className="text-sm text-[var(--text-secondary)]">{p.age}</span>
      ),
    },
    {
      key: 'overall_rating',
      label: 'OVR',
      stat: true,
      render: (p: Player) => <RatingBadge rating={p.overall_rating} size="sm" />,
    },
    {
      key: 'potential',
      label: 'Dev',
      render: (p: Player) => <DevBadge potential={p.potential} />,
    },
    ...(isOpponent
      ? [{
          key: 'trade',
          label: 'Trade',
          align: 'right' as const,
          width: 'w-24',
          render: (p: Player) => (
            <button
              className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
              onClick={(e) => {
                e.stopPropagation();
                setTradePlayerId(p.id);
                setTradePlayerName(`${p.first_name} ${p.last_name}`);
                setTradeModalOpen(true);
              }}
            >
              <ArrowLeftRight className="h-3 w-3" /> Trade
            </button>
          ),
        }]
      : []),
  ];

  // Compute quick team stats for the header
  const avgOvr = players.length > 0
    ? Math.round(players.reduce((s, p) => s + p.overall_rating, 0) / players.length) : 0;
  const rosterCount = players.length;
  const starCount = players.filter(p => p.overall_rating >= 85).length;

  return (
    <div className="space-y-6 -mt-6">
      {/* Team Header — same layout as player page */}
      <div
        className="-mx-4 sm:-mx-6"
        style={{ width: '100vw', marginLeft: 'calc(-50vw + 50%)' }}
      >
        {/* Top color strip */}
        <div className="h-1" style={{ backgroundColor: teamColor }} />

        {/* Main header */}
        <div className="bg-[var(--bg-surface)]">
          <div className="flex items-stretch" style={{ minHeight: '180px' }}>
            {/* Abbreviation area with team color + angled strips */}
            <div className="relative shrink-0 w-56 sm:w-72 hidden sm:block overflow-hidden">
              <div
                className="absolute inset-0"
                style={{
                  background: `linear-gradient(135deg, ${teamColor} 0%, ${teamColor2} 100%)`,
                }}
              />

              {/* Big oversized angled abbreviation */}
              <div className="absolute inset-0 flex items-center justify-center overflow-hidden" style={{ zIndex: 5 }}>
                <span
                  className="text-[120px] sm:text-[160px] font-black text-white/15 tracking-wider leading-none select-none"
                  style={{ transform: 'rotate(-12deg) translateY(10px)' }}
                >
                  {team.abbreviation}
                </span>
              </div>

              {/* Angled strips — same as player page */}
              <div
                className="absolute -top-2 -bottom-2 left-[15%] w-[6px]"
                style={{
                  background: 'rgba(255,255,255,0.15)',
                  transform: 'skewX(-8deg)',
                  zIndex: 8,
                }}
              />
              <div
                className="absolute -top-2 -bottom-2 right-[20px] w-[10px]"
                style={{
                  background: `${teamColor}66`,
                  transform: 'skewX(-8deg)',
                  boxShadow: '-2px 0 4px rgba(0,0,0,0.1)',
                  zIndex: 10,
                }}
              />
              <div
                className="absolute -top-2 -bottom-2 right-[-8px] w-[35px]"
                style={{
                  background: 'var(--bg-surface)',
                  transform: 'skewX(-8deg)',
                  boxShadow: '-4px 0 10px rgba(0,0,0,0.15)',
                  zIndex: 12,
                }}
              />
            </div>

            {/* Name + meta */}
            <div className="flex-1 min-w-0 px-8 py-8 flex flex-col justify-center">
              <div className="flex items-start gap-6">
                <div className="flex-1 min-w-0">
                  <h1 className="leading-none">
                    <span className="block text-xl sm:text-2xl font-normal text-[var(--text-secondary)]">{team.city}</span>
                    <span className="block text-3xl sm:text-5xl font-black text-[var(--text-primary)] uppercase tracking-tight">{team.name}</span>
                  </h1>
                  <p className="text-sm text-[var(--text-secondary)] mt-3">
                    {team.conference} {team.division} &middot; {team.wins}-{team.losses}{team.ties > 0 ? `-${team.ties}` : ''}
                  </p>
                </div>

                {/* OVR + OFF + DEF */}
                <div className="shrink-0 flex items-center gap-3">
                  <div className="flex flex-col items-center bg-[var(--bg-elevated)] border border-[var(--border)] rounded-lg px-5 py-3">
                    <span className="text-4xl font-bold text-[var(--text-primary)] leading-none">{team.overall_rating}</span>
                    <span className="text-[9px] font-bold uppercase tracking-widest text-[var(--text-muted)] mt-1">OVR</span>
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <div className="flex items-center gap-2 bg-[var(--bg-elevated)] border border-[var(--border)] rounded-md px-3 py-1.5">
                      <span className="font-stat text-lg font-bold text-[var(--text-primary)] leading-none">{(team as Record<string, unknown>)?.offense_rating as number ?? '--'}</span>
                      <span className="text-[9px] font-bold uppercase tracking-widest text-[var(--text-muted)]">OFF</span>
                    </div>
                    <div className="flex items-center gap-2 bg-[var(--bg-elevated)] border border-[var(--border)] rounded-md px-3 py-1.5">
                      <span className="font-stat text-lg font-bold text-[var(--text-primary)] leading-none">{(team as Record<string, unknown>)?.defense_rating as number ?? '--'}</span>
                      <span className="text-[9px] font-bold uppercase tracking-widest text-[var(--text-muted)]">DEF</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/* Bio details column */}
            <div className="hidden lg:flex flex-col justify-center gap-3 px-8 py-6 border-l border-[var(--border)] min-w-[200px]">
              <DetailLine label="ROSTER" value={`${rosterCount} players`} />
              <DetailLine label="AVG OVR" value={String(avgOvr)} />
              <DetailLine label="STARS" value={`${starCount} (85+ OVR)`} />
            </div>

            {/* Right: Per-game stat rankings */}
            {(() => {
              const rankings = (team as any).rankings;
              const ordinal = (n: number) => {
                const s = ['th', 'st', 'nd', 'rd'];
                const v = n % 100;
                return n + (s[(v - 20) % 10] || s[v] || s[0]);
              };
              if (!rankings || !rankings.pass_rank) return null;
              return (
                <div className="hidden lg:flex flex-col shrink-0 min-w-[320px]">
                  <div
                    className="px-4 py-1.5 text-center text-xs font-bold uppercase tracking-wider text-white"
                    style={{ backgroundColor: teamColor }}
                  >
                    Team Rankings
                  </div>
                  <div className="flex-1 flex items-center justify-center gap-6 px-6 py-4">
                    <div className="text-center">
                      <p className="text-2xl font-bold text-[var(--text-primary)] uppercase">{ordinal(rankings.pass_rank)}</p>
                      <p className="text-[11px] text-[var(--text-muted)]">PasY/G ({rankings.pass_ypg})</p>
                    </div>
                    <div className="text-center">
                      <p className="text-2xl font-bold text-[var(--text-primary)] uppercase">{ordinal(rankings.rush_rank)}</p>
                      <p className="text-[11px] text-[var(--text-muted)]">RusY/G ({rankings.rush_ypg})</p>
                    </div>
                    <div className="text-center">
                      <p className="text-2xl font-bold text-[var(--text-primary)] uppercase">{ordinal(rankings.total_rank)}</p>
                      <p className="text-[11px] text-[var(--text-muted)]">Yds/G ({rankings.total_ypg})</p>
                    </div>
                  </div>
                </div>
              );
            })()}
          </div>
        </div>

        {/* Info strip */}
        {(() => {
          const coaches = (team as any).coaches as any[] | undefined;
          const headCoach = coaches?.find((c: any) => c.is_human || c.role === 'head_coach') ?? coaches?.[0];
          const capTotal = (team as any).salary_cap ?? 0;
          const capUsed = (team as any).cap_used ?? 0;
          const capSpace = capTotal - capUsed;
          const formatCap = (n: number) => {
            if (Math.abs(n) >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`;
            if (Math.abs(n) >= 1_000) return `$${(n / 1_000).toFixed(0)}K`;
            return `$${n}`;
          };
          return (
            <div className="bg-[#222222] text-white">
              <div className="flex items-center justify-between px-8 py-3.5">
                <div className="flex items-center gap-6 text-sm">
                  {headCoach && (
                    <span className="text-white/70">
                      Coach: <span className="font-semibold text-white">{headCoach.name}</span>
                    </span>
                  )}
                  <span className="text-white/70">
                    Cap Space: <span className={`font-semibold ${capSpace > 0 ? 'text-white' : 'text-red-400'}`}>{formatCap(capSpace)}</span>
                  </span>
                </div>
                {team.streak && (
                  <span className="text-sm text-white/70">
                    Streak: <span className="font-semibold text-white">{team.streak}</span>
                  </span>
                )}
              </div>
            </div>
          );
        })()}
      </div>

      {/* Tabs */}
      <div className="mt-6">
        <SportsTabs
          tabs={[
            { key: 'roster', label: 'Roster', icon: Users },
            { key: 'depth', label: 'Depth Chart', icon: LayoutGrid },
          ]}
          activeTab={activeTab}
          onChange={setActiveTab}
          accentColor={teamColor}
        />
      </div>

      {/* Roster Tab */}
      {activeTab === 'roster' && (
        <div className="mt-4 space-y-3">
          <div className="flex items-center gap-3">
            <Select value={posFilter} onValueChange={(v) => v && setPosFilter(v)}>
              <SelectTrigger className="w-32 border-[var(--border)] bg-[var(--bg-elevated)]">
                <SelectValue placeholder="Position" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Positions</SelectItem>
                {positions.map((p) => (
                  <SelectItem key={p} value={p}>{p}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <span className="text-xs text-[var(--text-muted)]">{filtered.length} players</span>
          </div>

          <DataTable
            columns={rosterColumns}
            data={[...filtered].sort((a, b) => b.overall_rating - a.overall_rating)}
            accentColor={teamColor}
            rowKey={(p: Player) => p.id}
            onRowClick={(p: Player) => {
              navigate(`/player/${p.id}`);
            }}
            emptyMessage="No players match this filter"
          />
        </div>
      )}

      {/* Depth Chart Tab */}
      {activeTab === 'depth' && (
        <div className="mt-4">
          {depthChart ? (
            <DepthChartView depthChart={depthChart} teamColor={teamColor} />
          ) : (
            <EmptyBlock
              icon={LayoutGrid}
              title="Depth Chart Unavailable"
              description="Depth chart data is not available for this team."
            />
          )}
        </div>
      )}

      <AcquireTradeModal
        playerId={tradePlayerId}
        playerName={tradePlayerName}
        open={tradeModalOpen}
        onOpenChange={setTradeModalOpen}
      />
    </div>
  );
}

function DetailLine({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline gap-4">
      <span className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] w-20 shrink-0">{label}</span>
      <span className="text-sm font-medium text-[var(--text-primary)]">{value}</span>
    </div>
  );
}
