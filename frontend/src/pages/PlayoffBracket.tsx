import { useState, lazy, Suspense } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useNavigate } from 'react-router-dom';
import { TeamBadge } from '@/components/TeamBadge';
import { PageLayout, PageHeader, Section, EmptyBlock, SportsTabs } from '@/components/ui/sports-ui';
import { motion } from 'framer-motion';
import { Trophy, Crown, Shield } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

const Scenarios = lazy(() => import('./Scenarios'));

interface PlayoffTeam {
  id: number;
  city: string;
  name: string;
  abbreviation: string;
  primary_color: string;
  secondary_color: string;
  seed: number;
  wins: number;
  losses: number;
  is_division_winner?: boolean;
  is_bye?: boolean;
}

interface PlayoffGame {
  game_id: number;
  home_team: PlayoffTeam | null;
  away_team: PlayoffTeam | null;
  home_score: number | null;
  away_score: number | null;
  is_played: boolean;
  winner_id: number | null;
  round: string;
  round_label: string;
  conference?: string;
}

interface BracketData {
  rounds: Record<string, PlayoffGame[]>;
  seeding: Record<string, PlayoffTeam[]>;
  current_round: string | null;
  next_round: string | null;
  is_complete: boolean;
  champion: PlayoffTeam | null;
}

interface SeedingData {
  conferences: Record<string, PlayoffTeam[]>;
}

const ROUND_ORDER = ['wild_card', 'divisional', 'conference_championship', 'big_game'];
const ROUND_LABELS: Record<string, string> = {
  wild_card: 'Wild Card',
  divisional: 'Divisional',
  conference_championship: 'Conference Championship',
  big_game: 'The Big Game',
};

function MatchupCard({
  game,
  myTeamId,
  navigate,
}: {
  game: PlayoffGame;
  myTeamId: number;
  navigate: (path: string) => void;
}) {
  const home = game.home_team;
  const away = game.away_team;
  if (!home || !away) return null;

  const homeWon = game.is_played && game.winner_id === home.id;
  const awayWon = game.is_played && game.winner_id === away.id;
  const isMyGame = home.id === myTeamId || away.id === myTeamId;

  return (
    <div
      className={`rounded-lg border overflow-hidden transition-all ${
        isMyGame ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/5' : 'border-[var(--border)] bg-[var(--bg-surface)]'
      } ${game.is_played ? 'cursor-pointer hover:bg-[var(--bg-elevated)]' : ''}`}
      onClick={() => game.is_played && navigate(`/box-score/${game.game_id}`)}
    >
      {/* Top accent */}
      <div
        className="h-[2px] w-full"
        style={{ background: `linear-gradient(90deg, ${away.primary_color}, ${home.primary_color})` }}
      />

      {/* Away team */}
      <TeamRow
        team={away}
        score={game.away_score}
        isWinner={awayWon}
        isLoser={homeWon}
        isPlayed={game.is_played}
        myTeamId={myTeamId}
      />

      <div className="h-px bg-[var(--border)]" />

      {/* Home team */}
      <TeamRow
        team={home}
        score={game.home_score}
        isWinner={homeWon}
        isLoser={awayWon}
        isPlayed={game.is_played}
        myTeamId={myTeamId}
        isHome
      />
    </div>
  );
}

function TeamRow({
  team,
  score,
  isWinner,
  isLoser,
  isPlayed,
  myTeamId,
  isHome: _isHome,
}: {
  team: PlayoffTeam;
  score: number | null;
  isWinner: boolean;
  isLoser: boolean;
  isPlayed: boolean;
  myTeamId: number;
  isHome?: boolean;
}) {
  const isMine = team.id === myTeamId;

  return (
    <div className={`flex items-center justify-between px-3 py-2 ${isLoser ? 'opacity-40' : ''}`}>
      <div className="flex items-center gap-2 min-w-0">
        <span className="text-[10px] font-bold text-[var(--text-muted)] w-4 text-center shrink-0">
          {team.seed}
        </span>
        <TeamBadge
          abbreviation={team.abbreviation}
          primaryColor={team.primary_color}
          secondaryColor={team.secondary_color}
          size="xs"
        />
        <span className={`text-[13px] font-semibold truncate ${isMine ? 'text-[var(--accent-blue)]' : ''} ${isWinner ? 'font-bold' : ''}`}>
          {team.city} {team.name}
        </span>
        {isWinner && <Trophy className="h-3 w-3 text-[var(--accent-gold)] shrink-0" />}
      </div>
      <div className="flex items-center gap-2 shrink-0">
        {isPlayed && score !== null ? (
          <span className={`font-stat text-lg ${isWinner ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`}>
            {score}
          </span>
        ) : (
          <span className="text-xs text-[var(--text-muted)]">{team.wins}-{team.losses}</span>
        )}
      </div>
    </div>
  );
}

