import { useMemo, useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useStandings } from '@/hooks/useApi';
import { useNavigate } from 'react-router-dom';
import { TeamBadge } from '@/components/TeamBadge';
import { motion } from 'framer-motion';
import { Trophy } from 'lucide-react';
import { PageLayout, PageHeader, SportsTabs, DataTable, Section } from '@/components/ui/sports-ui';
import type { StandingsTeam } from '@/api/client';

/**
 * Calculate clinch status for each team.
 * x = clinched playoff spot
 * y = clinched division
 * z = clinched #1 seed / home field
 * e = eliminated from playoff contention
 */
function calculateClinchStatus(
  allTeams: StandingsTeam[],
  divisions: Record<string, Record<string, StandingsTeam[]>>,
  totalWeeks: number
): Record<number, { tag: string; label: string } | null> {
  const status: Record<number, { tag: string; label: string } | null> = {};

  // Need at least a few weeks of data
  const maxGames = Math.max(...allTeams.map(t => t.wins + t.losses + (t.ties ?? 0)));
  if (maxGames < 6) return status;

  const gamesRemaining = totalWeeks - maxGames;

  // Conference groupings
  const confTeams: Record<string, StandingsTeam[]> = {};
  for (const [conf, divs] of Object.entries(divisions)) {
    confTeams[conf] = [];
    for (const teams of Object.values(divs)) {
      confTeams[conf].push(...(teams as StandingsTeam[]));
    }
    confTeams[conf].sort((a, b) => (b.win_pct ?? 0) - (a.win_pct ?? 0));
  }

  // Division leaders
  for (const [conf, divs] of Object.entries(divisions)) {
    for (const [_div, teams] of Object.entries(divs)) {
      const sorted = [...(teams as StandingsTeam[])].sort((a, b) => (b.win_pct ?? 0) - (a.win_pct ?? 0));
      if (sorted.length < 2) continue;

      const leader = sorted[0];
      const second = sorted[1];

      // Can second place catch the leader?
      const leaderMinWins = leader.wins;
      const secondMaxWins = second.wins + gamesRemaining;

      if (leaderMinWins > secondMaxWins) {
        // Clinched division
        status[leader.id] = { tag: 'y', label: 'Clinched Division' };
      }

      // Check elimination — can last place team make playoffs?
      const confSorted = confTeams[conf] ?? [];
      const playoffCutoff = confSorted.length >= 7 ? (confSorted[6]?.wins ?? 0) : 0;

      for (const t of sorted) {
        const tMaxWins = t.wins + gamesRemaining;
        if (tMaxWins < playoffCutoff && gamesRemaining <= 4) {
          if (!status[t.id]) {
            status[t.id] = { tag: 'e', label: 'Eliminated' };
          }
        }
      }
    }
  }

  // Check for #1 seed clinch
  for (const [_conf, teams] of Object.entries(confTeams)) {
    if (teams.length < 2) continue;
    const best = teams[0];
    const second = teams[1];
    const secondMaxWins = second.wins + gamesRemaining;

    if (best.wins > secondMaxWins && status[best.id]?.tag === 'y') {
      status[best.id] = { tag: 'z', label: 'Clinched #1 Seed' };
    }
  }

  // Clinched playoff spot (top 7 in conference, simplified)
  for (const [_conf2, teams] of Object.entries(confTeams)) {
    // 7 teams make playoffs per conference (4 div winners + 3 wild cards)
    const playoffSpots = Math.min(7, Math.floor(teams.length / 2));
    for (let i = 0; i < playoffSpots; i++) {
      const t = teams[i];
      if (status[t.id]) continue; // already has higher clinch

      // Team has clinched if they can't be caught by the team just outside
      if (i + 1 < teams.length) {
        const bubble = teams[playoffSpots]; // first team out
        if (bubble) {
          const bubbleMaxWins = bubble.wins + gamesRemaining;
          if (t.wins > bubbleMaxWins) {
            status[t.id] = { tag: 'x', label: 'Clinched Playoff Spot' };
          }
        }
      }
    }
  }

  return status;
}

const CLINCH_STYLES: Record<string, { bg: string; text: string; border: string; label: string }> = {
  z: { bg: 'bg-yellow-500/15', text: 'text-yellow-400', border: 'border-yellow-500/30', label: '#1 Seed' },
  y: { bg: 'bg-green-500/15', text: 'text-green-400', border: 'border-green-500/30', label: 'Division' },
  x: { bg: 'bg-blue-500/15', text: 'text-blue-400', border: 'border-blue-500/30', label: 'Playoffs' },
  e: { bg: 'bg-red-500/15', text: 'text-red-400', border: 'border-red-500/30', label: 'Eliminated' },
};

