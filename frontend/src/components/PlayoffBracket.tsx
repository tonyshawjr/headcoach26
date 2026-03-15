import { useMemo } from 'react';
import { TeamLogo } from '@/components/TeamLogo';
import type { Game } from '@/api/client';

interface PlayoffBracketProps {
  games: Game[];
  userTeamId?: number;
}

type RoundKey = 'wild_card' | 'divisional' | 'conference_championship' | 'big_game';

interface Matchup {
  game?: Game;
  homeTeam?: Game['home_team'];
  awayTeam?: Game['away_team'];
  homeSeed?: number;
  awaySeed?: number;
  winner?: 'home' | 'away';
}

const ROUND_LABELS: Record<RoundKey, string> = {
  wild_card: 'Wild Card',
  divisional: 'Divisional',
  conference_championship: 'Conference Championship',
  big_game: 'The Big Game',
};

const ROUND_WEEK: Record<RoundKey, number> = {
  wild_card: 19,
  divisional: 20,
  conference_championship: 21,
  big_game: 22,
};

/**
 * Derive seed numbers from playoff matchup structure.
 * Wild Card: higher seed is home. #2v#7, #3v#6, #4v#5
 * The #1 seed gets a bye in Wild Card round.
 */
function inferSeeds(
  wcGames: Game[],
  conference: string
): Map<number, number> {
  const seedMap = new Map<number, number>();

  // Standard 7-team seedings: home seeds are 2,3,4 and away seeds are 7,6,5
  const standardPairs = [
    { homeSeed: 2, awaySeed: 7 },
    { homeSeed: 3, awaySeed: 6 },
    { homeSeed: 4, awaySeed: 5 },
  ];

  // Sort WC games by home_team_id for consistent ordering, then assign seeds
  const confWc = wcGames
    .filter((g) => {
      const homeConf = g.home_team?.conference;
      return homeConf === conference;
    })
    .sort((a, b) => a.id - b.id);

  confWc.forEach((g, i) => {
    if (i < standardPairs.length) {
      seedMap.set(g.home_team_id, standardPairs[i].homeSeed);
      seedMap.set(g.away_team_id, standardPairs[i].awaySeed);
    }
  });

  return seedMap;
}

function getWinner(game: Game): 'home' | 'away' | undefined {
  if (!game.is_simulated || game.home_score === null || game.away_score === null) return undefined;
  return game.home_score > game.away_score ? 'home' : 'away';
}

function MatchupBox({
  matchup,
  userTeamId,
  roundLabel,
}: {
  matchup: Matchup;
  userTeamId?: number;
  roundLabel?: string;
}) {
  const { game, homeTeam, awayTeam, homeSeed, awaySeed, winner } = matchup;
  const isUserHome = userTeamId != null && game?.home_team_id === userTeamId;
  const isUserAway = userTeamId != null && game?.away_team_id === userTeamId;
  const isUserGame = isUserHome || isUserAway;

  return (
    <div
      className={`relative rounded-md border bg-[var(--bg-surface)] overflow-hidden transition-shadow ${
        isUserGame
          ? 'border-[var(--accent-blue)]/50 shadow-[0_0_12px_rgba(33,136,255,0.15)]'
          : 'border-[var(--border)]'
      }`}
      style={{ width: 180 }}
    >
      {/* Round label (only for The Big Game) */}
      {roundLabel && (
        <div className="bg-[var(--bg-elevated)] px-2 py-1 text-center">
          <span className="text-[9px] font-bold uppercase tracking-[0.15em] text-[var(--accent-gold)]">
            {roundLabel}
          </span>
        </div>
      )}

      {/* Away team row (top) */}
      <TeamRow
        team={awayTeam}
        seed={awaySeed}
        score={game?.is_simulated ? game.away_score : null}
        isWinner={winner === 'away'}
        isUser={isUserAway}
        hasTbd={!awayTeam}
        simulated={game?.is_simulated ?? false}
      />

      {/* Divider */}
      <div className="border-t border-[var(--border)]" />

      {/* Home team row (bottom) */}
      <TeamRow
        team={homeTeam}
        seed={homeSeed}
        score={game?.is_simulated ? game.home_score : null}
        isWinner={winner === 'home'}
        isUser={isUserHome}
        hasTbd={!homeTeam}
        simulated={game?.is_simulated ?? false}
      />
    </div>
  );
}

