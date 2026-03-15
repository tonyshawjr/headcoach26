import { useState, useMemo } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useScenarios } from '@/hooks/useApi';
import { PageLayout, PageHeader } from '@/components/ui/sports-ui';
import { Calculator, Check, RotateCcw } from 'lucide-react';
import type { ScenarioTeam, RemainingGame } from '@/api/client';

type GameResult = 'home' | 'away' | null;

function getWinPct(w: number, l: number): string {
  const total = w + l;
  if (total === 0) return '.000';
  return (w / total).toFixed(3).replace(/^0/, '');
}

export default function Scenarios({ embedded }: { embedded?: boolean }) {
  const league = useAuthStore((s) => s.league);
  const team = useAuthStore((s) => s.team);
  const { data, isLoading } = useScenarios(league?.id);
  const [results, setResults] = useState<Record<number, GameResult>>({});
  const [showGames, setShowGames] = useState(false);

  if (isLoading || !data) {
    if (embedded) {
      return (
        <div className="flex h-64 items-center justify-center">
          <Calculator className="h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
        </div>
      );
    }
    return (
      <PageLayout>
        <PageHeader title="Playoff Picture" icon={Calculator} accentColor="var(--accent-blue)" />
        <div className="flex h-64 items-center justify-center">
          <Calculator className="h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
        </div>
      </PageLayout>
    );
  }

  const { teams, remaining_games: remainingGames, scenarios } = data;

  if (remainingGames.length === 0) {
    if (embedded) {
      return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
          <p className="text-[var(--text-secondary)]">The regular season is over.</p>
        </div>
      );
    }
    return (
      <PageLayout>
        <PageHeader title="Playoff Picture" icon={Calculator} accentColor="var(--accent-blue)" subtitle="Regular season complete" />
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
          <p className="text-[var(--text-secondary)]">The regular season is over. Check the playoff bracket for results.</p>
        </div>
      </PageLayout>
    );
  }

  const myTeamId = team?.id;
  const myTeam = teams.find((t) => t.id === myTeamId);
  const myConf = myTeam?.conference;
  const myDiv = myTeam?.division;
  const myScenario = myTeamId ? scenarios[myTeamId] : null;

  function toggleResult(gameId: number, side: 'home' | 'away') {
    setResults((prev) => {
      const current = prev[gameId];
      if (current === side) return { ...prev, [gameId]: null };
      return { ...prev, [gameId]: side };
    });
  }

  function resetAll() { setResults({}); }

  // Compute projected records with user-selected results
  const projected = useMemo(() => {
    const tw: Record<number, { wins: number; losses: number }> = {};
    teams.forEach((t) => { tw[t.id] = { wins: t.wins, losses: t.losses }; });
    remainingGames.forEach((g) => {
      const r = results[g.id];
      if (r === 'home') { tw[g.home_team_id].wins++; tw[g.away_team_id].losses++; }
      else if (r === 'away') { tw[g.away_team_id].wins++; tw[g.home_team_id].losses++; }
    });
    return tw;
  }, [teams, remainingGames, results]);

  // Build conference playoff picture
  const confTeams = useMemo(() => {
    if (!myConf) return [];
    return teams
      .filter((t) => t.conference === myConf)
      .map((t) => {
        const proj = projected[t.id] ?? { wins: t.wins, losses: t.losses };
        const changed = proj.wins !== t.wins || proj.losses !== t.losses;
        const scenario = scenarios[t.id];
        return { ...t, projWins: proj.wins, projLosses: proj.losses, changed, scenario };
      })
      .sort((a, b) => {
        const d = b.projWins - a.projWins;
        return d !== 0 ? d : (b.points_for - a.points_for);
      });
  }, [teams, myConf, projected, scenarios]);

  // Determine division winners + wild cards
  const playoffPicture = useMemo(() => {
    if (!myConf) return { inPlayoffs: [] as typeof confTeams, bubble: [] as typeof confTeams, out: [] as typeof confTeams };

    // Find division winners
    const divWinners = new Map<string, typeof confTeams[0]>();
    confTeams.forEach((t) => {
      const key = t.division;
      const current = divWinners.get(key);
      if (!current || t.projWins > current.projWins || (t.projWins === current.projWins && t.points_for > current.points_for)) {
        divWinners.set(key, t);
      }
    });

    const divWinnerIds = new Set([...divWinners.values()].map((t) => t.id));
    const playoffSpots = myScenario?.playoff_spots ?? 7;
    const divCount = divWinners.size;
    const wcSpots = playoffSpots - divCount;

    // Sort div winners by record for seeding
    const sortedDivWinners = [...divWinners.values()].sort((a, b) => {
      const d = b.projWins - a.projWins;
      return d !== 0 ? d : (b.points_for - a.points_for);
    });

    // Wild cards: best non-div-winners
    const nonDivWinners = confTeams.filter((t) => !divWinnerIds.has(t.id));
    const wildCards = nonDivWinners.slice(0, wcSpots);
    const wcIds = new Set(wildCards.map((t) => t.id));

    const inPlayoffs = [...sortedDivWinners, ...wildCards];
    // Bubble = teams not in playoffs but not mathematically eliminated
    const remaining = nonDivWinners.slice(wcSpots);
    const bubble = remaining.filter((t) => !t.scenario?.eliminated);
    const out = remaining.filter((t) => t.scenario?.eliminated);

    return { inPlayoffs, bubble, out };
  }, [confTeams, myConf, myScenario]);

  // My division standings
  const myDivTeams = useMemo(() => {
    return confTeams.filter((t) => t.division === myDiv);
  }, [confTeams, myDiv]);

  // My remaining schedule
  const myGames = remainingGames.filter((g) => g.home_team_id === myTeamId || g.away_team_id === myTeamId);

  // Games that affect my division race
  const divRivalGames = remainingGames.filter((g) => {
    if (g.home_team_id === myTeamId || g.away_team_id === myTeamId) return false;
    const divIds = myDivTeams.map((t) => t.id);
    return divIds.includes(g.home_team_id) || divIds.includes(g.away_team_id);
  });

  const setCount = Object.values(results).filter(Boolean).length;

  const content = (
    <>

      {/* ── Your Situation ── */}
      {myTeam && myScenario && (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden mb-6">
          <div className="h-[3px]" style={{ backgroundColor: myTeam.primary_color }} />
          <div className="p-5">
            <div className="flex items-center gap-3 mb-3">
              <span className="h-4 w-4 rounded-full" style={{ backgroundColor: myTeam.primary_color }} />
              <h2 className="font-display text-lg">{myTeam.city} {myTeam.name}</h2>
              <span className="font-stat text-sm text-[var(--text-secondary)]">
                {projected[myTeamId!]?.wins ?? myTeam.wins}-{projected[myTeamId!]?.losses ?? myTeam.losses}
              </span>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <MiniStat label="Division Rank" value={`#${myDivTeams.findIndex((t) => t.id === myTeamId) + 1} in ${myDiv}`} />
              <MiniStat label="Conference Seed" value={`#${confTeams.findIndex((t) => t.id === myTeamId) + 1}`} />
              <MiniStat label="Games Left" value={String(myScenario.games_left)} />
              {myScenario.div_magic_number !== null && myScenario.is_div_leader && myScenario.div_magic_number > 0 && (
                <MiniStat label="Clinch Division With" value={`${myScenario.div_magic_number} more win${myScenario.div_magic_number !== 1 ? 's' : ''}`} accent />
              )}
              {myScenario.clinched_division && <MiniStat label="Status" value="Clinched Division" good />}
              {myScenario.clinched_playoff && !myScenario.clinched_division && <MiniStat label="Status" value="Clinched Playoff" good />}
              {myScenario.eliminated && <MiniStat label="Status" value="Eliminated" bad />}
              {!myScenario.clinched_playoff && !myScenario.eliminated && (
                <MiniStat label="Status" value={myScenario.can_win_division ? 'In Division Race' : 'Wild Card Hunt'} />
              )}
            </div>
          </div>
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
        {/* ── Left: Playoff Picture + Game Toggles ── */}
        <div className="space-y-6">

          {/* Playoff Bracket Picture */}
          <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
            <div className="px-4 py-2.5 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
              <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-blue)]">
                {myConf === 'AC' ? 'American' : 'Professional'} Conference Playoff Seeds
              </span>
            </div>

            {/* In the Playoffs */}
            <div className="divide-y divide-[var(--border)]">
              {playoffPicture.inPlayoffs.map((t, i) => {
                const isMe = t.id === myTeamId;
                const isDivWinner = i < (new Set(playoffPicture.inPlayoffs.map((x) => x.division))).size &&
                  playoffPicture.inPlayoffs.slice(0, i + 1).filter((x) => x.division === t.division).length === 1;
                return (
                  <div key={t.id} className={`flex items-center gap-3 px-4 py-3 ${isMe ? 'bg-[var(--accent-blue)]/5' : ''}`}>
                    <span className={`w-6 text-center font-stat text-sm ${i === 0 ? 'text-[var(--accent-gold)]' : 'text-[var(--text-muted)]'}`}>
                      {i + 1}
                    </span>
                    <span className="h-3 w-3 rounded-full shrink-0" style={{ backgroundColor: t.primary_color }} />
                    <span className={`flex-1 text-sm ${isMe ? 'font-bold text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}`}>
                      {t.city} {t.name}
                    </span>
                    <span className="text-[10px] text-[var(--text-muted)] w-12">{t.division}</span>
                    <span className={`font-stat text-sm w-10 text-right ${t.changed ? 'text-[var(--accent-blue)]' : ''}`}>
                      {t.projWins}-{t.projLosses}
                    </span>
                    {i === 0 && (
                      <span className="text-[9px] font-bold text-[var(--accent-gold)] bg-[var(--accent-gold)]/10 px-1.5 rounded">BYE</span>
                    )}
                    {t.scenario?.clinched_playoff && (
                      <span className="text-[9px] font-bold text-green-400 bg-green-500/10 px-1.5 rounded">IN</span>
                    )}
                  </div>
                );
              })}
            </div>

            {/* Bubble */}
            {playoffPicture.bubble.length > 0 && (
              <>
                <div className="px-4 py-1.5 bg-[var(--bg-elevated)]/60 border-t border-b border-[var(--border)]">
                  <span className="text-[9px] font-bold uppercase tracking-widest text-yellow-500">In the Hunt</span>
                </div>
                <div className="divide-y divide-[var(--border)]">
                  {playoffPicture.bubble.map((t) => {
                    const isMe = t.id === myTeamId;
                    return (
                      <div key={t.id} className={`flex items-center gap-3 px-4 py-3 ${isMe ? 'bg-[var(--accent-blue)]/5' : ''}`}>
                        <span className="w-6" />
                        <span className="h-3 w-3 rounded-full shrink-0" style={{ backgroundColor: t.primary_color }} />
                        <span className={`flex-1 text-sm ${isMe ? 'font-bold text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}`}>
                          {t.city} {t.name}
                        </span>
                        <span className="text-[10px] text-[var(--text-muted)] w-12">{t.division}</span>
                        <span className={`font-stat text-sm w-10 text-right ${t.changed ? 'text-[var(--accent-blue)]' : ''}`}>
                          {t.projWins}-{t.projLosses}
                        </span>
                      </div>
                    );
                  })}
                </div>
              </>
            )}

            {/* Eliminated */}
            {playoffPicture.out.length > 0 && (
              <>
                <div className="px-4 py-1.5 bg-[var(--bg-elevated)]/60 border-t border-b border-[var(--border)]">
                  <span className="text-[9px] font-bold uppercase tracking-widest text-red-400">Out of Contention</span>
                </div>
                <div className="divide-y divide-[var(--border)]">
                  {playoffPicture.out.map((t) => (
                    <div key={t.id} className="flex items-center gap-3 px-4 py-2.5 opacity-50">
                      <span className="w-6" />
                      <span className="h-3 w-3 rounded-full shrink-0" style={{ backgroundColor: t.primary_color }} />
                      <span className="flex-1 text-sm text-[var(--text-muted)]">{t.city} {t.name}</span>
                      <span className="text-[10px] text-[var(--text-muted)] w-12">{t.division}</span>
                      <span className="font-stat text-sm w-10 text-right text-[var(--text-muted)]">{t.projWins}-{t.projLosses}</span>
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>

          {/* Game Toggles */}
          <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
            <button
              onClick={() => setShowGames(!showGames)}
              className="w-full flex items-center justify-between px-4 py-3 hover:bg-[var(--bg-elevated)] transition-colors"
            >
              <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-blue)]">
                What If? — Toggle Game Results
              </span>
              <span className="text-xs text-[var(--text-muted)]">
                {showGames ? 'Hide' : `${remainingGames.length} games remaining`}
              </span>
            </button>
            {showGames && (
              <GameToggles
                games={remainingGames}
                results={results}
                onToggle={toggleResult}
                myTeamId={myTeamId}
                teams={teams}
              />
            )}
          </div>
        </div>

        {/* ── Right: Division Race + Your Schedule ── */}
        <div className="space-y-5">

          {/* Division Race */}
          <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden sticky top-4">
            <div className="px-4 py-2.5 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
              <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-blue)]">
                {myDiv} Division Race
              </span>
            </div>
            <div className="p-3 space-y-1.5">
              {myDivTeams.map((t, i) => {
                const isMe = t.id === myTeamId;
                const gamesBack = i === 0 ? null : (myDivTeams[0].projWins - t.projWins);
                return (
                  <div key={t.id} className={`flex items-center gap-2 rounded-md px-2.5 py-2 ${isMe ? 'bg-[var(--accent-blue)]/8 ring-1 ring-[var(--accent-blue)]/20' : ''}`}>
                    <span className={`w-5 text-center font-stat text-xs ${i === 0 ? 'text-[var(--accent-gold)]' : 'text-[var(--text-muted)]'}`}>{i + 1}</span>
                    <span className="h-3 w-3 rounded-full shrink-0" style={{ backgroundColor: t.primary_color }} />
                    <span className={`flex-1 text-sm ${isMe ? 'font-bold' : ''}`}>{t.abbreviation}</span>
                    <span className={`font-stat text-sm ${t.changed ? 'text-[var(--accent-blue)]' : ''}`}>{t.projWins}-{t.projLosses}</span>
                    <span className="text-[10px] text-[var(--text-muted)] w-7 text-right">
                      {gamesBack !== null && gamesBack > 0 ? `-${gamesBack}` : gamesBack === 0 && i > 0 ? '--' : ''}
                    </span>
                  </div>
                );
              })}
            </div>

            {/* Division rival remaining games */}
            {divRivalGames.length > 0 && (
              <>
                <div className="px-4 py-2 border-t border-[var(--border)] bg-[var(--bg-elevated)]/40">
                  <span className="text-[9px] font-bold uppercase tracking-widest text-[var(--text-muted)]">
                    Division Rival Games
                  </span>
                </div>
                <div className="px-3 py-2 space-y-1">
                  {divRivalGames.slice(0, 8).map((g) => (
                    <div key={g.id} className="flex items-center gap-2 text-xs text-[var(--text-muted)]">
                      <span className="text-[10px] w-8">Wk {g.week}</span>
                      <button
                        onClick={() => { setShowGames(true); toggleResult(g.id, 'away'); }}
                        className={`flex-1 text-left rounded px-1.5 py-0.5 transition-colors ${results[g.id] === 'away' ? 'bg-green-500/15 text-green-400 font-semibold' : 'hover:bg-[var(--bg-elevated)]'}`}
                      >
                        {g.away_abbr}
                      </button>
                      <span className="text-[10px]">@</span>
                      <button
                        onClick={() => { setShowGames(true); toggleResult(g.id, 'home'); }}
                        className={`flex-1 text-left rounded px-1.5 py-0.5 transition-colors ${results[g.id] === 'home' ? 'bg-green-500/15 text-green-400 font-semibold' : 'hover:bg-[var(--bg-elevated)]'}`}
                      >
                        {g.home_abbr}
                      </button>
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>

          {/* Your Remaining Schedule */}
          {myGames.length > 0 && (
            <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
              <div className="px-4 py-2.5 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
                <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-blue)]">
                  Your Remaining Schedule
                </span>
                <p className="text-[9px] text-[var(--text-muted)] mt-0.5">Tap W or L to predict each game</p>
              </div>
              <div className="divide-y divide-[var(--border)]">
                {myGames.map((g) => {
                  const isHome = g.home_team_id === myTeamId;
                  const oppAbbr = isHome ? g.away_abbr : g.home_abbr;
                  const oppId = isHome ? g.away_team_id : g.home_team_id;
                  const oppTeam = teams.find((t) => t.id === oppId);
                  const oppProj = projected[oppId];
                  const mySide: 'home' | 'away' = isHome ? 'home' : 'away';
                  const oppSide: 'home' | 'away' = isHome ? 'away' : 'home';
                  const myResult = results[g.id];
                  const isWin = myResult === mySide;
                  const isLoss = myResult === oppSide;

                  return (
                    <div key={g.id} className="flex items-center gap-3 px-4 py-2.5">
                      <span className="text-[10px] font-semibold text-[var(--text-muted)] w-8">Wk {g.week}</span>
                      <span className="text-xs text-[var(--text-muted)] w-4">{isHome ? 'vs' : '@'}</span>
                      <span className="h-2.5 w-2.5 rounded-full shrink-0" style={{ backgroundColor: oppTeam?.primary_color }} />
                      <span className="flex-1 text-sm font-semibold text-[var(--text-primary)]">{oppAbbr}</span>
                      <span className="text-[10px] text-[var(--text-muted)] w-10 text-right">
                        {oppProj ? `${oppProj.wins}-${oppProj.losses}` : ''}
                      </span>
                      {/* Win/Loss toggle buttons */}
                      <div className="flex gap-1 ml-1">
                        <button
                          onClick={() => toggleResult(g.id, mySide)}
                          className={`w-8 h-6 rounded text-[10px] font-bold transition-all ${
                            isWin
                              ? 'bg-green-500 text-white'
                              : 'bg-[var(--bg-elevated)] text-[var(--text-muted)] hover:text-green-400 hover:bg-green-500/10'
                          }`}
                        >
                          W
                        </button>
                        <button
                          onClick={() => toggleResult(g.id, oppSide)}
                          className={`w-8 h-6 rounded text-[10px] font-bold transition-all ${
                            isLoss
                              ? 'bg-red-500 text-white'
                              : 'bg-[var(--bg-elevated)] text-[var(--text-muted)] hover:text-red-400 hover:bg-red-500/10'
                          }`}
                        >
                          L
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      </div>

      {setCount > 0 && (
        <div className="mt-4 flex justify-center">
          <button onClick={resetAll} className="flex items-center gap-1.5 px-4 py-2 rounded-md text-xs font-bold uppercase tracking-wider text-[var(--text-muted)] hover:text-[var(--text-primary)] border border-[var(--border)] transition-colors">
            <RotateCcw className="h-3.5 w-3.5" /> Reset All Predictions ({setCount})
          </button>
        </div>
      )}
    </>
  );

  if (embedded) return content;

  return (
    <PageLayout>
      <PageHeader
        title="Playoff Picture"
        icon={Calculator}
        accentColor="var(--accent-blue)"
        subtitle={myConf ? `${myConf === 'AC' ? 'American' : 'Professional'} Conference` : undefined}
      />
      {content}
    </PageLayout>
  );
}

/* ── Mini Stat Box ── */

function MiniStat({ label, value, accent, good, bad }: { label: string; value: string; accent?: boolean; good?: boolean; bad?: boolean }) {
  const color = good ? 'text-green-400' : bad ? 'text-red-400' : accent ? 'text-[var(--accent-gold)]' : 'text-[var(--text-primary)]';
  return (
    <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-elevated)]/40 px-3 py-2.5">
      <p className="text-[9px] font-bold uppercase tracking-widest text-[var(--text-muted)] mb-0.5">{label}</p>
      <p className={`font-stat text-sm ${color}`}>{value}</p>
    </div>
  );
}

/* ── Game Toggles (collapsible) ── */

function GameToggles({
  games,
  results,
  onToggle,
  myTeamId,
  teams,
}: {
  games: RemainingGame[];
  results: Record<number, GameResult>;
  onToggle: (id: number, side: 'home' | 'away') => void;
  myTeamId?: number;
  teams: ScenarioTeam[];
}) {
  // Group by week
  const byWeek: Record<number, RemainingGame[]> = {};
  games.forEach((g) => { if (!byWeek[g.week]) byWeek[g.week] = []; byWeek[g.week].push(g); });
  const weeks = Object.keys(byWeek).map(Number).sort((a, b) => a - b);

  return (
    <div className="border-t border-[var(--border)] max-h-[500px] overflow-y-auto">
      {weeks.map((week) => (
        <div key={week}>
          <div className="px-4 py-1.5 bg-[var(--bg-elevated)]/40 border-b border-[var(--border)] sticky top-0 z-10">
            <span className="text-[9px] font-bold uppercase tracking-widest text-[var(--text-muted)]">Week {week}</span>
          </div>
          <div className="divide-y divide-[var(--border)]">
            {byWeek[week].map((game) => {
              const isMyGame = game.home_team_id === myTeamId || game.away_team_id === myTeamId;
              const awayTeam = teams.find((t) => t.id === game.away_team_id);
              const homeTeam = teams.find((t) => t.id === game.home_team_id);

              return (
                <div key={game.id} className={`flex items-center gap-1.5 px-4 py-2 ${isMyGame ? 'bg-[var(--accent-blue)]/5' : ''}`}>
                  <button
                    onClick={() => onToggle(game.id, 'away')}
                    className={`flex items-center gap-1.5 flex-1 rounded px-2 py-1 text-xs font-semibold transition-all ${
                      results[game.id] === 'away'
                        ? 'bg-green-500/15 text-green-400 ring-1 ring-green-500/30'
                        : 'text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)]'
                    }`}
                  >
                    <span className="h-2 w-2 rounded-full shrink-0" style={{ backgroundColor: awayTeam?.primary_color }} />
                    {game.away_abbr}
                    {results[game.id] === 'away' && <Check className="h-3 w-3 ml-auto" />}
                  </button>
                  <span className="text-[9px] text-[var(--text-muted)]">@</span>
                  <button
                    onClick={() => onToggle(game.id, 'home')}
                    className={`flex items-center gap-1.5 flex-1 rounded px-2 py-1 text-xs font-semibold transition-all ${
                      results[game.id] === 'home'
                        ? 'bg-green-500/15 text-green-400 ring-1 ring-green-500/30'
                        : 'text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)]'
                    }`}
                  >
                    <span className="h-2 w-2 rounded-full shrink-0" style={{ backgroundColor: homeTeam?.primary_color }} />
                    {game.home_abbr}
                    {results[game.id] === 'home' && <Check className="h-3 w-3 ml-auto" />}
                  </button>
                </div>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}
