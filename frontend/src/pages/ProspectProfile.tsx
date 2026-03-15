import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useRoster } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { motion } from 'framer-motion';
import { GraduationCap, Search, Check, AlertTriangle, ChevronLeft, Star } from 'lucide-react';
import { toast } from 'sonner';

function ratingColor(r: number) {
  if (r >= 75) return 'text-green-400';
  if (r >= 68) return 'text-blue-400';
  if (r >= 60) return 'text-yellow-400';
  return 'text-red-400';
}

const trendLabels: Record<string, { text: string; color: string; icon: string }> = {
  rising: { text: 'Rising Fast', color: 'text-green-400', icon: '↑↑' },
  up: { text: 'Trending Up', color: 'text-green-400', icon: '↑' },
  steady: { text: 'Steady', color: 'text-[var(--text-muted)]', icon: '—' },
  down: { text: 'Trending Down', color: 'text-red-400', icon: '↓' },
  falling: { text: 'Falling Fast', color: 'text-red-400', icon: '↓↓' },
};

interface ProspectData {
  id: number;
  first_name: string;
  last_name: string;
  position: string;
  college: string;
  age: number;
  projected_round: number;
  stock_rating: number;
  stock_trend: string;
  is_drafted: boolean;
  scout_level: number;
  is_scouted: boolean;
  is_favorited: boolean;
  draft_board_rank: number | null;
  injury_flag: string | null;
  character_flag: string | null;
  buzz: string | null;
  // Scouted fields (revealed progressively)
  scouted_overall?: number;
  scouted_floor?: number;
  scouted_ceiling?: number;
  potential?: string;
  tier?: string;
  strengths?: string[];
  weaknesses?: string[];
  attribute_grades?: Record<string, string>;
  // Always available
  game_log: { week: number; performance: string; narrative: string; stock_change: number; stock_after: number }[];
  season_highlights: { week: number; type: string; narrative: string }[];
}