function ClinchBadge({ tag }: { tag: string }) {
  const style = CLINCH_STYLES[tag];
  if (!style) return null;
  return (
    <span
      className={`inline-flex items-center justify-center rounded px-1.5 py-0 text-[9px] font-extrabold uppercase leading-[18px] border ${style.bg} ${style.text} ${style.border}`}
    >
      {tag}
    </span>
  );
}

function ClinchLegend() {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[10px] text-[var(--text-muted)]">
      {Object.entries(CLINCH_STYLES).map(([tag, style]) => (
        <span key={tag} className="flex items-center gap-1">
          <span
            className={`inline-flex items-center justify-center rounded px-1 py-0 text-[9px] font-extrabold uppercase leading-[16px] border ${style.bg} ${style.text} ${style.border}`}
          >
            {tag}
          </span>
          {style.label}
        </span>
      ))}
    </div>
  );
}

/** Build DataTable columns for standings, with optional conf/div columns */
function useStandingsColumns(
  myTeamId: number,
  clinchStatus: Record<number, { tag: string; label: string } | null>,
  _navigate: (path: string) => void,
  opts: { showConf?: boolean; showDiv?: boolean } = {}
) {
  return useMemo(() => {
    const cols: Parameters<typeof DataTable<StandingsTeam>>[0]['columns'] = [
      {
        key: 'rank',
        label: '#',
        align: 'center',
        width: 'w-10',
        render: (_row, idx) => (
          <span className="font-stat text-[11px] text-[var(--text-muted)]">{idx + 1}</span>
        ),
      },
      {
        key: 'team',
        label: 'Team',
        align: 'left',
        render: (row) => {
          const isMine = row.id === myTeamId;
          const clinch = clinchStatus[row.id] ?? null;
          return (
            <div className="flex items-center gap-2.5">
              <TeamBadge
                abbreviation={row.abbreviation}
                primaryColor={row.primary_color}
                secondaryColor={row.secondary_color}
                size="xs"
              />
              <span
                className={`text-[13px] font-semibold whitespace-nowrap ${
                  isMine
                    ? 'font-bold text-[var(--accent-blue)]'
                    : 'text-[var(--text-primary)]'
                }`}
              >
                {row.city} {row.name}
              </span>
              {clinch && <ClinchBadge tag={clinch.tag} />}
            </div>
          );
        },
      },
    ];

    if (opts.showConf) {
      cols.push({
        key: 'conference',
        label: 'Conf',
        align: 'center',
        render: (row) => (
          <span className="text-xs text-[var(--text-muted)]">{row.conference}</span>
        ),
      });
    }

    if (opts.showDiv) {
      cols.push({
        key: 'division',
        label: 'Div',
        align: 'center',
        render: (row) => (
          <span className="text-xs text-[var(--text-muted)]">{row.division}</span>
        ),
      });
    }

    cols.push(
      {
        key: 'wins',
        label: 'W',
        align: 'center',
        stat: true,
        width: 'w-12',
        render: (row) => (
          <span className="font-stat text-sm text-green-400">{row.wins}</span>
        ),
      },
      {
        key: 'losses',
        label: 'L',
        align: 'center',
        stat: true,
        width: 'w-12',
        render: (row) => (
          <span className="font-stat text-sm text-red-400">{row.losses}</span>
        ),
      },
      {
        key: 'win_pct',
        label: 'PCT',
        align: 'center',
        stat: true,
        width: 'w-14',
        render: (row) => (
          <span className="font-stat text-xs text-[var(--text-secondary)]">
            {row.win_pct?.toFixed(3) ?? '.000'}
          </span>
        ),
      },
      {
        key: 'points_for',
        label: 'PF',
        align: 'center',
        stat: true,
        width: 'w-14',
        render: (row) => (
          <span className="font-stat text-xs text-[var(--text-secondary)]">{row.points_for}</span>
        ),
      },
      {
        key: 'points_against',
        label: 'PA',
        align: 'center',
        stat: true,
        width: 'w-14',
        render: (row) => (
          <span className="font-stat text-xs text-[var(--text-secondary)]">{row.points_against}</span>
        ),
      },
      {
        key: 'point_diff',
        label: 'DIFF',
        align: 'center',
        stat: true,
        width: 'w-14',
        render: (row) => {
          const diff = row.point_diff ?? 0;
          const color =
            diff > 0 ? 'text-green-400' : diff < 0 ? 'text-red-400' : 'text-[var(--text-muted)]';
          return (
            <span className={`font-stat text-xs ${color}`}>
              {diff > 0 ? '+' : ''}
              {diff}
            </span>
          );
        },
      },
      {
        key: 'streak',
        label: 'STK',
        align: 'center',
        width: 'w-12',
        render: (row) => (
          <span className="text-xs text-[var(--text-muted)]">{row.streak || '--'}</span>
        ),
      }
    );

    return cols;
  }, [myTeamId, clinchStatus, opts.showConf, opts.showDiv]);
}

