import { useLegacy, useAwards } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { motion } from 'framer-motion';
import {
  Award as AwardIcon, Trophy, TrendingUp, Calendar,
  Star, Target, Medal,
} from 'lucide-react';
import {
  RadialBarChart, RadialBar, PolarAngleAxis,
} from 'recharts';

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

function playoffBadge(result: string) {
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
  const seasons = legacy?.seasons ?? [];
  const totalGames = totalWins + totalLosses + totalTies;
  const winPct = totalGames > 0 ? ((totalWins / totalGames) * 100).toFixed(1) : '0.0';

  return (
    <div className="space-y-6">
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-gold)]/10">
            <AwardIcon className="h-5 w-5 text-[var(--accent-gold)]" />
          </div>
          <div>
            <h1 className="font-display text-2xl">Legacy</h1>
            <p className="text-sm text-[var(--text-secondary)]">
              {coach?.name}&apos;s coaching career
            </p>
          </div>
        </div>
      </motion.div>

      {/* Top Row: Legacy Score + Career Stats */}
      <div className="grid gap-6 lg:grid-cols-3">
        {/* Legacy Gauge */}
        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.4, delay: 0.1 }}
        >
          <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
            <CardContent className="flex flex-col items-center p-6">
              <h2 className="mb-1 font-display text-sm uppercase tracking-widest text-[var(--text-muted)]">
                Legacy Score
              </h2>
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
            </CardContent>
          </Card>
        </motion.div>

        {/* Career Stats */}
        <motion.div
          className="lg:col-span-2"
          initial={{ opacity: 0, x: 10 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.4, delay: 0.2 }}
        >
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="p-5 text-center">
                <TrendingUp className="mx-auto h-5 w-5 text-[var(--accent-blue)] mb-2" />
                <p className="text-xs text-[var(--text-muted)] uppercase">Record</p>
                <p className="font-display text-2xl mt-1">
                  {totalWins}-{totalLosses}{totalTies > 0 ? `-${totalTies}` : ''}
                </p>
                <p className="text-xs text-[var(--text-secondary)] mt-1">
                  {winPct}% Win Rate
                </p>
              </CardContent>
            </Card>

            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="p-5 text-center">
                <Trophy className="mx-auto h-5 w-5 text-[var(--accent-gold)] mb-2" />
                <p className="text-xs text-[var(--text-muted)] uppercase">Championships</p>
                <p className="font-display text-2xl text-[var(--accent-gold)] mt-1">
                  {championships}
                </p>
                <p className="text-xs text-[var(--text-secondary)] mt-1">
                  Titles Won
                </p>
              </CardContent>
            </Card>

            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="p-5 text-center">
                <Target className="mx-auto h-5 w-5 text-green-400 mb-2" />
                <p className="text-xs text-[var(--text-muted)] uppercase">Playoffs</p>
                <p className="font-display text-2xl text-green-400 mt-1">
                  {playoffAppearances}
                </p>
                <p className="text-xs text-[var(--text-secondary)] mt-1">
                  Appearances
                </p>
              </CardContent>
            </Card>

            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="p-5 text-center">
                <Calendar className="mx-auto h-5 w-5 text-[var(--text-secondary)] mb-2" />
                <p className="text-xs text-[var(--text-muted)] uppercase">Seasons</p>
                <p className="font-display text-2xl mt-1">
                  {seasons.length}
                </p>
                <p className="text-xs text-[var(--text-secondary)] mt-1">
                  Coached
                </p>
              </CardContent>
            </Card>

            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="p-5 text-center">
                <Star className="mx-auto h-5 w-5 text-yellow-400 mb-2" />
                <p className="text-xs text-[var(--text-muted)] uppercase">Total Wins</p>
                <p className="font-display text-2xl text-yellow-400 mt-1">
                  {totalWins}
                </p>
                <p className="text-xs text-[var(--text-secondary)] mt-1">
                  Career Victories
                </p>
              </CardContent>
            </Card>

            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="p-5 text-center">
                <Medal className="mx-auto h-5 w-5 text-purple-400 mb-2" />
                <p className="text-xs text-[var(--text-muted)] uppercase">Awards</p>
                <p className="font-display text-2xl text-purple-400 mt-1">
                  {(awards ?? []).length}
                </p>
                <p className="text-xs text-[var(--text-secondary)] mt-1">
                  Accolades
                </p>
              </CardContent>
            </Card>
          </div>
        </motion.div>
      </div>

      {/* Season History Table */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, delay: 0.3 }}
      >
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardHeader className="pb-2">
            <CardTitle className="font-display text-base flex items-center gap-2">
              <Calendar className="h-4 w-4 text-[var(--accent-blue)]" /> Season History
            </CardTitle>
          </CardHeader>
          <CardContent>
            {seasons.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <Calendar className="h-8 w-8 text-[var(--text-muted)] mb-2" />
                <p className="text-sm text-[var(--text-secondary)]">No seasons completed yet</p>
                <p className="text-xs text-[var(--text-muted)] mt-1">
                  Complete your first season to see your coaching history
                </p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Year</TableHead>
                    <TableHead>Team</TableHead>
                    <TableHead>Record</TableHead>
                    <TableHead>Win %</TableHead>
                    <TableHead>Playoffs</TableHead>
                    <TableHead>Notable</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {seasons.map((season, i) => {
                    const games = season.wins + season.losses + season.ties;
                    const pct = games > 0 ? ((season.wins / games) * 100).toFixed(1) : '0.0';
                    return (
                      <motion.tr
                        key={`${season.year}-${i}`}
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ delay: i * 0.05 }}
                        className="border-b border-[var(--border)]"
                      >
                        <TableCell className="font-display">{season.year}</TableCell>
                        <TableCell className="text-sm">{season.team_name}</TableCell>
                        <TableCell className="font-mono">
                          {season.wins}-{season.losses}{season.ties > 0 ? `-${season.ties}` : ''}
                        </TableCell>
                        <TableCell className="font-mono text-sm">{pct}%</TableCell>
                        <TableCell>
                          {playoffBadge(season.playoff_result) !== null ? (
                            <Badge
                              variant="outline"
                              className={`text-[10px] ${playoffBadge(season.playoff_result)}`}
                            >
                              {season.playoff_result.replace(/_/g, ' ')}
                            </Badge>
                          ) : (
                            <span className="text-xs text-[var(--text-muted)]">--</span>
                          )}
                        </TableCell>
                        <TableCell className="text-sm text-[var(--text-secondary)] max-w-xs truncate">
                          {season.notable_event || '--'}
                        </TableCell>
                      </motion.tr>
                    );
                  })}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      </motion.div>

      {/* Awards Section */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, delay: 0.4 }}
      >
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardHeader className="pb-2">
            <CardTitle className="font-display text-base flex items-center gap-2">
              <AwardIcon className="h-4 w-4 text-[var(--accent-gold)]" /> Awards
            </CardTitle>
          </CardHeader>
          <CardContent>
            {awardsLoading ? (
              <p className="text-sm text-[var(--text-secondary)]">Loading awards...</p>
            ) : (awards ?? []).length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <AwardIcon className="h-8 w-8 text-[var(--text-muted)] mb-2" />
                <p className="text-sm text-[var(--text-secondary)]">No awards won yet</p>
                <p className="text-xs text-[var(--text-muted)] mt-1">
                  Lead your team to greatness to earn recognition
                </p>
              </div>
            ) : (
              <div className="space-y-2">
                {(awards ?? []).map((award, i) => (
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
                        <Badge variant="outline" className="text-[10px]">
                          {award.season_year}
                        </Badge>
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
          </CardContent>
        </Card>
      </motion.div>
    </div>
  );
}
