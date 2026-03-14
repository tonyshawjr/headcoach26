import { useAuthStore } from '@/stores/authStore';
import { useStandings } from '@/hooks/useApi';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useNavigate } from 'react-router-dom';
import { TeamBadge } from '@/components/TeamBadge';
import { motion } from 'framer-motion';
import type { StandingsTeam } from '@/api/client';

export default function Standings() {
  const league = useAuthStore((s) => s.league);
  const myTeam = useAuthStore((s) => s.team);
  const { data, isLoading } = useStandings(league?.id);
  const navigate = useNavigate();

  if (isLoading) return <p className="text-[var(--text-secondary)]">Loading standings...</p>;

  const divisions = data?.divisions ?? {};

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2">
        <div className="h-5 w-[3px] rounded-full bg-[var(--accent-gold)]" />
        <h1 className="font-display text-2xl tracking-tight">Standings</h1>
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        {Object.entries(divisions).map(([conf, divs], ci) =>
          Object.entries(divs).map(([div, teams], di) => (
            <motion.div
              key={`${conf}-${div}`}
              initial={{ opacity: 0, y: 16 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.35, delay: (ci * 2 + di) * 0.08 }}
            >
              <Card className="overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
                {/* Division header bar */}
                <CardHeader className="border-b border-[var(--border)] bg-[var(--bg-elevated)]/50 py-2.5 px-4">
                  <CardTitle className="flex items-center gap-2">
                    <span className="font-display text-xs uppercase tracking-[0.15em] text-[var(--text-muted)]">{conf}</span>
                    <div className="h-1 w-1 rounded-full bg-[var(--text-muted)]" />
                    <span className="font-display text-xs uppercase tracking-[0.15em] text-[var(--text-primary)]">{div}</span>
                  </CardTitle>
                </CardHeader>

                <CardContent className="p-0">
                  <Table>
                    <TableHeader>
                      <TableRow className="border-[var(--border)] hover:bg-transparent">
                        <TableHead className="pl-4 text-[10px] font-bold uppercase tracking-[0.15em]">Team</TableHead>
                        <TableHead className="text-center text-[10px] font-bold uppercase tracking-[0.15em]">W</TableHead>
                        <TableHead className="text-center text-[10px] font-bold uppercase tracking-[0.15em]">L</TableHead>
                        <TableHead className="text-center text-[10px] font-bold uppercase tracking-[0.15em]">PCT</TableHead>
                        <TableHead className="text-center text-[10px] font-bold uppercase tracking-[0.15em]">PF</TableHead>
                        <TableHead className="text-center text-[10px] font-bold uppercase tracking-[0.15em]">PA</TableHead>
                        <TableHead className="text-center text-[10px] font-bold uppercase tracking-[0.15em]">DIFF</TableHead>
                        <TableHead className="text-center text-[10px] font-bold uppercase tracking-[0.15em] pr-4">STK</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {(teams as StandingsTeam[]).map((t, idx) => {
                        const isMine = t.id === myTeam?.id;
                        return (
                          <TableRow
                            key={t.id}
                            className={`cursor-pointer border-[var(--border)] transition-colors hover:bg-[var(--bg-elevated)] ${
                              isMine ? 'bg-[var(--accent-blue)]/[0.06]' : ''
                            }`}
                            onClick={() => navigate(`/team/${t.id}`)}
                          >
                            <TableCell className="pl-4">
                              <div className="flex items-center gap-2.5">
                                <span className="w-4 text-center text-[10px] font-bold text-[var(--text-muted)]">{idx + 1}</span>
                                <TeamBadge
                                  abbreviation={t.abbreviation}
                                  primaryColor={t.primary_color}
                                  secondaryColor={t.secondary_color}
                                  size="xs"
                                />
                                <span className={`text-[13px] font-medium ${isMine ? 'font-bold text-[var(--accent-blue)]' : ''}`}>
                                  {t.abbreviation}
                                </span>
                              </div>
                            </TableCell>
                            <TableCell className="text-center font-stat text-sm">{t.wins}</TableCell>
                            <TableCell className="text-center font-stat text-sm">{t.losses}</TableCell>
                            <TableCell className="text-center font-stat text-xs text-[var(--text-secondary)]">
                              {t.win_pct?.toFixed(3) ?? '.000'}
                            </TableCell>
                            <TableCell className="text-center font-stat text-xs text-[var(--text-secondary)]">{t.points_for}</TableCell>
                            <TableCell className="text-center font-stat text-xs text-[var(--text-secondary)]">{t.points_against}</TableCell>
                            <TableCell className="text-center">
                              <span className={`font-stat text-xs ${(t.point_diff ?? 0) > 0 ? 'text-green-400' : (t.point_diff ?? 0) < 0 ? 'text-red-400' : 'text-[var(--text-muted)]'}`}>
                                {(t.point_diff ?? 0) > 0 ? '+' : ''}{t.point_diff ?? 0}
                              </span>
                            </TableCell>
                            <TableCell className="pr-4 text-center text-xs text-[var(--text-muted)]">{t.streak || '--'}</TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </CardContent>
              </Card>
            </motion.div>
          ))
        )}
      </div>
    </div>
  );
}