function SeedingPreview({ seeding, myTeamId }: { seeding: Record<string, PlayoffTeam[]>; myTeamId: number }) {
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      {Object.entries(seeding).map(([conf, teams]) => (
        <Section key={conf} title={`${conf} Conference`} accentColor="var(--accent-gold)">
          <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
            <div className="h-[2px] w-full bg-gradient-to-r from-[var(--accent-gold)] to-transparent" />
            {teams.map((t, i) => {
              const isMine = t.id === myTeamId;
              return (
                <div
                  key={t.id}
                  className={`flex items-center justify-between px-4 py-2.5 border-b border-[var(--border)] last:border-b-0 ${
                    isMine ? 'bg-[var(--accent-blue)]/5' : ''
                  }`}
                >
                  <div className="flex items-center gap-3">
                    <span className="font-stat text-sm font-bold text-[var(--text-muted)] w-5 text-center">
                      {t.seed}
                    </span>
                    <TeamBadge
                      abbreviation={t.abbreviation}
                      primaryColor={t.primary_color}
                      secondaryColor={t.secondary_color}
                      size="xs"
                    />
                    <div>
                      <span className={`text-[13px] font-semibold ${isMine ? 'text-[var(--accent-blue)]' : ''}`}>
                        {t.city} {t.name}
                      </span>
                      <div className="flex items-center gap-1.5 mt-0.5">
                        <span className="text-[10px] text-[var(--text-muted)]">{t.wins}-{t.losses}</span>
                        {t.is_division_winner && (
                          <Badge variant="outline" className="text-[8px] bg-green-500/10 text-green-400 border-green-500/20 px-1 py-0">
                            DIV
                          </Badge>
                        )}
                        {!t.is_division_winner && (
                          <Badge variant="outline" className="text-[8px] bg-blue-500/10 text-blue-400 border-blue-500/20 px-1 py-0">
                            WC
                          </Badge>
                        )}
                        {t.is_bye && (
                          <Badge variant="outline" className="text-[8px] bg-yellow-500/10 text-yellow-400 border-yellow-500/20 px-1 py-0">
                            BYE
                          </Badge>
                        )}
                      </div>
                    </div>
                  </div>
                  <div className="text-right">
                    {t.is_bye && (
                      <span className="text-[10px] font-semibold text-[var(--accent-gold)]">1st Round Bye</span>
                    )}
                    {!t.is_bye && i >= 4 && (
                      <span className="text-[10px] text-[var(--text-muted)]">
                        @ #{teams.length - (i - 3)} seed
                      </span>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </Section>
      ))}
    </div>
  );
}

export default function PlayoffBracket() {
  const league = useAuthStore((s) => s.league);
  const myTeam = useAuthStore((s) => s.team);
  const navigate = useNavigate();
  const myTeamId = myTeam?.id ?? 0;
  const phase = league?.phase ?? '';
  const [regSeasonTab, setRegSeasonTab] = useState<'picture' | 'scenarios'>('picture');

  const { data: bracket } = useQuery<BracketData>({
    queryKey: ['playoffBracket', league?.id],
    queryFn: () => api.get(`/leagues/${league?.id}/playoff-bracket`),
    enabled: !!league?.id,
  });

  const { data: seeding } = useQuery<SeedingData>({
    queryKey: ['playoffSeeding', league?.id],
    queryFn: () => api.get(`/leagues/${league?.id}/playoff-seeding`),
    enabled: !!league?.id,
  });

  const isPlayoffs = phase === 'playoffs';
  const hasRounds = bracket && bracket.rounds && Object.keys(bracket.rounds).length > 0;

  return (
    <PageLayout>
      <PageHeader
        title={isPlayoffs ? 'Playoff Bracket' : 'Playoff Picture'}
        subtitle={isPlayoffs ? (bracket?.is_complete ? 'Season Complete' : bracket?.current_round ? ROUND_LABELS[bracket.current_round] ?? bracket.current_round : 'Playoffs') : 'If the season ended today...'}
        icon={Trophy}
        accentColor="var(--accent-gold)"
      />

      {/* Champion banner */}
      {bracket?.is_complete && bracket?.champion && (
        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          className="rounded-xl border-2 border-[var(--accent-gold)] bg-gradient-to-r from-[var(--accent-gold)]/10 to-transparent p-6 text-center mb-6"
        >
          <Crown className="h-10 w-10 text-[var(--accent-gold)] mx-auto mb-2" />
          <h2 className="font-display text-3xl text-[var(--accent-gold)]">
            {bracket.champion.city} {bracket.champion.name}
          </h2>
          <p className="text-sm text-[var(--text-secondary)] mt-1">League Champions</p>
        </motion.div>
      )}

      {/* Bracket rounds — shown during actual playoffs */}
      {isPlayoffs && hasRounds && (
        <div className="space-y-8">
          {ROUND_ORDER.filter(r => bracket?.rounds?.[r]?.length).map((roundKey, ri) => {
            const games = bracket!.rounds[roundKey] ?? [];
            const label = ROUND_LABELS[roundKey] ?? roundKey;
            const isCurrentRound = bracket?.current_round === roundKey;

            // Split by conference for non-championship rounds
            const isChampionship = roundKey === 'big_game';

            return (
              <motion.div
                key={roundKey}
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.35, delay: ri * 0.1 }}
              >
                <Section
                  title={label}
                  accentColor={isChampionship ? 'var(--accent-gold)' : 'var(--accent-blue)'}
                >
                  {isCurrentRound && !bracket?.is_complete && (
                    <div className="mb-3">
                      <Badge variant="outline" className="text-[10px] bg-green-500/10 text-green-400 border-green-500/20">
                        Current Round
                      </Badge>
                    </div>
                  )}

                  {isChampionship ? (
                    // Championship — single game, full width
                    <div className="max-w-md mx-auto">
                      {games.map((g) => (
                        <MatchupCard key={g.game_id} game={g} myTeamId={myTeamId} navigate={navigate} />
                      ))}
                    </div>
                  ) : (
                    // Regular rounds — group by conference
                    <div className="grid gap-5 lg:grid-cols-2">
                      {Object.entries(
                        games.reduce<Record<string, PlayoffGame[]>>((acc, g) => {
                          const conf = g.conference ?? 'Unknown';
                          if (!acc[conf]) acc[conf] = [];
                          acc[conf].push(g);
                          return acc;
                        }, {})
                      ).map(([conf, confGames]) => (
                        <div key={conf}>
                          <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)] mb-2">
                            {conf} Conference
                          </p>
                          <div className="space-y-3">
                            {confGames.map((g) => (
                              <MatchupCard key={g.game_id} game={g} myTeamId={myTeamId} navigate={navigate} />
                            ))}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </Section>
              </motion.div>
            );
          })}

          {/* Bye team callout */}
          {bracket?.rounds?.['wild_card'] && seeding?.conferences && (
            <div className="grid gap-5 lg:grid-cols-2">
              {Object.entries(seeding.conferences).map(([conf, teams]) => {
                const byeTeam = teams.find(t => t.is_bye);
                if (!byeTeam) return null;
                const alreadyPlayed = ROUND_ORDER.indexOf(bracket?.current_round ?? '') > 0;
                if (alreadyPlayed) return null;
                return (
                  <div key={conf} className="rounded-lg border border-[var(--accent-gold)]/30 bg-[var(--accent-gold)]/5 px-4 py-3 flex items-center gap-3">
                    <Shield className="h-5 w-5 text-[var(--accent-gold)]" />
                    <div>
                      <p className="text-sm font-semibold">{byeTeam.city} {byeTeam.name}</p>
                      <p className="text-[10px] text-[var(--text-muted)]">#1 seed — First Round Bye</p>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* Regular season: Playoff Picture + Scenarios tabs */}
      {!isPlayoffs && seeding?.conferences && (
        <div className="space-y-6">
          <SportsTabs
            tabs={[
              { key: 'picture', label: 'Playoff Picture' },
              { key: 'scenarios', label: 'What If?' },
            ]}
            activeTab={regSeasonTab}
            onChange={(k) => setRegSeasonTab(k as 'picture' | 'scenarios')}
            accentColor="var(--accent-gold)"
          />

          {regSeasonTab === 'picture' && (
            <>
              <div className="rounded-lg border border-[var(--accent-gold)]/20 bg-[var(--accent-gold)]/5 px-4 py-3">
                <p className="text-sm text-[var(--text-secondary)]">
                  <span className="font-semibold text-[var(--accent-gold)]">Playoff Picture</span> — Current projected playoff teams based on standings. The bracket is finalized when the regular season ends.
                </p>
              </div>
              <SeedingPreview seeding={seeding.conferences} myTeamId={myTeamId} />
            </>
          )}

          {regSeasonTab === 'scenarios' && (
            <Suspense fallback={<div className="flex h-64 items-center justify-center"><Trophy className="h-8 w-8 animate-pulse text-[var(--accent-gold)]" /></div>}>
              <Scenarios embedded />
            </Suspense>
          )}
        </div>
      )}

      {/* No data */}
      {!seeding?.conferences && !hasRounds && (
        <EmptyBlock
          icon={Trophy}
          title="No Playoff Data"
          description="The playoff picture will appear as the season progresses."
        />
      )}
    </PageLayout>
  );
}
