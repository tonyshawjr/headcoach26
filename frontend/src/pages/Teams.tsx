import { Link } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { teamApi, advanceApi, type ReadyStatus } from '@/api/client';
import { useQuery } from '@tanstack/react-query';
import { TeamBadge } from '@/components/TeamBadge';
import { Check, Clock } from 'lucide-react';

interface TeamData {
  id: number;
  city: string;
  name: string;
  abbreviation: string;
  primary_color: string;
  secondary_color: string;
  overall_rating: number;
  wins: number;
  losses: number;
  ties: number;
}

export default function Teams() {
  const league = useAuthStore((s) => s.league);
  const myTeam = useAuthStore((s) => s.team);

  const { data, isLoading } = useQuery({
    queryKey: ['teams-list', league?.id],
    queryFn: () => teamApi.list(league!.id),
    enabled: !!league?.id,
  });

  const { data: readyStatus } = useQuery({
    queryKey: ['ready-status', league?.id],
    queryFn: () => advanceApi.readyStatus(league!.id),
    enabled: !!league?.id && league?.phase !== 'preseason',
    refetchInterval: 15000, // poll every 15 seconds
  });

  if (isLoading) {
    return <p className="text-[var(--text-secondary)] py-8">Loading teams...</p>;
  }

  const conferences = (data as any)?.conferences as Record<string, Record<string, TeamData[]>> | undefined;

  if (!conferences) {
    return <p className="text-[var(--text-secondary)] py-8">No teams found.</p>;
  }

  // Build a lookup of coach ready status by team abbreviation
  const coachReadyMap: Record<string, boolean> = {};
  if (readyStatus) {
    for (const c of readyStatus.coaches) {
      coachReadyMap[c.team] = c.is_ready;
    }
  }

  return (
    <div className="space-y-8">
      <h1 className="text-2xl font-bold text-[var(--text-primary)]">Teams</h1>

      {/* Ready Status Summary */}
      {readyStatus && readyStatus.coaches_total > 0 && (
        <ReadyStatusPanel status={readyStatus} />
      )}

      {Object.entries(conferences).map(([confName, divisions]) => {
        const fullConfName = confName === 'AC' ? 'Atlantic Conference' : confName === 'PC' ? 'Pacific Conference' : confName;
        return (
        <div key={confName}>
          <h2 className="text-lg font-bold text-[var(--text-primary)] mb-4">{fullConfName}</h2>

          <div className="grid gap-6 md:grid-cols-2">
            {Object.entries(divisions).map(([divName, teams]) => (
              <div key={divName} className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
                <div className="px-5 py-3 border-b border-[var(--border)] bg-[var(--bg-elevated)]">
                  <h3 className="text-sm font-bold uppercase tracking-wider text-[var(--text-secondary)]">
                    {confName} {divName}
                  </h3>
                </div>

                <div>
                  {teams.map((team) => {
                    const isMyTeam = myTeam?.id === team.id;
                    const isReady = coachReadyMap[team.abbreviation];
                    const isHumanCoached = team.abbreviation in coachReadyMap;

                    return (
                      <Link
                        key={team.id}
                        to={`/team/${team.id}`}
                        className={`flex items-center gap-4 px-5 py-3.5 border-b border-[var(--border)] last:border-0 transition-colors hover:bg-[var(--bg-elevated)] ${
                          isMyTeam ? 'bg-[var(--accent-blue)]/5' : ''
                        }`}
                      >
                        <TeamBadge
                          abbreviation={team.abbreviation}
                          primaryColor={team.primary_color}
                          secondaryColor={team.secondary_color}
                          size="sm"
                        />
                        <div className="flex-1 min-w-0">
                          <p className="font-semibold text-[var(--text-primary)]">
                            {team.city} {team.name}
                            {isMyTeam && <span className="ml-2 text-xs text-[var(--accent-blue)]">Your Team</span>}
                          </p>
                        </div>
                        <div className="text-right shrink-0">
                          <p className="font-semibold text-[var(--text-primary)]">
                            {team.wins}-{team.losses}{team.ties ? `-${team.ties}` : ''}
                          </p>
                        </div>
                        <div className="shrink-0 w-10 text-center">
                          <span className="text-sm font-bold text-[var(--text-primary)]">{team.overall_rating}</span>
                          <span className="block text-[10px] text-[var(--text-muted)]">OVR</span>
                        </div>
                        {/* Ready indicator for human-coached teams */}
                        {isHumanCoached && (
                          <div className="shrink-0 w-8 flex justify-center">
                            {isReady ? (
                              <div className="flex h-6 w-6 items-center justify-center rounded-full bg-green-500/15" title="Ready">
                                <Check className="h-3.5 w-3.5 text-green-500" />
                              </div>
                            ) : (
                              <div className="flex h-6 w-6 items-center justify-center rounded-full bg-[var(--bg-elevated)]" title="Not ready">
                                <Clock className="h-3.5 w-3.5 text-[var(--text-muted)]" />
                              </div>
                            )}
                          </div>
                        )}
                      </Link>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        </div>
      );})}
    </div>
  );
}

function ReadyStatusPanel({ status }: { status: ReadyStatus }) {
  const allCoachesReady = status.coaches_ready === status.coaches_total;
  const allFantasyReady = status.fantasy_total === 0 || status.fantasy_ready === status.fantasy_total;

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-5">
      <h2 className="font-bold text-[var(--text-primary)] mb-4">
        Week {status.week} — Ready Status
      </h2>

      <div className="grid gap-6 sm:grid-cols-2">
        {/* Coaches */}
        <div>
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-sm font-semibold text-[var(--text-secondary)]">Head Coaches</h3>
            <span className={`text-sm font-bold ${allCoachesReady ? 'text-green-500' : 'text-[var(--text-muted)]'}`}>
              {status.coaches_ready}/{status.coaches_total}
            </span>
          </div>
          <div className="space-y-2">
            {status.coaches.map((c) => (
              <div key={c.coach_id} className="flex items-center justify-between py-1.5 border-b border-[var(--border)] last:border-0">
                <div>
                  <span className="text-sm font-medium text-[var(--text-primary)]">{c.name}</span>
                  <span className="ml-2 text-xs text-[var(--text-muted)]">{c.team_name}</span>
                </div>
                {c.is_ready ? (
                  <span className="flex items-center gap-1 text-xs font-semibold text-green-500">
                    <Check className="h-3 w-3" /> Ready
                  </span>
                ) : (
                  <span className="flex items-center gap-1 text-xs text-[var(--text-muted)]">
                    <Clock className="h-3 w-3" /> Waiting
                  </span>
                )}
              </div>
            ))}
            {status.coaches.length === 0 && (
              <p className="text-sm text-[var(--text-muted)]">No human coaches</p>
            )}
          </div>
        </div>

        {/* Fantasy Managers */}
        {status.fantasy_total > 0 && (
          <div>
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-[var(--text-secondary)]">Fantasy Managers</h3>
              <span className={`text-sm font-bold ${allFantasyReady ? 'text-green-500' : 'text-[var(--text-muted)]'}`}>
                {status.fantasy_ready}/{status.fantasy_total}
              </span>
            </div>
            <div className="space-y-2">
              {status.fantasy.map((f) => (
                <div key={f.manager_id} className="flex items-center justify-between py-1.5 border-b border-[var(--border)] last:border-0">
                  <div>
                    <span className="text-sm font-medium text-[var(--text-primary)]">{f.name}</span>
                    <span className="ml-2 text-xs text-[var(--text-muted)]">{f.team_name}</span>
                  </div>
                  {f.is_ready ? (
                    <span className="flex items-center gap-1 text-xs font-semibold text-green-500">
                      <Check className="h-3 w-3" /> Ready
                    </span>
                  ) : (
                    <span className="flex items-center gap-1 text-xs text-[var(--text-muted)]">
                      <Clock className="h-3 w-3" /> Waiting
                    </span>
                  )}
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Auto-advance info */}
      {status.advance_mode === 'auto' && status.next_advance_at && (
        <div className="mt-4 pt-4 border-t border-[var(--border)] text-sm text-[var(--text-secondary)]">
          Auto-advance scheduled: <span className="font-semibold text-[var(--text-primary)]">
            {new Date(status.next_advance_at).toLocaleString()}
          </span>
        </div>
      )}

      {status.all_ready && (
        <div className="mt-4 pt-4 border-t border-[var(--border)] text-sm font-semibold text-green-500">
          Everyone is ready — the week will advance automatically.
        </div>
      )}
    </div>
  );
}