function TeamRow({
  team,
  seed,
  score,
  isWinner,
  isUser,
  hasTbd,
  simulated,
}: {
  team?: Game['home_team'];
  seed?: number;
  score: number | null;
  isWinner: boolean;
  isUser: boolean;
  hasTbd: boolean;
  simulated: boolean;
}) {
  return (
    <div
      className={`flex items-center gap-1.5 px-2 py-1.5 ${isWinner ? 'bg-white/[0.04]' : ''}`}
      style={
        isWinner && team?.primary_color
          ? { borderLeft: `3px solid ${team.primary_color}` }
          : { borderLeft: '3px solid transparent' }
      }
    >
      {seed != null && (
        <span className="w-3 shrink-0 text-center text-[9px] font-bold text-[var(--text-muted)]">
          {seed}
        </span>
      )}
      {hasTbd ? (
        <span className="flex-1 text-[11px] italic text-[var(--text-muted)]">TBD</span>
      ) : (
        <>
          <TeamLogo
            abbreviation={team?.abbreviation}
            primaryColor={team?.primary_color}
            secondaryColor={team?.secondary_color}
            size="xs"
          />
          <span
            className={`flex-1 text-[12px] ${
              isWinner
                ? 'font-bold text-[var(--text-primary)]'
                : 'text-[var(--text-secondary)]'
            } ${isUser ? 'text-[var(--accent-blue)]' : ''}`}
          >
            {team?.abbreviation ?? '???'}
          </span>
        </>
      )}
      <span
        className={`font-stat text-[12px] ${
          isWinner ? 'font-bold text-[var(--text-primary)]' : 'text-[var(--text-muted)]'
        }`}
      >
        {simulated && score !== null ? score : hasTbd ? '' : '--'}
      </span>
    </div>
  );
}

/**
 * Connector line between bracket rounds.
 * `direction` controls whether the line converges up or down.
 */
function Connector({ direction }: { direction: 'up' | 'down' | 'straight' }) {
  return (
    <div className="flex items-center" style={{ width: 24 }}>
      <svg width="24" height="48" viewBox="0 0 24 48" className="text-[var(--border)]">
        {direction === 'straight' && (
          <line x1="0" y1="24" x2="24" y2="24" stroke="currentColor" strokeWidth="1.5" />
        )}
        {direction === 'up' && (
          <path d="M0 36 L12 36 L12 12 L24 12" fill="none" stroke="currentColor" strokeWidth="1.5" />
        )}
        {direction === 'down' && (
          <path d="M0 12 L12 12 L12 36 L24 36" fill="none" stroke="currentColor" strokeWidth="1.5" />
        )}
      </svg>
    </div>
  );
}

