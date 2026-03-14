import { useParams } from 'react-router-dom';
import { useBoxScore } from '@/hooks/useApi';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TeamBadge } from '@/components/TeamBadge';
import { motion } from 'framer-motion';

export default function BoxScore() {
  const { id } = useParams<{ id: string }>();
  const { data, isLoading } = useBoxScore(Number(id));

  if (isLoading) return <p className="text-[var(--text-secondary)]">Loading box score...</p>;
  if (!data) return <p className="text-[var(--text-secondary)]">Game not found.</p>;

  const { game, home, away } = data;
  const homeTeam = game.home_team;
  const awayTeam = game.away_team;
  const homeWon = (game.home_score ?? 0) > (game.away_score ?? 0);

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      {/* Scoreboard */}
      <Card className="overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
        {/* Top accent gradient using both team colors */}
        <div
          className="h-[3px] w-full"
          style={{
            background: `linear-gradient(90deg, ${awayTeam?.primary_color ?? '#333'} 0%, ${awayTeam?.primary_color ?? '#333'} 45%, ${homeTeam?.primary_color ?? '#333'} 55%, ${homeTeam?.primary_color ?? '#333'} 100%)`,
          }}
        />
        <div className="flex items-center justify-between p-6">
          <TeamScore team={awayTeam} score={game.away_score ?? 0} won={!homeWon} />
          <div className="text-center">
            <Badge variant="secondary" className="mb-1 bg-[var(--bg-elevated)] text-xs font-bold uppercase tracking-widest">Final</Badge>
            <p className="text-[10px] font-medium text-[var(--text-muted)]">Week {game.week}</p>
            {game.weather && game.weather !== 'clear' && game.weather !== 'dome' && (
              <p className="text-[10px] text-[var(--text-muted)]">{game.weather}</p>
            )}
          </div>
          <TeamScore team={homeTeam} score={game.home_score ?? 0} won={homeWon} isHome />
        </div>
        {game.turning_point && (
          <div className="border-t border-[var(--border)] bg-[var(--bg-primary)] px-6 py-3">
            <p className="text-xs text-[var(--text-secondary)]">
              <span className="font-bold uppercase tracking-wider text-[var(--accent-gold)]">Turning Point</span>
              <span className="mx-2 text-[var(--text-muted)]">/</span>
              {game.turning_point}
            </p>
          </div>
        )}
      </Card>

      {/* Team Totals */}
      <div className="grid grid-cols-2 gap-4">
        <TotalCard label={awayTeam?.abbreviation ?? 'AWY'} totals={away?.totals ?? {}} color={awayTeam?.primary_color} />
        <TotalCard label={homeTeam?.abbreviation ?? 'HME'} totals={home?.totals ?? {}} color={homeTeam?.primary_color} />
      </div>

      {/* Player Stats */}
      <Tabs defaultValue="away">
        <TabsList className="bg-[var(--bg-elevated)]">
          <TabsTrigger value="away" className="text-xs font-semibold uppercase tracking-wider">{awayTeam?.abbreviation} Stats</TabsTrigger>
          <TabsTrigger value="home" className="text-xs font-semibold uppercase tracking-wider">{homeTeam?.abbreviation} Stats</TabsTrigger>
        </TabsList>
        <TabsContent value="away" className="mt-4">
          <PlayerStatsTable players={away?.players ?? []} />
        </TabsContent>
        <TabsContent value="home" className="mt-4">
          <PlayerStatsTable players={home?.players ?? []} />
        </TabsContent>
      </Tabs>
    </div>
  );
}

function TeamScore({ team, score, won, isHome }: {
  team?: { city: string; name: string; abbreviation: string; primary_color: string; secondary_color: string };
  score: number;
  won: boolean;
  isHome?: boolean;
}) {
  return (
    <div className={`flex items-center gap-4 ${isHome ? 'flex-row-reverse' : ''}`}>
      <TeamBadge
        abbreviation={team?.abbreviation}
        primaryColor={team?.primary_color}
        secondaryColor={team?.secondary_color}
        size="lg"
      />
      <div className={isHome ? 'text-right' : ''}>
        <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">{isHome ? 'Home' : 'Away'}</p>
        <p className="font-display text-lg tracking-tight">{team?.city} {team?.name}</p>
      </div>
      <motion.span
        initial={{ scale: 0.5, opacity: 0 }}
        animate={{ scale: 1, opacity: 1 }}
        transition={{ delay: 0.3, type: 'spring' }}
        className={`score-display text-5xl ${won ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`}
      >
        {score}
      </motion.span>
    </div>
  );
}

function TotalCard({ label, totals, color }: {
  label: string;
  totals: Record<string, number>;
  color?: string;
}) {
  return (
    <Card className="overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
      <div className="h-[2px] w-full" style={{ background: `linear-gradient(90deg, ${color ?? '#333'}, transparent)` }} />
      <CardHeader className="pb-2">
        <CardTitle className="font-display text-xs uppercase tracking-[0.15em]" style={{ color }}>{label}</CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-3 gap-2 text-center text-xs">
        <div>
          <p className="text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Pass Yds</p>
          <p className="font-stat text-xl">{totals.pass_yards ?? 0}</p>
        </div>
        <div>
          <p className="text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Rush Yds</p>
          <p className="font-stat text-xl">{totals.rush_yards ?? 0}</p>
        </div>
        <div>
          <p className="text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Total Yds</p>
          <p className="font-stat text-xl">{totals.total_yards ?? 0}</p>
        </div>
      </CardContent>
    </Card>
  );
}

function PlayerStatsTable({ players }: { players: { id: number; name: string; position: string; [k: string]: string | number }[] }) {
  const statCols = ['pass_yards', 'pass_tds', 'rush_yards', 'rush_tds', 'receptions', 'rec_yards', 'rec_tds', 'tackles', 'sacks'];
  const colLabels: Record<string, string> = {
    pass_yards: 'PYD', pass_tds: 'PTD', rush_yards: 'RYD', rush_tds: 'RTD',
    receptions: 'REC', rec_yards: 'REYD', rec_tds: 'RETD', tackles: 'TKL', sacks: 'SCK',
  };

  // Only show players with at least one non-zero stat
  const active = players.filter((p) =>
    statCols.some((c) => Number(p[c] ?? 0) > 0)
  );

  return (
    <Card className="overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
      <Table>
        <TableHeader>
          <TableRow className="border-[var(--border)] hover:bg-transparent">
            <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Player</TableHead>
            <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Pos</TableHead>
            {statCols.map((c) => (
              <TableHead key={c} className="text-center text-[10px] font-bold uppercase tracking-[0.15em]">{colLabels[c]}</TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {active.map((p) => (
            <TableRow key={p.id} className="border-[var(--border)]">
              <TableCell className="text-[13px] font-medium">{p.name}</TableCell>
              <TableCell><Badge variant="outline" className="text-[10px] font-semibold border-[var(--border)]">{p.position}</Badge></TableCell>
              {statCols.map((c) => {
                const v = Number(p[c] ?? 0);
                return (
                  <TableCell key={c} className={`text-center font-stat text-xs ${v > 0 ? '' : 'text-[var(--text-muted)]'}`}>
                    {v || '--'}
                  </TableCell>
                );
              })}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Card>
  );
}
