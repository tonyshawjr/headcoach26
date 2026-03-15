import { useOffseasonReport } from '@/hooks/useApi';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { motion } from 'framer-motion';
import { Link } from 'react-router-dom';
import {
  Trophy, TrendingUp, TrendingDown, UserMinus, FileText, Calendar,
  ArrowRight, Sparkles,
} from 'lucide-react';

const sectionVariants = {
  hidden: { opacity: 0, y: 20 },
  visible: (i: number) => ({
    opacity: 1,
    y: 0,
    transition: { duration: 0.4, delay: i * 0.12 },
  }),
};

function awardLabel(type: string): string {
  const labels: Record<string, string> = {
    'MVP': 'Most Valuable Player',
    'Offensive Player of the Year': 'Offensive Player of the Year',
    'OPOY': 'Offensive Player of the Year',
    'Defensive Player of the Year': 'Defensive Player of the Year',
    'DPOY': 'Defensive Player of the Year',
    'Coach of the Year': 'Coach of the Year',
    'COTY': 'Coach of the Year',
  };
  return labels[type] ?? type;
}

function awardAccent(type: string): string {
  if (type === 'MVP') return 'border-[var(--accent-gold)]/40 bg-[var(--accent-gold)]/5';
  return 'border-[var(--border)] bg-[var(--bg-primary)]';
}