export default function ProspectProfile() {
  const { id } = useParams<{ id: string }>();
  const prospectId = id ? Number(id) : 0;
  const navigate = useNavigate();
  const qc = useQueryClient();
  const team = useAuthStore((s) => s.team);

  const { data: prospect, isLoading } = useQuery<ProspectData>({
    queryKey: ['prospect', prospectId],
    queryFn: () => api.get(`/draft/prospect/${prospectId}`),
    enabled: !!prospectId,
  });

  const { data: roster } = useRoster(team?.id);

  const scoutMut = useMutation({
    mutationFn: (pid: number) => api.post<{ message: string; report: { scout_level: number; scouts_remaining: number } }>(`/draft/scout/${pid}`),
    onSuccess: (data) => {
      const report = data.report ?? data;
      const remaining = (report as any)?.scouts_remaining ?? 0;
      const level = (report as any)?.scout_level ?? 1;
      const labels = ['', 'Level 1 — Overview', 'Level 2 — Deep Dive', 'Level 3 — Full Report'];
      toast.success(`${labels[level] ?? 'Scouted'}! ${remaining} scouting points remaining this week.`);
      qc.invalidateQueries({ queryKey: ['prospect', prospectId] });
      qc.invalidateQueries({ queryKey: ['draftBoard'] });
      qc.invalidateQueries({ queryKey: ['scoutingBudget'] });
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Scouting failed'),
  });

  const favMut = useMutation({
    mutationFn: (pid: number) => api.post<{ favorited: boolean }>(`/draft/prospect/${pid}/favorite`),
    onSuccess: (data) => {
      toast.success(data.favorited ? 'Added to your draft board' : 'Removed from draft board');
      qc.invalidateQueries({ queryKey: ['prospect', prospectId] });
    },
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <GraduationCap className="h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
      </div>
    );
  }

  if (!prospect) {
    return <p className="text-[var(--text-secondary)] text-center py-20">Prospect not found.</p>;
  }

  const trend = trendLabels[prospect.stock_trend] ?? trendLabels.steady;
  const starter = roster?.active?.filter((p) => p.position === prospect.position)
    .sort((a, b) => b.overall_rating - a.overall_rating)[0];

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      {/* Back button */}
      <Button variant="ghost" size="sm" onClick={() => navigate('/draft')} className="gap-1 text-[var(--text-muted)]">
        <ChevronLeft className="h-4 w-4" /> Back to Draft Board
      </Button>

      {/* Header */}
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        className="rounded-xl bg-[var(--bg-surface)] border border-[var(--border)] overflow-hidden"
      >
        <div className="h-1.5 w-full bg-gradient-to-r from-[var(--accent-blue)] to-transparent" />
        <div className="p-6">
          <div className="flex items-start justify-between">
            <div>
              <div className="flex items-center gap-3">
                <GraduationCap className="h-6 w-6 text-[var(--accent-blue)]" />
                <h1 className="font-display text-3xl tracking-tight">{prospect.first_name} {prospect.last_name}</h1>
                {prospect.is_scouted && prospect.tier && (
                  <Badge variant="outline" className={`text-[10px] ${
                    prospect.tier === 'Generational' ? 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25' :
                    prospect.tier === 'Blue Chip' ? 'bg-blue-500/15 text-blue-400 border-blue-500/25' :
                    'border-[var(--border)]'
                  }`}>
                    {prospect.tier}
                  </Badge>
                )}
              </div>
              <p className="text-sm text-[var(--text-secondary)] mt-1">
                {prospect.position} &middot; {prospect.college} &middot; Age {prospect.age}
              </p>
              {prospect.injury_flag && (
                <Badge variant="outline" className="mt-2 text-[10px] bg-red-500/10 text-red-400 border-red-500/30">
                  Injury: {prospect.injury_flag}
                </Badge>
              )}
              {prospect.character_flag && (
                <Badge variant="outline" className="mt-2 ml-2 text-[10px] bg-orange-500/10 text-orange-400 border-orange-500/30">
                  Character: {prospect.character_flag}
                </Badge>
              )}
            </div>
            <div className="text-right space-y-2">
              <div className="flex items-center gap-3 justify-end">
                <Button
                  size="sm"
                  variant={prospect.is_favorited ? 'default' : 'outline'}
                  className={`gap-1 ${prospect.is_favorited ? 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30' : ''}`}
                  onClick={() => favMut.mutate(prospect.id)}
                >
                  <Star className={`h-3.5 w-3.5 ${prospect.is_favorited ? 'fill-yellow-400' : ''}`} />
                  {prospect.is_favorited ? 'On Board' : 'Add to Board'}
                </Button>
              </div>
              <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">Projected</p>
                <p className="font-display text-2xl">Round {prospect.projected_round}</p>
              </div>
              <div className="flex items-center gap-2 justify-end">
                <span className={`text-lg font-bold ${trend.color}`}>{trend.icon}</span>
                <span className={`text-sm ${trend.color}`}>{trend.text}</span>
              </div>
            </div>
          </div>
        </div>
      </motion.div>

      {/* Scout button + comparison */}
      <div className="grid gap-4 md:grid-cols-2">
        {/* Scout action */}
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="p-5">
            {/* Scout level progression */}
            <div className="space-y-3">
              {/* Level indicator */}
              <div className="flex items-center justify-between">
                <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">Scout Level</p>
                <div className="flex gap-1">
                  {[1, 2, 3].map((lvl) => (
                    <div
                      key={lvl}
                      className={`h-2 w-8 rounded-full ${
                        prospect.scout_level >= lvl ? 'bg-[var(--accent-blue)]' : 'bg-[var(--bg-elevated)]'
                      }`}
                    />
                  ))}
                </div>
              </div>

              {prospect.scout_level >= 1 && (
                <>
                  <div className="grid grid-cols-3 gap-3 text-center">
                    <div>
                      <p className="text-[10px] text-[var(--text-muted)]">Floor</p>
                      <p className={`font-display text-xl ${ratingColor(prospect.scouted_floor ?? 0)}`}>{prospect.scouted_floor}</p>
                    </div>
                    <div>
                      <p className="text-[10px] text-[var(--text-muted)]">Projected</p>
                      <p className={`font-display text-xl ${ratingColor(prospect.scouted_overall ?? 0)}`}>{prospect.scouted_overall}</p>
                    </div>
                    <div>
                      <p className="text-[10px] text-[var(--text-muted)]">Ceiling</p>
                      <p className={`font-display text-xl ${ratingColor(prospect.scouted_ceiling ?? 0)}`}>{prospect.scouted_ceiling}</p>
                    </div>
                  </div>
                  {prospect.potential && (
                    <p className="text-xs text-center text-[var(--text-secondary)]">
                      Development: <span className="font-semibold capitalize">{prospect.potential}</span>
                    </p>
                  )}
                </>
              )}

              {prospect.scout_level >= 3 && prospect.tier && (
                <div className="text-center">
                  <Badge variant="outline" className={`text-xs ${
                    prospect.tier === 'Generational' ? 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25' :
                    prospect.tier === 'Blue Chip' ? 'bg-blue-500/15 text-blue-400 border-blue-500/25' :
                    'border-[var(--border)]'
                  }`}>
                    {prospect.tier}
                  </Badge>
                </div>
              )}

              {prospect.scout_level === 0 && (
                <div className="text-center py-2">
                  <Search className="h-6 w-6 text-[var(--text-muted)] mx-auto mb-2" />
                  <p className="text-sm text-[var(--text-secondary)]">
                    Not yet scouted. Use a scouting point to reveal their OVR range and development.
                  </p>
                </div>
              )}

              {prospect.scout_level < 3 && (
                <Button
                  className="w-full gap-1.5"
                  variant={prospect.scout_level === 0 ? 'default' : 'outline'}
                  onClick={() => scoutMut.mutate(prospect.id)}
                  disabled={scoutMut.isPending}
                >
                  <Search className="h-4 w-4" />
                  {scoutMut.isPending ? 'Scouting...' :
                    prospect.scout_level === 0 ? 'Scout — Level 1 (Overview)' :
                    prospect.scout_level === 1 ? 'Scout — Level 2 (Deep Dive)' :
                    'Scout — Level 3 (Full Report)'}
                </Button>
              )}

              {prospect.scout_level >= 3 && (
                <p className="text-xs text-center text-green-400">
                  <Check className="h-3 w-3 inline mr-1" />Fully Scouted
                </p>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Your starter comparison */}
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardHeader className="pb-2">
            <CardTitle className="text-xs font-display uppercase tracking-[0.15em] text-[var(--text-muted)]">
              Your {prospect.position} Starter
            </CardTitle>
          </CardHeader>
          <CardContent className="pt-0">
            {starter ? (
              <div className="flex items-center gap-4">
                <div>
                  <p className="font-medium">{starter.first_name} {starter.last_name}</p>
                  <p className="text-xs text-[var(--text-secondary)]">Age {starter.age}</p>
                </div>
                <div className="ml-auto text-right">
                  <p className={`font-display text-2xl ${ratingColor(starter.overall_rating)}`}>{starter.overall_rating}</p>
                  <p className="text-[10px] text-[var(--text-muted)]">OVR</p>
                </div>
              </div>
            ) : (
              <p className="text-sm text-[var(--text-muted)]">No starter at {prospect.position}</p>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Scouted strengths/weaknesses */}
      {prospect.is_scouted && (prospect.strengths?.length || prospect.weaknesses?.length) ? (
        <div className="grid gap-4 md:grid-cols-2">
          {prospect.strengths && prospect.strengths.length > 0 && (
            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardHeader className="pb-2">
                <CardTitle className="text-xs font-display uppercase tracking-[0.15em] text-green-400">Strengths</CardTitle>
              </CardHeader>
              <CardContent className="pt-0 space-y-1.5">
                {prospect.strengths.map((s, i) => (
                  <div key={i} className="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                    <Check className="h-3 w-3 text-green-400 shrink-0" /> {s}
                  </div>
                ))}
              </CardContent>
            </Card>
          )}
          {prospect.weaknesses && prospect.weaknesses.length > 0 && (
            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardHeader className="pb-2">
                <CardTitle className="text-xs font-display uppercase tracking-[0.15em] text-red-400">Weaknesses</CardTitle>
              </CardHeader>
              <CardContent className="pt-0 space-y-1.5">
                {prospect.weaknesses.map((w, i) => (
                  <div key={i} className="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                    <AlertTriangle className="h-3 w-3 text-red-400 shrink-0" /> {w}
                  </div>
                ))}
              </CardContent>
            </Card>
          )}
        </div>
      ) : null}

      {/* Attribute grades (revealed progressively) */}
      {prospect.attribute_grades && Object.keys(prospect.attribute_grades).length > 0 && (
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardHeader className="pb-2">
            <CardTitle className="text-xs font-display uppercase tracking-[0.15em] text-[var(--text-muted)]">
              Attribute Grades
              <span className="ml-2 text-[var(--text-secondary)] normal-case font-normal">
                ({Object.keys(prospect.attribute_grades).length} revealed)
              </span>
            </CardTitle>
          </CardHeader>
          <CardContent className="pt-0">
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              {Object.entries(prospect.attribute_grades).map(([attr, grade]) => {
                const gradeColor: Record<string, string> = {
                  A: 'text-green-400 bg-green-500/10 border-green-500/20',
                  B: 'text-blue-400 bg-blue-500/10 border-blue-500/20',
                  C: 'text-yellow-400 bg-yellow-500/10 border-yellow-500/20',
                  D: 'text-orange-400 bg-orange-500/10 border-orange-500/20',
                  F: 'text-red-400 bg-red-500/10 border-red-500/20',
                };
                return (
                  <div key={attr} className={`text-center rounded-lg border p-3 ${gradeColor[grade as string] ?? 'border-[var(--border)]'}`}>
                    <p className="text-[10px] text-[var(--text-muted)] uppercase">{(attr as string).replace(/_/g, ' ')}</p>
                    <p className="font-display text-2xl">{grade}</p>
                  </div>
                );
              })}
            </div>
            {prospect.scout_level < 3 && (
              <p className="text-[10px] text-[var(--text-muted)] text-center mt-2">
                Scout to Level {prospect.scout_level + 1} to reveal more attributes
              </p>
            )}
          </CardContent>
        </Card>
      )}

      {/* Buzz */}
      {prospect.buzz && (
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="p-4">
            <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-gold)] mb-1">Latest Buzz</p>
            <p className="text-sm text-[var(--text-secondary)] italic">&ldquo;{prospect.buzz}&rdquo;</p>
          </CardContent>
        </Card>
      )}

      {/* College Game Log */}
      {prospect.game_log && prospect.game_log.length > 0 && (
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardHeader>
            <CardTitle className="text-xs font-display uppercase tracking-[0.15em] text-[var(--text-muted)]">
              College Season Game Log
            </CardTitle>
          </CardHeader>
          <CardContent className="pt-0 space-y-2">
            {[...prospect.game_log].reverse().map((entry, i) => {
              const perfColors: Record<string, string> = {
                elite: 'border-l-green-400', good: 'border-l-blue-400',
                average: 'border-l-gray-500', bad: 'border-l-orange-400', terrible: 'border-l-red-400',
              };
              const stockColor = entry.stock_change > 0 ? 'text-green-400' : entry.stock_change < 0 ? 'text-red-400' : 'text-[var(--text-muted)]';
              return (
                <div key={i} className={`border-l-2 ${perfColors[entry.performance] ?? 'border-l-gray-500'} pl-3 py-1`}>
                  <div className="flex items-center justify-between">
                    <p className="text-[10px] font-bold text-[var(--text-muted)]">Week {entry.week}</p>
                    <span className={`text-xs font-mono ${stockColor}`}>
                      {entry.stock_change > 0 ? '+' : ''}{entry.stock_change}
                    </span>
                  </div>
                  <p className="text-sm text-[var(--text-secondary)]">{entry.narrative}</p>
                </div>
              );
            })}
          </CardContent>
        </Card>
      )}

      {prospect.game_log.length === 0 && (
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="py-8 text-center">
            <p className="text-sm text-[var(--text-muted)]">No college game data yet. Game log updates each week.</p>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