export function PlayoffBracket({ games, userTeamId }: PlayoffBracketProps) {
  const bracket = useMemo(() => {
    // Separate games by round
    const byRound: Record<RoundKey, Game[]> = {
      wild_card: [],
      divisional: [],
      conference_championship: [],
      big_game: [],
    };

    for (const g of games) {
      const type = (g.game_type ?? '') as RoundKey;
      if (type in byRound) {
        byRound[type].push(g);
      } else if (g.week === ROUND_WEEK.wild_card) {
        byRound.wild_card.push(g);
      } else if (g.week === ROUND_WEEK.divisional) {
        byRound.divisional.push(g);
      } else if (g.week === ROUND_WEEK.conference_championship) {
        byRound.conference_championship.push(g);
      } else if (g.week === ROUND_WEEK.big_game) {
        byRound.big_game.push(g);
      }
    }

    // Detect conferences from WC games
    const conferences = new Set<string>();
    for (const g of byRound.wild_card) {
      if (g.home_team?.conference) conferences.add(g.home_team.conference);
    }
    // Fallback: also check divisional/championship
    for (const g of [...byRound.divisional, ...byRound.conference_championship]) {
      if (g.home_team?.conference) conferences.add(g.home_team.conference);
    }
    const confList = Array.from(conferences).sort();

    // Build seed maps per conference
    const seedMaps: Record<string, Map<number, number>> = {};
    for (const conf of confList) {
      seedMaps[conf] = inferSeeds(byRound.wild_card, conf);
    }

    // For divisional round, #1 seed plays lowest remaining seed
    // The #1 seed team appears in divisional games but not WC — detect them
    for (const conf of confList) {
      const divGames = byRound.divisional.filter(
        (g) => g.home_team?.conference === conf
      );
      for (const g of divGames) {
        if (!seedMaps[conf].has(g.home_team_id)) {
          seedMaps[conf].set(g.home_team_id, 1);
        }
        if (!seedMaps[conf].has(g.away_team_id)) {
          seedMaps[conf].set(g.away_team_id, 1);
        }
      }
    }

    // Build matchups per conference per round
    const confBrackets: Record<
      string,
      { wc: Matchup[]; div: Matchup[]; champ: Matchup }
    > = {};

    for (const conf of confList) {
      const sm = seedMaps[conf];

      // Wild Card matchups
      const wcGames = byRound.wild_card.filter(
        (g) => g.home_team?.conference === conf
      );
      const wcMatchups: Matchup[] = wcGames.map((g) => ({
        game: g,
        homeTeam: g.home_team,
        awayTeam: g.away_team,
        homeSeed: sm.get(g.home_team_id),
        awaySeed: sm.get(g.away_team_id),
        winner: getWinner(g),
      }));
      // Sort by home seed ascending (2, 3, 4)
      wcMatchups.sort((a, b) => (a.homeSeed ?? 99) - (b.homeSeed ?? 99));

      // Divisional matchups
      const divGames = byRound.divisional.filter(
        (g) => g.home_team?.conference === conf
      );
      const divMatchups: Matchup[] = divGames.map((g) => ({
        game: g,
        homeTeam: g.home_team,
        awayTeam: g.away_team,
        homeSeed: sm.get(g.home_team_id),
        awaySeed: sm.get(g.away_team_id),
        winner: getWinner(g),
      }));
      // Sort so #1 seed game is first
      divMatchups.sort((a, b) => (a.homeSeed ?? 99) - (b.homeSeed ?? 99));

      // Ensure we have 2 divisional slots even if games don't exist yet
      while (divMatchups.length < 2) {
        divMatchups.push({});
      }

      // Conference Championship
      const champGames = byRound.conference_championship.filter(
        (g) => g.home_team?.conference === conf
      );
      const champMatchup: Matchup = champGames.length > 0
        ? {
            game: champGames[0],
            homeTeam: champGames[0].home_team,
            awayTeam: champGames[0].away_team,
            homeSeed: sm.get(champGames[0].home_team_id),
            awaySeed: sm.get(champGames[0].away_team_id),
            winner: getWinner(champGames[0]),
          }
        : {};

      confBrackets[conf] = {
        wc: wcMatchups,
        div: divMatchups,
        champ: champMatchup,
      };
    }

    // The Big Game
    const sbGame = byRound.big_game[0];
    const sbMatchup: Matchup = sbGame
      ? {
          game: sbGame,
          homeTeam: sbGame.home_team,
          awayTeam: sbGame.away_team,
          winner: getWinner(sbGame),
        }
      : {};

    return { confList, confBrackets, sbMatchup };
  }, [games]);

  const { confList, confBrackets, sbMatchup } = bracket;

  if (confList.length === 0) {
    return (
      <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
        <p className="text-sm text-[var(--text-muted)]">No playoff games scheduled yet.</p>
      </div>
    );
  }

  // Render: Left conference | The Big Game center | Right conference
  const leftConf = confList[0];
  const rightConf = confList[1] ?? confList[0];
  const leftBracket = confBrackets[leftConf];
  const rightBracket = confBrackets[rightConf];

  return (
    <div className="space-y-4">
      {/* Round labels header */}
      <div className="flex items-center justify-center gap-1">
        {(['wild_card', 'divisional', 'conference_championship', 'big_game'] as RoundKey[]).map((r) => (
          <div key={r} className="flex-1 text-center">
            <span className="text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--text-muted)]">
              {ROUND_LABELS[r]}
            </span>
          </div>
        ))}
      </div>

      {/* Bracket layout */}
      <div className="overflow-x-auto">
        <div className="flex items-stretch justify-center gap-0 min-w-[860px]">

          {/* --- Left Conference --- */}
          <div className="flex flex-col items-end">
            {/* Conference label */}
            <div className="mb-3 w-full text-center">
              <span className="text-[11px] font-bold uppercase tracking-[0.15em] text-[var(--text-secondary)]">
                {leftConf}
              </span>
            </div>

            <div className="flex items-center gap-0">
              {/* Wild Card column */}
              <div className="flex flex-col gap-4 justify-center">
                {leftBracket.wc.map((m, i) => (
                  <MatchupBox key={`lwc-${i}`} matchup={m} userTeamId={userTeamId} />
                ))}
                {leftBracket.wc.length === 0 && (
                  <div className="flex items-center justify-center" style={{ width: 180, height: 80 }}>
                    <span className="text-[10px] text-[var(--text-muted)]">No WC games</span>
                  </div>
                )}
              </div>

              {/* Connector lines WC -> DIV */}
              <div className="flex flex-col gap-6 justify-center">
                <Connector direction="up" />
                <Connector direction="straight" />
                <Connector direction="down" />
              </div>

              {/* Divisional column */}
              <div className="flex flex-col gap-8 justify-center">
                {leftBracket.div.map((m, i) => (
                  <MatchupBox key={`ldiv-${i}`} matchup={m} userTeamId={userTeamId} />
                ))}
              </div>

              {/* Connector lines DIV -> CHAMP */}
              <div className="flex flex-col gap-4 justify-center">
                <Connector direction="up" />
                <Connector direction="down" />
              </div>

              {/* Conference Championship */}
              <div className="flex flex-col justify-center">
                <MatchupBox matchup={leftBracket.champ} userTeamId={userTeamId} />
              </div>

              {/* Connector to The Big Game */}
              <Connector direction="straight" />
            </div>
          </div>

          {/* --- The Big Game (center) --- */}
          <div className="flex flex-col items-center justify-center px-2">
            <MatchupBox
              matchup={sbMatchup}
              userTeamId={userTeamId}
              roundLabel="The Big Game"
            />
            {sbMatchup.winner && sbMatchup.game && (
              <div className="mt-2 text-center">
                <span className="text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--accent-gold)]">
                  Champion
                </span>
                <div className="mt-1 flex items-center justify-center gap-1.5">
                  {(() => {
                    const champ =
                      sbMatchup.winner === 'home'
                        ? sbMatchup.homeTeam
                        : sbMatchup.awayTeam;
                    return champ ? (
                      <>
                        <TeamLogo
                          abbreviation={champ.abbreviation}
                          primaryColor={champ.primary_color}
                          secondaryColor={champ.secondary_color}
                          size="sm"
                        />
                        <span className="text-[13px] font-bold text-[var(--text-primary)]">
                          {champ.city} {champ.name}
                        </span>
                      </>
                    ) : null;
                  })()}
                </div>
              </div>
            )}
          </div>

          {/* --- Right Conference --- */}
          {confList.length >= 2 && (
            <div className="flex flex-col items-start">
              {/* Conference label */}
              <div className="mb-3 w-full text-center">
                <span className="text-[11px] font-bold uppercase tracking-[0.15em] text-[var(--text-secondary)]">
                  {rightConf}
                </span>
              </div>

              <div className="flex items-center gap-0">
                {/* Connector from The Big Game */}
                <Connector direction="straight" />

                {/* Conference Championship */}
                <div className="flex flex-col justify-center">
                  <MatchupBox matchup={rightBracket.champ} userTeamId={userTeamId} />
                </div>

                {/* Connector lines CHAMP -> DIV */}
                <div className="flex flex-col gap-4 justify-center">
                  <Connector direction="down" />
                  <Connector direction="up" />
                </div>

                {/* Divisional column */}
                <div className="flex flex-col gap-8 justify-center">
                  {rightBracket.div.map((m, i) => (
                    <MatchupBox key={`rdiv-${i}`} matchup={m} userTeamId={userTeamId} />
                  ))}
                </div>

                {/* Connector lines DIV -> WC */}
                <div className="flex flex-col gap-6 justify-center">
                  <Connector direction="down" />
                  <Connector direction="straight" />
                  <Connector direction="up" />
                </div>

                {/* Wild Card column */}
                <div className="flex flex-col gap-4 justify-center">
                  {rightBracket.wc.map((m, i) => (
                    <MatchupBox key={`rwc-${i}`} matchup={m} userTeamId={userTeamId} />
                  ))}
                  {rightBracket.wc.length === 0 && (
                    <div className="flex items-center justify-center" style={{ width: 180, height: 80 }}>
                      <span className="text-[10px] text-[var(--text-muted)]">No WC games</span>
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