export default function OffseasonReport() {
  const { data: report, isLoading, isError } = useOffseasonReport();

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <Sparkles className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-gold)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Building offseason report...</p>
        </div>
      </div>
    );
  }

  if (isError || !report) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <FileText className="mx-auto h-8 w-8 text-[var(--text-muted)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">No offseason data available.</p>
          <p className="mt-1 text-xs text-[var(--text-muted)]">
            Complete a season and process the offseason to see the report.
          </p>
        </div>
      </div>
    );
  }

  const { awards, development, contracts_expired, draft_class_size, schedule_games, new_season_year } = report;
  const { improved, declined, retired } = development;

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-gold)]/10">
            <Sparkles className="h-5 w-5 text-[var(--accent-gold)]" />
          </div>
          <div>
            <h1 className="font-display text-2xl">Offseason Report</h1>
            <p className="text-sm text-[var(--text-secondary)]">
              {new_season_year} season preparation summary
            </p>
          </div>
        </div>
      </motion.div>

      {/* 1. Season Awards */}
      <motion.div
        custom={0}
        initial="hidden"
        animate="visible"
        variants={sectionVariants}
      >
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardHeader className="pb-2">
            <CardTitle className="font-display text-base flex items-center gap-2">
              <Trophy className="h-4 w-4 text-[var(--accent-gold)]" /> Season Awards
            </CardTitle>
          </CardHeader>
          <CardContent>
            {awards.length === 0 ? (
              <p className="text-sm text-[var(--text-muted)] py-4 text-center">
                No awards recorded for this season.
              </p>
            ) : (
              <div className="grid gap-3 sm:grid-cols-2">
                {awards.map((award, i) => (
                  <motion.div
                    key={`${award.type}-${i}`}
                    initial={{ opacity: 0, scale: 0.95 }}
                    animate={{ opacity: 1, scale: 1 }}
                    transition={{ duration: 0.3, delay: i * 0.08 }}
                    className={`rounded-lg border px-4 py-3 ${awardAccent(award.type)}`}
                  >
                    <div className="flex items-start gap-3">
                      <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${
                        award.type === 'MVP'
                          ? 'bg-[var(--accent-gold)]/20'
                          : 'bg-[var(--accent-blue)]/10'
                      }`}>
                        <Trophy className={`h-4 w-4 ${
                          award.type === 'MVP'
                            ? 'text-[var(--accent-gold)]'
                            : 'text-[var(--accent-blue)]'
                        }`} />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                          {awardLabel(award.type)}
                        </p>
                        <p className={`text-sm font-semibold mt-0.5 ${
                          award.type === 'MVP' ? 'text-[var(--accent-gold)]' : ''
                        }`}>
                          {award.player_name ?? award.coach_name ?? 'Unknown'}
                        </p>
                        <div className="flex items-center gap-2 mt-1">
                          <Badge variant="outline" className="text-[10px]">
                            {award.team_name}
                          </Badge>
                          {award.stats && (
                            <span className="text-[10px] text-[var(--text-muted)] truncate">
                              {award.stats}
                            </span>
                          )}
                        </div>
                      </div>
                    </div>
                  </motion.div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </motion.div>

      {/* 2. Player Development */}
      <motion.div
        custom={1}
        initial="hidden"
        animate="visible"
        variants={sectionVariants}
      >
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardHeader className="pb-2">
            <CardTitle className="font-display text-base flex items-center gap-2">
              <TrendingUp className="h-4 w-4 text-green-400" /> Player Development
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-6">
            {/* Improved */}
            {improved.length > 0 && (
              <div>
                <div className="flex items-center gap-2 mb-2">
                  <TrendingUp className="h-3.5 w-3.5 text-green-400" />
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-green-400">
                    Improved ({improved.length})
                  </h3>
                </div>
                <div className="rounded-lg border border-green-500/20 overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="text-xs">Name</TableHead>
                        <TableHead className="text-xs">Pos</TableHead>
                        <TableHead className="text-xs text-right">Old OVR</TableHead>
                        <TableHead className="text-xs text-center"></TableHead>
                        <TableHead className="text-xs text-right">New OVR</TableHead>
                        <TableHead className="text-xs text-right">Change</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {improved.map((p, i) => (
                        <TableRow key={`imp-${i}`} className="border-b border-green-500/10">
                          <TableCell className="text-sm font-medium">{p.name}</TableCell>
                          <TableCell>
                            <Badge variant="outline" className="text-[10px]">{p.position}</Badge>
                          </TableCell>
                          <TableCell className="text-right text-sm text-[var(--text-secondary)] font-mono">
                            {p.old_ovr}
                          </TableCell>
                          <TableCell className="text-center text-[var(--text-muted)]">
                            <ArrowRight className="h-3 w-3 mx-auto" />
                          </TableCell>
                          <TableCell className="text-right text-sm font-mono font-semibold text-green-400">
                            {p.new_ovr}
                          </TableCell>
                          <TableCell className="text-right">
                            <Badge className="bg-green-500/15 text-green-400 border-green-500/30 text-[10px]">
                              +{p.change}
                            </Badge>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </div>
            )}

            {/* Declined */}
            {declined.length > 0 && (
              <div>
                <div className="flex items-center gap-2 mb-2">
                  <TrendingDown className="h-3.5 w-3.5 text-red-400" />
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-red-400">
                    Declined ({declined.length})
                  </h3>
                </div>
                <div className="rounded-lg border border-red-500/20 overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="text-xs">Name</TableHead>
                        <TableHead className="text-xs">Pos</TableHead>
                        <TableHead className="text-xs text-right">Old OVR</TableHead>
                        <TableHead className="text-xs text-center"></TableHead>
                        <TableHead className="text-xs text-right">New OVR</TableHead>
                        <TableHead className="text-xs text-right">Change</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {declined.map((p, i) => (
                        <TableRow key={`dec-${i}`} className="border-b border-red-500/10">
                          <TableCell className="text-sm font-medium">{p.name}</TableCell>
                          <TableCell>
                            <Badge variant="outline" className="text-[10px]">{p.position}</Badge>
                          </TableCell>
                          <TableCell className="text-right text-sm text-[var(--text-secondary)] font-mono">
                            {p.old_ovr}
                          </TableCell>
                          <TableCell className="text-center text-[var(--text-muted)]">
                            <ArrowRight className="h-3 w-3 mx-auto" />
                          </TableCell>
                          <TableCell className="text-right text-sm font-mono font-semibold text-red-400">
                            {p.new_ovr}
                          </TableCell>
                          <TableCell className="text-right">
                            <Badge className="bg-red-500/15 text-red-400 border-red-500/30 text-[10px]">
                              {p.change}
                            </Badge>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </div>
            )}

            {/* Retired */}
            {retired.length > 0 && (
              <div>
                <div className="flex items-center gap-2 mb-2">
                  <UserMinus className="h-3.5 w-3.5 text-[var(--text-muted)]" />
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                    Retired ({retired.length})
                  </h3>
                </div>
                <div className="rounded-lg border border-[var(--border)] overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="text-xs">Name</TableHead>
                        <TableHead className="text-xs">Pos</TableHead>
                        <TableHead className="text-xs text-right">Final OVR</TableHead>
                        <TableHead className="text-xs text-right">Age</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {retired.map((p, i) => (
                        <TableRow key={`ret-${i}`} className="text-[var(--text-muted)]">
                          <TableCell className="text-sm">{p.name}</TableCell>
                          <TableCell>
                            <Badge variant="outline" className="text-[10px] opacity-60">{p.position}</Badge>
                          </TableCell>
                          <TableCell className="text-right text-sm font-mono">{p.final_ovr}</TableCell>
                          <TableCell className="text-right text-sm font-mono">{p.age}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </div>
            )}

            {improved.length === 0 && declined.length === 0 && retired.length === 0 && (
              <p className="text-sm text-[var(--text-muted)] py-4 text-center">
                No player development data available.
              </p>
            )}
          </CardContent>
        </Card>
      </motion.div>

      {/* 3. Contract Expirations */}
      <motion.div
        custom={2}
        initial="hidden"
        animate="visible"
        variants={sectionVariants}
      >
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardHeader className="pb-2">
            <CardTitle className="font-display text-base flex items-center gap-2">
              <FileText className="h-4 w-4 text-[var(--accent-blue)]" /> Contract Expirations
            </CardTitle>
          </CardHeader>
          <CardContent>
            {contracts_expired.length === 0 ? (
              <p className="text-sm text-[var(--text-muted)] py-4 text-center">
                No contracts expired this offseason.
              </p>
            ) : (
              <>
                <p className="text-xs text-[var(--text-secondary)] mb-3">
                  These players are now free agents.
                </p>
                <div className="rounded-lg border border-[var(--border)] overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="text-xs">Player</TableHead>
                        <TableHead className="text-xs">Pos</TableHead>
                        <TableHead className="text-xs text-right">OVR</TableHead>
                        <TableHead className="text-xs">Former Team</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {contracts_expired.map((p, i) => (
                        <TableRow key={`exp-${i}`}>
                          <TableCell className="text-sm font-medium">{p.name}</TableCell>
                          <TableCell>
                            <Badge variant="outline" className="text-[10px]">{p.position}</Badge>
                          </TableCell>
                          <TableCell className="text-right text-sm font-mono font-semibold">
                            {p.overall_rating}
                          </TableCell>
                          <TableCell className="text-sm text-[var(--text-secondary)]">
                            {p.team_name}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </>
            )}
          </CardContent>
        </Card>
      </motion.div>

      {/* 4. Draft Class Preview + 5. New Schedule — side by side */}
      <div className="grid gap-4 sm:grid-cols-2">
        {/* Draft Class */}
        <motion.div
          custom={3}
          initial="hidden"
          animate="visible"
          variants={sectionVariants}
        >
          <Card className="border-[var(--border)] bg-[var(--bg-surface)] h-full">
            <CardContent className="p-5 flex flex-col justify-between h-full">
              <div>
                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--accent-blue)]/10 mb-3">
                  <Sparkles className="h-4.5 w-4.5 text-[var(--accent-blue)]" />
                </div>
                <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-primary)]">
                  Draft Class Preview
                </h3>
                <p className="text-sm text-[var(--text-secondary)] mt-2">
                  {draft_class_size > 0 ? (
                    <>
                      <span className="text-lg font-display text-[var(--accent-blue)]">{draft_class_size}</span>
                      {' '}prospects generated for {new_season_year}.
                    </>
                  ) : (
                    <>No draft class has been generated yet.</>
                  )}
                </p>
                <p className="text-xs text-[var(--text-muted)] mt-1">
                  Visit the Draft Room to start scouting.
                </p>
              </div>
              <Link
                to="/draft"
                className="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-[var(--accent-blue)] hover:underline"
              >
                Go to Draft Room <ArrowRight className="h-3 w-3" />
              </Link>
            </CardContent>
          </Card>
        </motion.div>

        {/* New Schedule */}
        <motion.div
          custom={4}
          initial="hidden"
          animate="visible"
          variants={sectionVariants}
        >
          <Card className="border-[var(--border)] bg-[var(--bg-surface)] h-full">
            <CardContent className="p-5 flex flex-col justify-between h-full">
              <div>
                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-green-500/10 mb-3">
                  <Calendar className="h-4.5 w-4.5 text-green-400" />
                </div>
                <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-primary)]">
                  New Schedule
                </h3>
                <p className="text-sm text-[var(--text-secondary)] mt-2">
                  {schedule_games > 0 ? (
                    <>
                      Your <span className="text-lg font-display text-green-400">{new_season_year}</span> schedule
                      is ready! <span className="font-semibold">{schedule_games}</span> games across 18 weeks.
                    </>
                  ) : (
                    <>Schedule will be generated when the new season begins.</>
                  )}
                </p>
              </div>
              <Link
                to="/schedule"
                className="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-green-400 hover:underline"
              >
                View Schedule <ArrowRight className="h-3 w-3" />
              </Link>
            </CardContent>
          </Card>
        </motion.div>
      </div>
    </div>
  );
}