export default function Standings() {
  const league = useAuthStore((s) => s.league);
  const myTeam = useAuthStore((s) => s.team);
  const { data, isLoading } = useStandings(league?.id);
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState('division');

  const divisions = data?.divisions ?? {};
  const conferences = data?.conferences ?? {};

  // Build league-wide standings (all teams sorted by record)
  const leagueWide = useMemo(() => {
    const all: StandingsTeam[] = [];
    for (const divs of Object.values(divisions)) {
      for (const teams of Object.values(divs)) {
        all.push(...(teams as StandingsTeam[]));
      }
    }
    return all.sort((a, b) => (b.win_pct ?? 0) - (a.win_pct ?? 0) || (b.point_diff ?? 0) - (a.point_diff ?? 0));
  }, [divisions]);

  // Calculate clinch statuses
  const clinchStatus = useMemo(() => {
    const totalWeeks = 18; // regular season weeks
    return calculateClinchStatus(leagueWide, divisions, totalWeeks);
  }, [leagueWide, divisions]);

  const myTeamId = myTeam?.id ?? 0;

  // Column sets for each view
  const divisionCols = useStandingsColumns(myTeamId, clinchStatus, navigate);
  const conferenceCols = useStandingsColumns(myTeamId, clinchStatus, navigate, { showDiv: true });
  const leagueCols = useStandingsColumns(myTeamId, clinchStatus, navigate, { showConf: true, showDiv: true });

  if (isLoading) {
    return (
      <PageLayout>
        <p className="text-[var(--text-secondary)]">Loading standings...</p>
      </PageLayout>
    );
  }

  const seasonYear = league?.season_year ? `${league.season_year} Season` : undefined;

  return (
    <PageLayout>
      <PageHeader
        title="Standings"
        icon={Trophy}
        accentColor="var(--accent-gold)"
        meta={seasonYear}
        actions={<ClinchLegend />}
      />

      <SportsTabs
        tabs={[
          { key: 'division', label: 'Division' },
          { key: 'conference', label: 'Conference' },
          { key: 'league', label: 'League' },
        ]}
        activeTab={activeTab}
        onChange={setActiveTab}
        accentColor="var(--accent-gold)"
      />

      <div className="mt-5">
        {/* Division Standings */}
        {activeTab === 'division' && (
          <div className="grid gap-5 lg:grid-cols-2">
            {Object.entries(divisions).map(([conf, divs], ci) =>
              Object.entries(divs).map(([div, teams], di) => (
                <motion.div
                  key={`${conf}-${div}`}
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.35, delay: (ci * 2 + di) * 0.06 }}
                >
                  <Section
                    title={`${conf} ${div}`}
                    accentColor="var(--accent-gold)"
                  >
                    <DataTable
                      columns={divisionCols}
                      data={teams as StandingsTeam[]}
                      rowKey={(row) => row.id}
                      onRowClick={(row) => navigate(`/team/${row.id}`)}
                      striped
                    />
                  </Section>
                </motion.div>
              ))
            )}
          </div>
        )}

        {/* Conference Standings */}
        {activeTab === 'conference' && (
          <div className="grid gap-5 lg:grid-cols-2">
            {Object.entries(conferences).map(([conf, teams], ci) => (
              <motion.div
                key={conf}
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.35, delay: ci * 0.1 }}
              >
                <Section
                  title={`${conf} Conference`}
                  accentColor="var(--accent-gold)"
                >
                  <DataTable
                    columns={conferenceCols}
                    data={teams as StandingsTeam[]}
                    rowKey={(row) => row.id}
                    onRowClick={(row) => navigate(`/team/${row.id}`)}
                    striped
                  />
                </Section>
              </motion.div>
            ))}
          </div>
        )}

        {/* League-Wide Standings */}
        {activeTab === 'league' && (
          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.35 }}
          >
            <Section
              title="League Standings"
              accentColor="var(--accent-gold)"
            >
              <DataTable
                columns={leagueCols}
                data={leagueWide}
                rowKey={(row) => row.id}
                onRowClick={(row) => navigate(`/team/${row.id}`)}
                striped
              />
            </Section>
          </motion.div>
        )}
      </div>
    </PageLayout>
  );
}
