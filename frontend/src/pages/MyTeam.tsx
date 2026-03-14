import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useRoster, useDepthChart } from '@/hooks/useApi';
import { useNavigate } from 'react-router-dom';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TeamBadge } from '@/components/TeamBadge';
import { FindTradeModal } from '@/components/FindTradeModal';
import { motion } from 'framer-motion';

const positionGroups = {
  Offense: ['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C'],
  Defense: ['DE', 'DT', 'LB', 'CB', 'S'],
  Special: ['K', 'P', 'LS'],
};

function ratingColor(r: number) {
  if (r >= 85) return 'text-green-400';
  if (r >= 75) return 'text-blue-400';
  if (r >= 65) return 'text-yellow-400';
  return 'text-red-400';
}

export default function MyTeam() {
  const team = useAuthStore((s) => s.team);
  const { data: roster, isLoading } = useRoster(team?.id);
  const { data: depthChart } = useDepthChart(team?.id);
  const navigate = useNavigate();
  const [posFilter, setPosFilter] = useState('all');
  const [tradePlayerId, setTradePlayerId] = useState<number | null>(null);
  const [tradePlayerName, setTradePlayerName] = useState('');
  const [tradeModalOpen, setTradeModalOpen] = useState(false);

  if (isLoading) return <p className="text-[var(--text-secondary)]">Loading roster...</p>;

  const players = roster?.active ?? [];
  const filtered = posFilter === 'all' ? players : players.filter((p) => p.position === posFilter);

  const positions = [...new Set(players.map((p) => p.position))].sort();

  return (
    <div className="space-y-6">
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
        className="flex items-center justify-between"
      >
        <div className="flex items-center gap-4">
          <TeamBadge
            abbreviation={team?.abbreviation}
            primaryColor={team?.primary_color}
            secondaryColor={team?.secondary_color}
            size="lg"
          />
          <div>
            <h1 className="font-display text-2xl tracking-tight">{team?.city} {team?.name}</h1>
            <p className="text-sm text-[var(--text-secondary)]">Roster & Depth Chart</p>
          </div>
        </div>
        <div className="flex items-center gap-5">
          <TeamMorale morale={team?.morale ?? 70} rating={team?.overall_rating ?? 75} teamColor={team?.primary_color} />
        </div>
      </motion.div>

      <Tabs defaultValue="roster">
        <TabsList className="bg-[var(--bg-elevated)]">
          <TabsTrigger value="roster" className="text-xs font-semibold uppercase tracking-wider">Roster</TabsTrigger>
          <TabsTrigger value="depth" className="text-xs font-semibold uppercase tracking-wider">Depth Chart</TabsTrigger>
        </TabsList>

        <TabsContent value="roster" className="mt-4">
          <div className="mb-3 flex items-center gap-3">
            <Select value={posFilter} onValueChange={(v) => v && setPosFilter(v)}>
              <SelectTrigger className="w-32 border-[var(--border)] bg-[var(--bg-elevated)]">
                <SelectValue placeholder="Position" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All</SelectItem>
                {positions.map((p) => (
                  <SelectItem key={p} value={p}>{p}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <span className="text-xs font-medium text-[var(--text-muted)]">{filtered.length} players</span>
          </div>

          <Card className="overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
            {/* Accent bar */}
            <div className="h-[2px] w-full" style={{ background: `linear-gradient(90deg, ${team?.primary_color ?? '#2188FF'}, transparent)` }} />
            <Table>
              <TableHeader>
                <TableRow className="border-[var(--border)] hover:bg-transparent">
                  <TableHead className="w-12 text-[10px] font-bold uppercase tracking-[0.15em]">#</TableHead>
                  <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Name</TableHead>
                  <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Pos</TableHead>
                  <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Age</TableHead>
                  <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">OVR</TableHead>
                  <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Potential</TableHead>
                  <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Status</TableHead>
                  <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em] w-20">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filtered
                  .sort((a, b) => b.overall_rating - a.overall_rating)
                  .map((p) => (
                    <TableRow
                      key={p.id}
                      className="cursor-pointer border-[var(--border)] transition-colors hover:bg-[var(--bg-elevated)]"
                      onClick={() => navigate(`/player/${p.id}`)}
                    >
                      <TableCell className="font-stat text-xs text-[var(--text-muted)]">{p.jersey_number}</TableCell>
                      <TableCell className="text-[13px] font-medium">{p.first_name} {p.last_name}</TableCell>
                      <TableCell>
                        <Badge variant="outline" className="text-[10px] font-semibold border-[var(--border)]">{p.position}</Badge>
                      </TableCell>
                      <TableCell className="text-sm text-[var(--text-secondary)]">{p.age}</TableCell>
                      <TableCell className={`font-stat text-sm ${ratingColor(p.overall_rating)}`}>
                        {p.overall_rating}
                      </TableCell>
                      <TableCell>
                        <DevelopmentBadge potential={p.potential} />
                      </TableCell>
                      <TableCell>
                        {p.injury ? (
                          <Badge variant="destructive" className="text-[10px] font-semibold">
                            {p.injury.severity} ({p.injury.weeks_remaining}w)
                          </Badge>
                        ) : (
                          <Badge variant="secondary" className="text-[10px] font-semibold bg-green-500/10 text-green-400 border-green-500/20">Active</Badge>
                        )}
                      </TableCell>
                      <TableCell>
                        <Button
                          size="xs"
                          variant="ghost"
                          className="text-[10px]"
                          onClick={(e) => {
                            e.stopPropagation();
                            setTradePlayerId(p.id);
                            setTradePlayerName(`${p.first_name} ${p.last_name}`);
                            setTradeModalOpen(true);
                          }}
                        >
                          Trade
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
              </TableBody>
            </Table>
          </Card>
        </TabsContent>

        <TabsContent value="depth" className="mt-4">
          <div className="space-y-5">
            {Object.entries(positionGroups).map(([group, positions]) => (
              <Card key={group} className="overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
                <CardHeader className="border-b border-[var(--border)] bg-[var(--bg-elevated)]/50 py-2.5 px-4">
                  <CardTitle className="font-display text-xs uppercase tracking-[0.15em]">{group}</CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  <Table>
                    <TableHeader>
                      <TableRow className="border-[var(--border)] hover:bg-transparent">
                        <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Pos</TableHead>
                        <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Starter</TableHead>
                        <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">Backup</TableHead>
                        <TableHead className="text-[10px] font-bold uppercase tracking-[0.15em]">3rd String</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {positions.map((pos) => {
                        const entries = depthChart?.[pos] ?? [];
                        return (
                          <TableRow key={pos} className="border-[var(--border)]">
                            <TableCell className="font-display text-xs uppercase text-[var(--text-muted)]">{pos}</TableCell>
                            {[0, 1, 2].map((slot) => {
                              const e = entries[slot];
                              return (
                                <TableCell key={slot} className="text-sm">
                                  {e ? (
                                    <span
                                      className="cursor-pointer transition-colors hover:text-[var(--accent-blue)]"
                                      onClick={() => navigate(`/player/${e.player_id}`)}
                                    >
                                      {e.name}{' '}
                                      <span className={`font-stat text-xs ${ratingColor(e.overall_rating)}`}>
                                        {e.overall_rating}
                                      </span>
                                    </span>
                                  ) : (
                                    <span className="text-[var(--text-muted)]">--</span>
                                  )}
                                </TableCell>
                              );
                            })}
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>
      </Tabs>

      <FindTradeModal
        playerId={tradePlayerId}
        playerName={tradePlayerName}
        open={tradeModalOpen}
        onOpenChange={setTradeModalOpen}
      />
    </div>
  );
}

function DevelopmentBadge({ potential }: { potential: string }) {
  const colors: Record<string, string> = {
    elite: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25',
    high: 'bg-blue-500/15 text-blue-400 border-blue-500/25',
    average: 'bg-gray-500/15 text-gray-400 border-gray-500/25',
    limited: 'bg-red-500/15 text-red-400 border-red-500/25',
    // Legacy values
    superstar: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25',
    star: 'bg-blue-500/15 text-blue-400 border-blue-500/25',
    normal: 'bg-gray-500/15 text-gray-400 border-gray-500/25',
    slow: 'bg-red-500/15 text-red-400 border-red-500/25',
  };
  const labels: Record<string, string> = {
    elite: 'Elite', high: 'High', average: 'Average', limited: 'Limited',
    superstar: 'Elite', star: 'High', normal: 'Average', slow: 'Limited',
  };
  return (
    <Badge variant="outline" className={`text-[10px] font-semibold ${colors[potential] ?? ''}`}>
      {labels[potential] ?? potential}
    </Badge>
  );
}

function TeamMorale({ morale, rating, teamColor }: { morale: number; rating: number; teamColor?: string }) {
  return (
    <div className="flex gap-5">
      <div className="text-center">
        <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">Rating</p>
        <p className={`font-stat text-2xl leading-none ${ratingColor(rating)}`}>{rating}</p>
      </div>
      <div className="w-28">
        <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">Morale</p>
        <Progress value={morale} className="mt-1.5 h-1.5" />
        <p className="mt-0.5 text-right font-stat text-[10px]" style={{ color: teamColor }}>{morale}%</p>
      </div>
    </div>
  );
}
