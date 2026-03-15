import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { fantasyApi, type FantasyRosterPlayer, type FantasyMatchup as FantasyMatchupType, type FantasyDraftPick } from '@/api/client';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Trophy, Users, ArrowLeftRight, ClipboardList, BarChart3, Calendar,
  Play, UserPlus, ChevronDown, ChevronUp, Crown, Star,
} from 'lucide-react';
import { toast } from 'sonner';

type Tab = 'standings' | 'roster' | 'matchups' | 'draft' | 'available' | 'trades' | 'transactions';

export default function FantasyLeague() {
  const { id } = useParams<{ id: string }>();
  const leagueId = Number(id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<Tab>('standings');

  const { data: league, isLoading } = useQuery({
    queryKey: ['fantasy-league', leagueId],
    queryFn: () => fantasyApi.getLeague(leagueId),
    enabled: !!leagueId,
  });

  const draftMutation = useMutation({
    mutationFn: () => fantasyApi.draft(leagueId),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['fantasy-league', leagueId] });
      toast.success(`Draft complete! ${data.total_picks} picks made.`);
      setTab('draft');
    },
    onError: (err: Error) => toast.error(err.message),
  });

  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="h-8 w-48 animate-pulse rounded bg-[var(--bg-elevated)]" />
        <div className="h-64 animate-pulse rounded-xl bg-[var(--bg-elevated)]" />
      </div>
    );
  }

  if (!league) {
    return <div className="text-[var(--text-secondary)]">League not found</div>;
  }

  const tabs: { key: Tab; label: string; icon: typeof Trophy }[] = [
    { key: 'standings', label: 'Standings', icon: Trophy },
    { key: 'roster', label: 'My Roster', icon: Users },
    { key: 'matchups', label: 'Matchups', icon: Calendar },
    { key: 'draft', label: 'Draft Board', icon: ClipboardList },
    { key: 'available', label: 'Free Agents', icon: UserPlus },
    { key: 'trades', label: 'Trades', icon: ArrowLeftRight },
    { key: 'transactions', label: 'Activity', icon: BarChart3 },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <button onClick={() => navigate('/fantasy')} className="text-xs text-[var(--accent-blue)] hover:underline mb-1 block">
            &larr; All Fantasy Leagues
          </button>
          <h1 className="font-display text-2xl">{league.name}</h1>
          <div className="mt-1 flex items-center gap-3 text-sm text-[var(--text-secondary)]">
            <Badge className={league.status === 'active' ? 'bg-green-500/20 text-green-400' : league.status === 'playoffs' ? 'bg-purple-500/20 text-purple-400' : 'bg-yellow-500/20 text-yellow-400'}>
              {league.status}
            </Badge>
            <span>{league.num_teams} teams</span>
            <span>{league.scoring_type.toUpperCase()}</span>
            <span className="text-xs">Invite: <code className="bg-[var(--bg-elevated)] px-1.5 py-0.5 rounded">{league.invite_code}</code></span>
          </div>
        </div>
        {league.draft_status === 'pending' && league.my_manager && (
          <button
            onClick={() => draftMutation.mutate()}
            disabled={draftMutation.isPending}
            className="flex items-center gap-2 rounded-lg bg-green-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-green-500 transition-colors"
          >
            <Play className="h-4 w-4" />
            {draftMutation.isPending ? 'Drafting...' : 'Start Draft'}
          </button>
        )}
      </div>

      {/* Tab Nav */}
      <div className="flex gap-1 overflow-x-auto border-b border-[var(--border)] pb-px">
        {tabs.map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={`flex items-center gap-1.5 whitespace-nowrap px-4 py-2.5 text-sm font-medium transition-colors border-b-2 -mb-px ${
              tab === t.key
                ? 'border-[var(--accent-blue)] text-[var(--accent-blue)]'
                : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
            }`}
          >
            <t.icon className="h-3.5 w-3.5" />
            {t.label}
          </button>
        ))}
      </div>

      {/* Tab Content */}
      {tab === 'standings' && <StandingsTab leagueId={leagueId} />}
      {tab === 'roster' && <RosterTab leagueId={leagueId} />}
      {tab === 'matchups' && <MatchupsTab leagueId={leagueId} league={league} />}
      {tab === 'draft' && <DraftTab leagueId={leagueId} />}
      {tab === 'available' && <AvailableTab leagueId={leagueId} />}
      {tab === 'trades' && <TradesTab leagueId={leagueId} />}
      {tab === 'transactions' && <TransactionsTab leagueId={leagueId} />}
    </div>
  );
}

// ── Standings Tab ──────────────────────────────────────────────────────

function StandingsTab({ leagueId }: { leagueId: number }) {
  const { data: standings, isLoading } = useQuery({
    queryKey: ['fantasy-standings', leagueId],
    queryFn: () => fantasyApi.standings(leagueId),
  });

  if (isLoading || !standings) {
    return <div className="animate-pulse h-48 rounded-xl bg-[var(--bg-elevated)]" />;
  }

  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-secondary)]">
              <th className="px-4 py-3 w-8">#</th>
              <th className="px-4 py-3">Manager</th>
              <th className="px-4 py-3 text-center">W</th>
              <th className="px-4 py-3 text-center">L</th>
              <th className="px-4 py-3 text-center">T</th>
              <th className="px-4 py-3 text-right">PF</th>
              <th className="px-4 py-3 text-right">PA</th>
              <th className="px-4 py-3 text-center">Streak</th>
              <th className="px-4 py-3 text-right">PPG</th>
            </tr>
          </thead>
          <tbody>
            {standings.map((m, i) => (
              <tr key={m.id} className="border-b border-[var(--border)]/50 hover:bg-white/[0.02]">
                <td className="px-4 py-3 text-[var(--text-secondary)]">{i + 1}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <div
                      className="h-6 w-6 rounded-full flex items-center justify-center text-[10px] font-bold text-white"
                      style={{ backgroundColor: m.avatar_color }}
                    >
                      {m.owner_name.charAt(0)}
                    </div>
                    <div>
                      <div className="font-medium flex items-center gap-1.5">
                        {m.team_name}
                        {m.is_champion && <Crown className="h-3.5 w-3.5 text-yellow-400" />}
                        {m.is_ai && <Badge className="text-[10px] bg-[var(--bg-elevated)] text-[var(--text-secondary)] px-1">AI</Badge>}
                      </div>
                      <div className="text-xs text-[var(--text-secondary)]">{m.owner_name}</div>
                    </div>
                  </div>
                </td>
                <td className="px-4 py-3 text-center font-semibold text-green-400">{m.wins}</td>
                <td className="px-4 py-3 text-center font-semibold text-red-400">{m.losses}</td>
                <td className="px-4 py-3 text-center text-[var(--text-secondary)]">{m.ties}</td>
                <td className="px-4 py-3 text-right">{m.points_for.toFixed(1)}</td>
                <td className="px-4 py-3 text-right text-[var(--text-secondary)]">{m.points_against.toFixed(1)}</td>
                <td className="px-4 py-3 text-center">
                  {m.streak && (
                    <Badge className={m.streak.startsWith('W') ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'}>
                      {m.streak}
                    </Badge>
                  )}
                </td>
                <td className="px-4 py-3 text-right font-medium">{m.ppg?.toFixed(1) ?? '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

// ── Roster Tab ─────────────────────────────────────────────────────────

function RosterTab({ leagueId }: { leagueId: number }) {
  const { data, isLoading } = useQuery({
    queryKey: ['fantasy-roster', leagueId],
    queryFn: () => fantasyApi.roster(leagueId),
  });

  if (isLoading || !data) {
    return <div className="animate-pulse h-48 rounded-xl bg-[var(--bg-elevated)]" />;
  }

  const starters = data.roster.filter((p) => p.is_starter);
  const bench = data.roster.filter((p) => !p.is_starter);

  return (
    <div className="space-y-4">
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader className="pb-2">
          <CardTitle className="text-sm uppercase tracking-wider text-[var(--text-secondary)]">
            <Star className="inline h-3.5 w-3.5 mr-1" />Starters
          </CardTitle>
        </CardHeader>
        <CardContent>
          <RosterTable players={starters} />
        </CardContent>
      </Card>

      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader className="pb-2">
          <CardTitle className="text-sm uppercase tracking-wider text-[var(--text-secondary)]">Bench</CardTitle>
        </CardHeader>
        <CardContent>
          <RosterTable players={bench} />
        </CardContent>
      </Card>
    </div>
  );
}

function RosterTable({ players }: { players: FantasyRosterPlayer[] }) {
  if (players.length === 0) {
    return <p className="text-sm text-[var(--text-secondary)] py-2">No players</p>;
  }

  const posColors: Record<string, string> = {
    QB: 'text-red-400', RB: 'text-blue-400', WR: 'text-green-400',
    TE: 'text-orange-400', K: 'text-purple-400', DEF: 'text-gray-400',
  };

  return (
    <div className="space-y-1">
      {players.map((p) => (
        <div key={p.player_id} className="flex items-center justify-between rounded px-3 py-2 hover:bg-white/[0.02]">
          <div className="flex items-center gap-3">
            <Badge className={`w-8 justify-center text-[10px] font-bold ${posColors[p.position] || 'text-gray-400'} bg-white/5`}>
              {p.position}
            </Badge>
            <div>
              <span className="font-medium text-sm">{p.first_name} {p.last_name}</span>
              <span className="ml-2 text-xs text-[var(--text-secondary)]">{p.team_abbr} - {p.overall_rating} OVR</span>
            </div>
          </div>
          <div className="flex items-center gap-3 text-xs text-[var(--text-secondary)]">
            <span className="uppercase">{p.roster_slot}</span>
            {p.points !== undefined && (
              <span className="font-semibold text-[var(--text-primary)]">{p.points.toFixed(1)} pts</span>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Matchups Tab ───────────────────────────────────────────────────────

function MatchupsTab({ leagueId, league }: { leagueId: number; league: { regular_season_end_week: number; championship_week: number } }) {
  const [week, setWeek] = useState(1);

  const { data: matchups, isLoading } = useQuery({
    queryKey: ['fantasy-matchups', leagueId, week],
    queryFn: () => fantasyApi.matchups(leagueId, week),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <button
          onClick={() => setWeek(Math.max(1, week - 1))}
          disabled={week <= 1}
          className="rounded border border-[var(--border)] p-1.5 text-[var(--text-secondary)] hover:text-[var(--text-primary)] disabled:opacity-30"
        >
          <ChevronDown className="h-4 w-4 rotate-90" />
        </button>
        <span className="text-sm font-semibold min-w-[80px] text-center">Week {week}</span>
        <button
          onClick={() => setWeek(week + 1)}
          disabled={week >= league.championship_week}
          className="rounded border border-[var(--border)] p-1.5 text-[var(--text-secondary)] hover:text-[var(--text-primary)] disabled:opacity-30"
        >
          <ChevronUp className="h-4 w-4 rotate-90" />
        </button>
      </div>

      {isLoading ? (
        <div className="animate-pulse h-32 rounded-xl bg-[var(--bg-elevated)]" />
      ) : !matchups || matchups.length === 0 ? (
        <p className="text-sm text-[var(--text-secondary)]">No matchups this week</p>
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {matchups.map((m) => (
            <MatchupCard key={m.id} matchup={m} />
          ))}
        </div>
      )}
    </div>
  );
}

function MatchupCard({ matchup }: { matchup: FantasyMatchupType }) {
  const score1 = matchup.manager1_score;
  const score2 = matchup.manager2_score;
  const hasScores = score1 !== null && score2 !== null;
  const team1Winning = hasScores && (score1 ?? 0) > (score2 ?? 0);
  const team2Winning = hasScores && (score2 ?? 0) > (score1 ?? 0);

  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
      <CardContent className="p-4">
        {matchup.is_playoff && (
          <Badge className="mb-2 bg-purple-500/20 text-purple-400 text-[10px]">
            {matchup.is_championship ? 'Championship' : 'Playoff'}
          </Badge>
        )}
        <div className="flex items-center justify-between">
          <div className={`flex-1 ${team1Winning ? 'font-semibold' : ''}`}>
            <div className="text-sm">{matchup.team1_name}</div>
            <div className="text-xs text-[var(--text-secondary)]">{matchup.owner1_name}</div>
          </div>
          <div className="flex items-center gap-3 px-4">
            <span className={`text-lg font-bold ${team1Winning ? 'text-green-400' : ''}`}>
              {hasScores ? score1?.toFixed(1) : '-'}
            </span>
            <span className="text-xs text-[var(--text-secondary)]">vs</span>
            <span className={`text-lg font-bold ${team2Winning ? 'text-green-400' : ''}`}>
              {hasScores ? score2?.toFixed(1) : '-'}
            </span>
          </div>
          <div className={`flex-1 text-right ${team2Winning ? 'font-semibold' : ''}`}>
            <div className="text-sm">{matchup.team2_name}</div>
            <div className="text-xs text-[var(--text-secondary)]">{matchup.owner2_name}</div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

// ── Draft Tab ──────────────────────────────────────────────────────────

function DraftTab({ leagueId }: { leagueId: number }) {
  const { data: picks, isLoading } = useQuery({
    queryKey: ['fantasy-draft', leagueId],
    queryFn: () => fantasyApi.draftResults(leagueId),
  });

  if (isLoading) {
    return <div className="animate-pulse h-48 rounded-xl bg-[var(--bg-elevated)]" />;
  }

  if (!picks || picks.length === 0) {
    return (
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardContent className="p-8 text-center">
          <ClipboardList className="mx-auto h-8 w-8 text-[var(--text-secondary)] mb-2" />
          <p className="text-sm text-[var(--text-secondary)]">Draft hasn't started yet. Hit "Start Draft" to begin!</p>
        </CardContent>
      </Card>
    );
  }

  // Group by round
  const rounds: Record<number, FantasyDraftPick[]> = {};
  picks.forEach((p) => {
    const round = Number(p.round ?? JSON.parse(p.details ?? '{}').round ?? 1);
    if (!rounds[round]) rounds[round] = [];
    rounds[round].push(p);
  });

  const posColors: Record<string, string> = {
    QB: 'border-l-red-400', RB: 'border-l-blue-400', WR: 'border-l-green-400',
    TE: 'border-l-orange-400', K: 'border-l-purple-400',
  };

  return (
    <div className="space-y-4">
      {Object.entries(rounds).map(([round, roundPicks]) => (
        <Card key={round} className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardHeader className="pb-1">
            <CardTitle className="text-xs uppercase tracking-wider text-[var(--text-secondary)]">Round {round}</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
              {roundPicks.map((pick, i) => {
                const pos = pick.position ?? pick.player_pos;
                const name = pick.player_name ?? `${pick.first_name} ${pick.last_name}`;
                const ovr = pick.overall ?? pick.overall_rating;
                return (
                  <div
                    key={i}
                    className={`flex items-center gap-2 rounded border-l-2 ${posColors[pos] || 'border-l-gray-500'} bg-white/[0.02] px-3 py-2`}
                  >
                    <Badge className="w-7 justify-center text-[10px] bg-white/5">{pos}</Badge>
                    <div className="flex-1 min-w-0">
                      <div className="text-xs font-medium truncate">{name}</div>
                      <div className="text-[10px] text-[var(--text-secondary)]">
                        {pick.team_name ?? pick.owner_name} &middot; {ovr} OVR
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

// ── Available Players Tab ──────────────────────────────────────────────

function AvailableTab({ leagueId }: { leagueId: number }) {
  const [position, setPosition] = useState<string>('');

  const { data: players, isLoading } = useQuery({
    queryKey: ['fantasy-available', leagueId, position],
    queryFn: () => fantasyApi.availablePlayers(leagueId, position || undefined),
  });

  return (
    <div className="space-y-4">
      <div className="flex gap-2">
        {['', 'QB', 'RB', 'WR', 'TE', 'K'].map((pos) => (
          <button
            key={pos}
            onClick={() => setPosition(pos)}
            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${
              position === pos
                ? 'bg-[var(--accent-blue)] text-white'
                : 'bg-[var(--bg-elevated)] text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
            }`}
          >
            {pos || 'All'}
          </button>
        ))}
      </div>

      {isLoading ? (
        <div className="animate-pulse h-48 rounded-xl bg-[var(--bg-elevated)]" />
      ) : !players || players.length === 0 ? (
        <p className="text-sm text-[var(--text-secondary)]">No available players</p>
      ) : (
        <Card className="border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-secondary)]">
                  <th className="px-4 py-3">Player</th>
                  <th className="px-4 py-3 text-center">POS</th>
                  <th className="px-4 py-3 text-center">OVR</th>
                  <th className="px-4 py-3">Team</th>
                </tr>
              </thead>
              <tbody>
                {players.slice(0, 50).map((p) => (
                  <tr key={p.id} className="border-b border-[var(--border)]/50 hover:bg-white/[0.02]">
                    <td className="px-4 py-2.5 font-medium">{p.first_name} {p.last_name}</td>
                    <td className="px-4 py-2.5 text-center">
                      <Badge className="bg-white/5 text-[10px]">{p.position}</Badge>
                    </td>
                    <td className="px-4 py-2.5 text-center">{p.overall_rating}</td>
                    <td className="px-4 py-2.5 text-[var(--text-secondary)]">{(p as unknown as Record<string, unknown>).team_abbr as string ?? '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  );
}

// ── Trades Tab ─────────────────────────────────────────────────────────

function TradesTab({ leagueId }: { leagueId: number }) {
  const { data: trades, isLoading } = useQuery({
    queryKey: ['fantasy-trades', leagueId],
    queryFn: () => fantasyApi.trades(leagueId),
  });

  if (isLoading) return <div className="animate-pulse h-32 rounded-xl bg-[var(--bg-elevated)]" />;

  if (!trades || trades.length === 0) {
    return (
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardContent className="p-8 text-center">
          <ArrowLeftRight className="mx-auto h-8 w-8 text-[var(--text-secondary)] mb-2" />
          <p className="text-sm text-[var(--text-secondary)]">No trade activity yet</p>
        </CardContent>
      </Card>
    );
  }

  const statusColors: Record<string, string> = {
    pending: 'bg-yellow-500/20 text-yellow-400',
    accepted: 'bg-green-500/20 text-green-400',
    rejected: 'bg-red-500/20 text-red-400',
  };

  return (
    <div className="space-y-3">
      {trades.map((t) => (
        <Card key={t.id} className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="p-4">
            <div className="flex items-center justify-between mb-2">
              <span className="text-xs text-[var(--text-secondary)]">
                {t.proposer_team} &rarr; {t.recipient_team}
              </span>
              <Badge className={statusColors[t.status] || ''}>{t.status}</Badge>
            </div>
            <div className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <div className="text-[10px] uppercase tracking-wider text-[var(--text-secondary)] mb-1">Sends</div>
                {t.players_offered.map((p) => (
                  <div key={p.id} className="text-xs">{p.first_name} {p.last_name} ({p.position}, {p.overall_rating})</div>
                ))}
              </div>
              <div>
                <div className="text-[10px] uppercase tracking-wider text-[var(--text-secondary)] mb-1">Receives</div>
                {t.players_requested.map((p) => (
                  <div key={p.id} className="text-xs">{p.first_name} {p.last_name} ({p.position}, {p.overall_rating})</div>
                ))}
              </div>
            </div>
            {t.message && <p className="mt-2 text-xs italic text-[var(--text-secondary)]">"{t.message}"</p>}
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

// ── Transactions Tab ───────────────────────────────────────────────────

function TransactionsTab({ leagueId }: { leagueId: number }) {
  const { data: transactions, isLoading } = useQuery({
    queryKey: ['fantasy-transactions', leagueId],
    queryFn: () => fantasyApi.transactions(leagueId),
  });

  if (isLoading) return <div className="animate-pulse h-32 rounded-xl bg-[var(--bg-elevated)]" />;

  if (!transactions || transactions.length === 0) {
    return (
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardContent className="p-8 text-center">
          <p className="text-sm text-[var(--text-secondary)]">No transactions yet</p>
        </CardContent>
      </Card>
    );
  }

  const typeLabels: Record<string, string> = {
    draft: 'Drafted', waiver_add: 'Waiver Claim', add_drop: 'Add/Drop',
    trade: 'Trade', free_agent: 'Free Agent',
  };

  const typeColors: Record<string, string> = {
    draft: 'text-blue-400', waiver_add: 'text-green-400', add_drop: 'text-orange-400',
    trade: 'text-purple-400', free_agent: 'text-yellow-400',
  };

  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
      <div className="divide-y divide-[var(--border)]/50">
        {transactions.map((t) => (
          <div key={t.id} className="flex items-center gap-3 px-4 py-3">
            <Badge className={`text-[10px] ${typeColors[t.type] || ''} bg-white/5`}>
              {typeLabels[t.type] || t.type}
            </Badge>
            <div className="flex-1">
              <span className="text-sm font-medium">{t.owner_name}</span>
              {t.player_first && (
                <span className="text-sm text-[var(--text-secondary)]">
                  {' '}— {t.player_first} {t.player_last} ({t.player_pos})
                </span>
              )}
            </div>
            <span className="text-xs text-[var(--text-secondary)]">Wk {t.week}</span>
          </div>
        ))}
      </div>
    </Card>
  );
}
